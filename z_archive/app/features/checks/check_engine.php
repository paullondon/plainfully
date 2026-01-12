<?php declare(strict_types=1);

namespace App\Features\Checks;

use App\Support\Trace;

/**
 * ============================================================
 * Plainfully — CheckEngine
 * ============================================================
 * Purpose:
 *   - Orchestrates a SINGLE clarification lifecycle
 *   - Selects AI implementation (real vs dummy) based on ENV
 *   - Emits structured trace events per stage (if enabled)
 *
 * DEBUG CONTROL:
 *   PLAINFULLY_DEBUG=true  -> Dummy AI (deterministic, no cost)
 *   PLAINFULLY_DEBUG=false -> Real AI client
 *
 * Tracing:
 *   - Trace is per-run (single clarification)
 *   - Trace stages are coarse (stage) with fine-grained steps
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
     * Run a single clarification.
     */
    public function run(CheckInput $input, bool $isPaid): CheckResult
    {
        $traceId = function_exists('pf_trace_new_id') ? pf_trace_new_id() : null;

        // ------------------------------------------------------------
        // STAGE 1: Intake
        // ------------------------------------------------------------
        pf_trace($this->pdo, $traceId, 'info', 'intake', 'received', 'Clarification received', [
            'channel' => $input->channel,
            'has_email' => $input->email !== null,
        ]);

        // ------------------------------------------------------------
        // STAGE 2: AI analysis (fail-open)
        // ------------------------------------------------------------
        $analysis = [];
        try {
            pf_trace($this->pdo, $traceId, 'info', 'ai', 'analyze.start', 'Calling AI', [
                'debug' => getenv('PLAINFULLY_DEBUG') ?: 'false',
                'paid'  => $isPaid,
            ]);

            $analysis = $this->ai->analyze(
                $input->content,
                AiMode::Clarify,
                [
                    'is_paid' => $isPaid,
                    'channel' => $input->channel,
                ]
            );

            pf_trace($this->pdo, $traceId, 'info', 'ai', 'analyze.ok', 'AI returned successfully');

        } catch (\Throwable $e) {
            pf_trace($this->pdo, $traceId, 'error', 'ai', 'analyze.fail', 'AI failed (fail-open)', [
                'error' => $e->getMessage(),
            ]);
            $analysis = [];
        }

        // ------------------------------------------------------------
        // STAGE 3: Normalisation
        // ------------------------------------------------------------
        $headline = (string)($analysis['headline'] ?? 'Your result is ready');
        $risk     = (string)($analysis['scam_risk_level'] ?? 'low');
        $isScam   = ($risk === 'high');

        pf_trace($this->pdo, $traceId, 'info', 'normalise', 'fields', 'Fields normalised', [
            'risk' => $risk,
        ]);

        // ------------------------------------------------------------
        // STAGE 4: Persistence (fail-open)
        // ------------------------------------------------------------
        $checkId = null;

        try {
            $userId = $this->getOrCreateUserIdByEmail($input->sourceIdentifier);

            $stmt = $this->pdo->prepare('
                INSERT INTO checks
                  (user_id, channel, source_identifier, content_type, is_scam, is_paid, short_summary, ai_result_json, created_at)
                VALUES
                  (:uid, :ch, :src, :ct, :scam, :paid, :summary, :json, NOW())
            ');

            $stmt->execute([
                ':uid'     => $userId,
                ':ch'      => $input->channel,
                ':src'     => $input->sourceIdentifier,
                ':ct'      => $input->contentType,
                ':scam'    => $isScam ? 1 : 0,
                ':paid'    => $isPaid ? 1 : 0,
                ':summary' => $headline,
                ':json'    => json_encode($analysis, JSON_UNESCAPED_UNICODE),
            ]);

            $checkId = (int)$this->pdo->lastInsertId();

            pf_trace($this->pdo, $traceId, 'info', 'persist', 'db.insert', 'Check stored', [
                'check_id' => $checkId,
            ]);

        } catch (\Throwable $e) {
            pf_trace($this->pdo, $traceId, 'error', 'persist', 'db.fail', 'DB insert failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // ------------------------------------------------------------
        // STAGE 5: Output
        // ------------------------------------------------------------
        pf_trace($this->pdo, $traceId, 'info', 'output', 'return', 'Returning result');

        return new CheckResult(
            $checkId,
            'ok',
            $headline,
            $risk,
            'Safety check complete',
            'Summary available',
            '',
            '',
            '',
            '',
            '',
            $isPaid,
            ['trace_id' => $traceId],
            json_encode($analysis, JSON_UNESCAPED_UNICODE)
        );
    }

    private function getOrCreateUserIdByEmail(string $email): int
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Invalid email');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }

        $stmt = $this->pdo->prepare('INSERT INTO users (email, plan, created_at) VALUES (:e, "free", NOW())');
        $stmt->execute([':e' => $email]);

        return (int)$this->pdo->lastInsertId();
    }
}
