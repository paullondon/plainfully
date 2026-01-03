<?php declare(strict_types=1);

namespace App\Features\Checks;

/**
 * ============================================================
 * Plainfully — CheckEngine
 * ============================================================
 * Purpose:
 *   - Calls AiClient to analyse a user message
 *   - Ensures a matching users row exists (by users.email)
 *   - Inserts a row into checks using the LIVE schema
 *   - Returns CheckResult (v1 signature)
 *
 * IMPORTANT PRODUCT DECISION (Jan 2026):
 *   - Scamcheck mode is REMOVED from this engine.
 *   - Everything funnels through "clarify" behaviour.
 *
 * Security / Safety:
 *   - Prepared statements only
 *   - No dynamic SQL
 *   - Fail-open on AI/DB errors so the user journey continues
 * ============================================================
 */
final class CheckEngine
{
    private \PDO $pdo;
    private AiClient $ai;

    public function __construct(\PDO $pdo, AiClient $ai)
    {
        $this->pdo = $pdo;
        $this->ai  = $ai;
    }

    /**
     * Run analysis + persist to DB (best-effort).
     */
    public function run(CheckInput $input, bool $isPaid): CheckResult
    {
        // ============================================================
        // 1) Mode selection (scamcheck removed)
        // ============================================================
        $mode = $this->modeFromChannel((string)$input->channel);

        // ============================================================
        // 2) Call AI (fail-open)
        // ============================================================
        $analysis = [];
        try {
            $analysis = $this->ai->analyze(
                (string)$input->content,
                $mode,
                [
                    'is_paid' => $isPaid,
                    'channel' => (string)$input->channel,
                ]
            );
        } catch (\Throwable $e) {
            error_log('AiClient analyze failed (fail-open): ' . $e->getMessage());
            $analysis = [];
        }

        if (!is_array($analysis)) {
            $analysis = [];
        }

        // ============================================================
        // 3) Normalise fields (safe defaults)
        // ============================================================
        $status            = (string)($analysis['status'] ?? 'ok');
        $headline          = (string)($analysis['headline'] ?? 'Your result is ready');

        // We keep these fields because CheckResult expects them.
        $externalRiskLine  = (string)($analysis['external_risk_line'] ?? 'Safety check: completed');
        $externalTopicLine = (string)($analysis['external_topic_line'] ?? 'Summary: ready');

        // Risk level stays supported (defaults to low)
        $riskRaw       = $analysis['scam_risk_level'] ?? ($analysis['scamRiskLevel'] ?? null);
        $scamRiskLevel = $this->normaliseRiskLevel($riskRaw, $analysis['is_scam'] ?? null);

        // Web fields (optional, shown on website page)
        $webWhatTheMessageSays = (string)($analysis['web_what_the_message_says'] ?? '');
        $webWhatItsAskingFor   = (string)($analysis['web_what_its_asking_for'] ?? '');
        $webScamLevelLine      = (string)($analysis['web_scam_level_line'] ?? '');
        $webLowRiskNote        = (string)($analysis['web_low_risk_note'] ?? '');
        $webScamExplanation    = (string)($analysis['web_scam_explanation'] ?? '');

        // Stored is_scam flag in DB:
        $isScam = ($scamRiskLevel === 'high');

        // Always store valid JSON
        $rawJson = $this->safeJsonEncode($analysis);

        // ============================================================
        // 4) Persist (fail-open)
        // ============================================================
        $id = null;

        try {
            $userId = $this->getOrCreateUserIdByEmail((string)$input->sourceIdentifier);

            $shortSummary = trim($headline . ' — ' . $externalTopicLine);
            if ($shortSummary === '') {
                $shortSummary = 'Plainfully result';
            }
            $shortSummary = $this->mbTrimTo($shortSummary, 240);

            $stmt = $this->pdo->prepare('
                INSERT INTO checks
                    (user_id, channel, source_identifier, content_type, is_scam, is_paid, short_summary, ai_result_json, created_at)
                VALUES
                    (:user_id, :channel, :source_identifier, :content_type, :is_scam, :is_paid, :short_summary, :ai_result_json, NOW())
            ');

            $stmt->execute([
                ':user_id'           => $userId,
                ':channel'           => (string)$input->channel,
                ':source_identifier' => (string)$input->sourceIdentifier,
                ':content_type'      => (string)$input->contentType,
                ':is_scam'           => $isScam ? 1 : 0,
                ':is_paid'           => $isPaid ? 1 : 0,
                ':short_summary'     => $shortSummary,
                ':ai_result_json'    => $rawJson,
            ]);

            $id = (int)$this->pdo->lastInsertId();
        } catch (\Throwable $e) {
            error_log('CheckEngine DB insert failed (fail-open): ' . $e->getMessage());
        }

        // ============================================================
        // 5) Return result (v1 constructor compatibility)
        // ============================================================
        return new CheckResult(
            $id,
            $status,
            $headline,
            $scamRiskLevel,
            $externalRiskLine,
            $externalTopicLine,
            $webWhatTheMessageSays,
            $webWhatItsAskingFor,
            $webScamLevelLine,
            $webLowRiskNote,
            $webScamExplanation,
            $isPaid,
            ['mode' => $mode->value],
            $rawJson
        );
    }

    // ============================================================
    // Mode selection
    // ============================================================
    private function modeFromChannel(string $channel): AiMode
    {
        // Scamcheck removed. Everything is clarify unless you later add other modes.
        if ($channel === 'email-clarify') {
            return AiMode::Clarify;
        }

        // Safe fallback
        return AiMode::Generic;
    }

    // ============================================================
    // Risk handling (kept for schema stability)
    // ============================================================
    private function normaliseRiskLevel(mixed $risk, mixed $isScamFallback): string
    {
        $r = strtolower(trim((string)($risk ?? '')));
        if (in_array($r, ['low', 'medium', 'high'], true)) {
            return $r;
        }

        if (is_bool($isScamFallback)) {
            return $isScamFallback ? 'high' : 'low';
        }

        $s = strtolower(trim((string)($isScamFallback ?? '')));
        if ($s === '1' || $s === 'true' || $s === 'yes') { return 'high'; }
        if ($s === '0' || $s === 'false' || $s === 'no') { return 'low'; }

        return 'low';
    }

    // ============================================================
    // JSON safety
    // ============================================================
    private function safeJsonEncode(array $data): string
    {
        try {
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json) && $json !== '' && json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return '{}';
    }

    // ============================================================
    // User lookup / creation
    // ============================================================
    private function getOrCreateUserIdByEmail(string $email): int
    {
        $email = trim(strtolower($email));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Invalid user email for user lookup.');
        }

        // 1) Select
        $sel = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $sel->execute([':email' => $email]);
        $found = $sel->fetchColumn();
        if ($found !== false && $found !== null) {
            return (int)$found;
        }

        // 2) Insert (race-safe)
        try {
            $ins = $this->pdo->prepare('
                INSERT INTO users (email, plan, created_at)
                VALUES (:email, :plan, NOW())
            ');
            $ins->execute([
                ':email' => $email,
                ':plan'  => 'free',
            ]);
            return (int)$this->pdo->lastInsertId();
        } catch (\Throwable $e) {
            // Another process likely inserted; re-select
            $sel2 = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $sel2->execute([':email' => $email]);
            $found2 = $sel2->fetchColumn();
            if ($found2 !== false && $found2 !== null) {
                return (int)$found2;
            }
            throw $e;
        }
    }

    // ============================================================
    // Helpers
    // ============================================================
    private function mbTrimTo(string $s, int $maxChars): string
    {
        $s = trim($s);
        if ($s === '') { return $s; }

        $len = mb_strlen($s, 'UTF-8');
        if ($len <= $maxChars) { return $s; }

        return rtrim(mb_substr($s, 0, $maxChars, 'UTF-8'));
    }
}
