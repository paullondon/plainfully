<?php declare(strict_types=1);

namespace App\Features\Checks;

use PDO;
use Throwable;

/**
 * CheckEngine (Plainfully)
 *
 * - Calls AiClient
 * - Ensures a matching `users` row exists (by users.email) and gets user_id
 * - Inserts into `checks` using the ACTUAL live schema:
 *     ai_result_json, channel, content_type, created_at, id, is_paid, is_scam,
 *     short_summary, source_identifier, updated_at, user_id
 * - Returns CheckResult (matches CheckResult v1 signature in your repo)
 *
 * Security:
 * - Prepared statements only
 * - No dynamic SQL
 * - Fail-open on DB insert so UX still works (but logs)
 */
final class CheckEngine
{
    private PDO $pdo;
    private AiClient $ai;

    public function __construct(PDO $pdo, AiClient $ai)
    {
        $this->pdo = $pdo;
        $this->ai  = $ai;
    }

    public function run(CheckInput $input, bool $isPaid): CheckResult
    {
        // Determine analysis mode by channel
        $mode = 'generic';
        if ($input->channel === 'email-scamcheck') {
            $mode = 'scamcheck';
        } elseif ($input->channel === 'email-clarify') {
            $mode = 'clarify';
        }

        // AiClient returns an array (DummyAiClient should too)
        $analysis = $this->ai->analyze($input->content, $mode);
        if (!is_array($analysis)) { $analysis = []; }

        // ---------
        // Normalise analysis fields (safe defaults; no jargon)
        // ---------
        $status             = (string)($analysis['status'] ?? 'ok');
        $headline           = (string)($analysis['headline'] ?? 'Your result is ready');
        $externalRiskLine   = (string)($analysis['external_risk_line'] ?? 'Scam risk level: unknown (we always check)');
        $externalTopicLine  = (string)($analysis['external_topic_line'] ?? 'This message appears to be about: unknown');

        $scamRiskLevelRaw = $analysis['scam_risk_level'] ?? ($analysis['scamRiskLevel'] ?? null);
        $scamRiskLevel    = $this->normaliseRiskLevel($scamRiskLevelRaw, $analysis['is_scam'] ?? null);

        // Web fields (these are for the website view, not the thin email)
        $webWhatTheMessageSays = (string)($analysis['web_what_the_message_says'] ?? '');
        $webWhatItsAskingFor   = (string)($analysis['web_what_its_asking_for'] ?? '');
        $webScamLevelLine      = (string)($analysis['web_scam_level_line'] ?? '');
        $webLowRiskNote        = (string)($analysis['web_low_risk_note'] ?? '');
        $webScamExplanation    = (string)($analysis['web_scam_explanation'] ?? '');

        // Boolean is_scam stored in DB (conservative: only "high" = scam)
        $isScam = ($scamRiskLevel === 'high');

        // Build JSON for DB (must be valid JSON to satisfy your constraint)
        $rawJson = $this->safeJsonEncode($analysis);

        // Store (best effort). If DB write fails, still return result so UX works.
        $id = null;

        try {
            $userId = $this->getOrCreateUserIdByEmail($input->sourceIdentifier);

            // short_summary must fit varchar; keep it tight.
            $shortSummary = trim($headline . ' — ' . $externalTopicLine);
            if ($shortSummary === '') { $shortSummary = 'Plainfully result'; }
            $shortSummary = $this->mbTrimTo($shortSummary, 240);

            $stmt = $this->pdo->prepare('
                INSERT INTO checks
                    (user_id, channel, source_identifier, content_type, is_scam, is_paid, short_summary, ai_result_json, created_at)
                VALUES
                    (:user_id, :channel, :source_identifier, :content_type, :is_scam, :is_paid, :short_summary, :ai_result_json, NOW())
            ');

            $stmt->execute([
                ':user_id'           => $userId,
                ':channel'           => $input->channel,
                ':source_identifier' => $input->sourceIdentifier,
                ':content_type'      => $input->contentType,
                ':is_scam'           => $isScam ? 1 : 0,
                ':is_paid'           => $isPaid ? 1 : 0,
                ':short_summary'     => $shortSummary,
                ':ai_result_json'    => $rawJson,
            ]);

            $id = (int)$this->pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('CheckEngine DB insert failed (fail-open): ' . $e->getMessage());
        }

        // IMPORTANT: This matches the constructor in /app/features/checks/check_result.php (v1)
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
            ['mode' => $mode],
            $rawJson
        );
    }

    /**
     * Normalise risk level into 'low'|'medium'|'high'.
     * If not provided, fall back to is_scam boolean if present.
     */
    private function normaliseRiskLevel(mixed $risk, mixed $isScamFallback): string
    {
        $r = strtolower(trim((string)($risk ?? '')));
        if (in_array($r, ['low', 'medium', 'high'], true)) {
            return $r;
        }

        // Fallback: if upstream only provides is_scam as boolish
        if (is_bool($isScamFallback)) {
            return $isScamFallback ? 'high' : 'low';
        }

        $s = strtolower(trim((string)($isScamFallback ?? '')));
        if ($s === '1' || $s === 'true' || $s === 'yes') { return 'high'; }
        if ($s === '0' || $s === 'false' || $s === 'no') { return 'low'; }

        return 'low';
    }

    /**
     * Always returns valid JSON.
     */
    private function safeJsonEncode(array $data): string
    {
        try {
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json) && $json !== '' && json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        } catch (Throwable $e) {
            // ignore
        }
        return '{}';
    }

    /**
     * Ensures a user exists for the given email address and returns users.id.
     *
     * - Uses users.email (UNIQUE) as the natural key.
     * - Inserts plan='free' for new users.
     * - Race-safe: if two processes insert at once, handle duplicate then re-select.
     */
    private function getOrCreateUserIdByEmail(string $email): int
    {
        $email = trim(strtolower($email));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Invalid user email for user lookup.');
        }

        // 1) Try select
        $sel = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $sel->execute([':email' => $email]);
        $found = $sel->fetchColumn();
        if ($found !== false && $found !== null) {
            return (int)$found;
        }

        // 2) Insert
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
        } catch (Throwable $e) {
            // Duplicate insert or transient issue; re-select
            $sel2 = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $sel2->execute([':email' => $email]);
            $found2 = $sel2->fetchColumn();
            if ($found2 !== false && $found2 !== null) {
                return (int)$found2;
            }
            throw $e;
        }
    }

    /**
     * UTF-8 safe trim to max chars.
     */
    private function mbTrimTo(string $s, int $maxChars): string
    {
        $s = trim($s);
        if ($s === '') { return $s; }
        $len = mb_strlen($s, 'UTF-8');
        if ($len <= $maxChars) { return $s; }
        return rtrim(mb_substr($s, 0, $maxChars, 'UTF-8'));
    }
}
