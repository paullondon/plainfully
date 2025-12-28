<?php declare(strict_types=1);

namespace App\Features\Checks;

use PDO;
use Throwable;

/**
 * CheckEngine
 *
 * - Takes a CheckInput
 * - Calls AiClient
 * - Ensures a matching `users` row exists (by email) and gets user_id
 * - Writes a row to `checks`
 * - Returns CheckResult
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

        $analysis = $this->ai->analyze($input->content, $mode);

        // Defensive parsing (Dummy client may return strings)
        $shortVerdict = (string)($analysis['short_verdict'] ?? 'Unknown');
        $capsule      = (string)($analysis['capsule'] ?? '');
        $isScamRaw    = $analysis['is_scam'] ?? false;
        $isScam       = is_bool($isScamRaw) ? $isScamRaw : (strtolower((string)$isScamRaw) === 'true' || (string)$isScamRaw === '1');

        // Always write valid JSON into ai_result_json (constraint-safe)
        // - If upstream already provides an array/object, encode it.
        // - If upstream provides a string, try to treat it as JSON; fallback to {}.
        $aiJson = '{}';
        try {
            if (is_array($analysis)) {
                $tmp = json_encode($analysis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($tmp) && $tmp !== '' && json_last_error() === JSON_ERROR_NONE) {
                    $aiJson = $tmp;
                }
            } elseif (is_string($analysis)) {
                $decoded = json_decode($analysis, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $tmp = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if (is_string($tmp) && $tmp !== '' && json_last_error() === JSON_ERROR_NONE) {
                        $aiJson = $tmp;
                    }
                }
            }
        } catch (Throwable $e) {
            // keep {}
        }

        // Store (best effort). If DB write fails, still return result so UX works.
        $id = null;

        try {
            $userId = $this->getOrCreateUserIdByEmail($input->sourceIdentifier);

            // IMPORTANT:
            // Your schema enforces fk_checks_user_id -> users.id, so user_id must exist.
            // Your schema enforces a constraint on checks.ai_result_json, so it must be valid JSON.
            $stmt = $this->pdo->prepare('
                INSERT INTO checks
                    (user_id, channel, source_identifier, content_type, is_scam, is_paid, short_verdict, input_capsule, ai_result_json, created_at)
                VALUES
                    (:user_id, :channel, :source_identifier, :content_type, :is_scam, :is_paid, :short_verdict, :input_capsule, :ai_result_json, NOW())
            ');

            $stmt->execute([
                ':user_id'           => $userId,
                ':channel'           => $input->channel,
                ':source_identifier' => $input->sourceIdentifier,
                ':content_type'      => $input->contentType,
                ':is_scam'           => $isScam ? 1 : 0,
                ':is_paid'           => $isPaid ? 1 : 0,
                ':short_verdict'     => $shortVerdict,
                ':input_capsule'     => $capsule,
                ':ai_result_json'    => $aiJson,
            ]);

            $id = (int)$this->pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('CheckEngine DB insert failed (fail-open): ' . $e->getMessage());
        }

        return new CheckResult(
            $id,
            $shortVerdict,
            $capsule,
            $isScam,
            $isPaid,
            ['mode' => $mode]
        );
    }

    /**
     * Ensures a user exists for the given email address and returns users.id.
     *
     * - Uses users.email (UNIQUE) as the natural key.
     * - Inserts plan='free' for new users (matches your users.plan enum default).
     * - Race-safe: if two processes insert at once, handle duplicate then re-select.
     */
    private function getOrCreateUserIdByEmail(string $email): int
    {
        $email = trim(strtolower($email));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Fail-closed: do not invent a user_id. This avoids FK errors masking bad input.
            throw new \RuntimeException('Invalid user email for user lookup.');
        }

        // 1) Try select
        $sel = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $sel->execute([':email' => $email]);
        $found = $sel->fetchColumn();
        if ($found !== false && $found !== null) {
            return (int)$found;
        }

        // 2) Insert (best effort)
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
            // Duplicate insert or other transient issue; re-select
            $sel2 = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $sel2->execute([':email' => $email]);
            $found2 = $sel2->fetchColumn();
            if ($found2 !== false && $found2 !== null) {
                return (int)$found2;
            }

            throw $e;
        }
    }
}