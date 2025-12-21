<?php declare(strict_types=1);

use App\Features\Checks\CheckInput;
use App\Features\Checks\CheckEngine;
use App\Features\Checks\DummyAiClient;

// Feature classes (no composer)
require_once dirname(__DIR__) . '/features/checks/check_input.php';
require_once dirname(__DIR__) . '/features/checks/check_result.php';
require_once dirname(__DIR__) . '/features/checks/ai_client.php';
require_once dirname(__DIR__) . '/features/checks/check_engine.php';

/* ========================================================================== *
 * ENV knobs (put these in .env)
 * --------------------------------------------------------------------------
 * FREE_CHECKS_LIMIT=3
 *
 * FAIRUSE_FREE_HOURLY=5
 * FAIRUSE_FREE_DAILY=10
 *
 * FAIRUSE_PAID_HOURLY=60
 * FAIRUSE_PAID_DAILY=500
 *
 * Notes:
 * - Rolling window is HARD-CODED to 28 days (data retention policy).
 * - SMS is always paid: currently NO throttling (by design).
 *   If you later want stability caps for SMS, add envs and wire them in.
 * ========================================================================== */

const PLAINFULLY_ROLLING_DAYS = 28;

if (!function_exists('pf_env_int')) {
    /**
     * Read an int from env safely (bounded).
     */
    function pf_env_int(string $key, int $default, int $min = 0, int $max = 1_000_000): int
    {
        $raw = getenv($key);
        if ($raw === false || $raw === '') {
            return $default;
        }

        $val = filter_var($raw, FILTER_VALIDATE_INT);
        if ($val === false) {
            return $default;
        }

        if ($val < $min) { return $min; }
        if ($val > $max) { return $max; }
        return $val;
    }
}

if (!function_exists('plainfully_email_sender_domain')) {
    function plainfully_email_sender_domain(?string $email): ?string
    {
        if (!$email || strpos($email, '@') === false) {
            return null;
        }

        $domain = trim(substr(strrchr($email, '@'), 1) ?: '');
        if ($domain === '') {
            return null;
        }

        $domain = strtolower($domain);
        $domain = preg_replace('/:\d+$/', '', $domain);

        return $domain ?: null;
    }
}

if (!function_exists('plainfully_normalise_email_text')) {
    /**
     * Convert subject+body (plain or HTML) into safe visible text.
     * - Strips tags
     * - Converts <a>text</a> to "text [link]" or "text [link – potentially risky]"
     */
    function plainfully_normalise_email_text(string $subject, string $body, ?string $fromEmail = null): string
    {
        $full = $subject !== '' ? ($subject . "\n\n" . $body) : $body;
        $senderDomain = plainfully_email_sender_domain($fromEmail);

        // Fast path (no obvious HTML)
        if (
            stripos($full, '<a ') === false &&
            stripos($full, '<html') === false &&
            stripos($full, '<body') === false
        ) {
            return trim($full);
        }

        // Replace anchors with safe text markers
        $full = preg_replace_callback(
            '/<a\b[^>]*>(.*?)<\/a>/is',
            static function (array $matches) use ($senderDomain): string {
                $anchorHtml  = $matches[0] ?? '';
                $anchorInner = $matches[1] ?? '';

                $anchorText = trim(strip_tags($anchorInner));
                if ($anchorText === '') {
                    $anchorText = 'link';
                }

                $suffix = ' [link]';

                if ($senderDomain !== null) {
                    $href = null;

                    if (preg_match('/href\s*=\s*"([^"]*)"/i', $anchorHtml, $m)) {
                        $href = $m[1];
                    } elseif (preg_match("/href\s*=\s*'([^']*)'/i", $anchorHtml, $m)) {
                        $href = $m[1];
                    }

                    if (is_string($href) && $href !== '') {
                        $host = parse_url($href, PHP_URL_HOST);

                        if (is_string($host) && $host !== '') {
                            $host = strtolower($host);

                            $isSimilar =
                                stripos($host, $senderDomain) !== false ||
                                stripos($senderDomain, $host) !== false;

                            if (!$isSimilar) {
                                $suffix = ' [link – potentially risky]';
                            }
                        }
                    }
                }

                return $anchorText . $suffix;
            },
            $full
        );

        $full = strip_tags($full);
        $full = html_entity_decode($full, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $full = str_replace(["\r\n", "\r"], "\n", $full);
        $full = preg_replace('/[ \t]+/u', ' ', $full);
        $full = preg_replace("/\n{3,}/u", "\n\n", $full);

        return trim($full);
    }
}

if (!function_exists('pf_require_hook_token')) {
    function pf_require_hook_token(string $envVarName): bool
    {
        $tokenHeader = $_SERVER['HTTP_X_PLAINFULLY_TOKEN'] ?? '';
        $expected    = getenv($envVarName) ?: '';

        if ($expected === '' || !hash_equals($expected, $tokenHeader)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Unauthorised hook call.']);
            return false;
        }

        return true;
    }
}

if (!function_exists('pf_detect_paid_by_email')) {
    /**
     * Best-effort paid check.
     * Fail-safe = treat as FREE if uncertain.
     *
     * Adjust the DB query if your users table differs.
     */
    function pf_detect_paid_by_email(string $email): bool
    {
        try {
            // Prefer your own helper if it exists
            if (function_exists('pf_user_find_by_email')) {
                $u = pf_user_find_by_email($email);
                return is_object($u) && isset($u->plan) && strtolower((string)$u->plan) === 'unlimited';
            }
            if (function_exists('pf_find_user_by_email')) {
                $u = pf_find_user_by_email($email);
                return is_object($u) && isset($u->plan) && strtolower((string)$u->plan) === 'unlimited';
            }

            // Fallback: minimal DB probe
            $pdo = pf_db();
            $stmt = $pdo->prepare('SELECT plan FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $plan = $stmt->fetchColumn();

            return is_string($plan) && strtolower($plan) === 'unlimited';
        } catch (Throwable $e) {
            error_log('pf_detect_paid_by_email fail-safe (FREE): ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('pf_free_plan_limit_hit')) {
    /**
     * FREE plan hard cap: N checks per rolling 28 days (hard-coded).
     *
     * Returns: [bool $hit, ?int $nextAvailableEpoch]
     */
    function pf_free_plan_limit_hit(string $sourceIdentifier, int $limit): array
    {
        try {
            $pdo = pf_db();
            $since = (new DateTimeImmutable('-' . PLAINFULLY_ROLLING_DAYS . ' days'))->format('Y-m-d H:i:s');

            $stmt = $pdo->prepare('
                SELECT COUNT(*) AS c
                FROM checks
                WHERE source_identifier = :sid
                  AND created_at >= :since
            ');
            $stmt->execute([
                ':sid'   => $sourceIdentifier,
                ':since' => $since,
            ]);

            $count = (int)$stmt->fetchColumn();
            if ($count < $limit) {
                return [false, null];
            }

            // Oldest in window => next free time
            $stmtOldest = $pdo->prepare('
                SELECT created_at
                FROM checks
                WHERE source_identifier = :sid
                  AND created_at >= :since
                ORDER BY created_at ASC
                LIMIT 1
            ');
            $stmtOldest->execute([
                ':sid'   => $sourceIdentifier,
                ':since' => $since,
            ]);

            $oldest = $stmtOldest->fetchColumn();
            if (!is_string($oldest) || $oldest === '') {
                return [true, null];
            }

            $oldestDt = new DateTimeImmutable($oldest);
            $nextDt   = $oldestDt->modify('+' . PLAINFULLY_ROLLING_DAYS . ' days');

            return [true, $nextDt->getTimestamp()];
        } catch (Throwable $e) {
            // Fail-safe: block if we cannot compute usage
            error_log('pf_free_plan_limit_hit error (fail-safe HIT): ' . $e->getMessage());
            return [true, null];
        }
    }
}

if (!function_exists('pf_sender_fairuse_limit_hit_email')) {
    /**
     * Fair-use caps for EMAIL channels (always enforced).
     * Returns: [bool $hit, int $hourCount, int $dayCount]
     */
    function pf_sender_fairuse_limit_hit_email(string $fromEmail, int $hourLimit, int $dayLimit): array
    {
        try {
            $pdo = pf_db();

            $hourAgo = (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
            $dayAgo  = (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');

            $stmt = $pdo->prepare('
                SELECT
                    SUM(created_at >= :hourAgo) AS hour_count,
                    SUM(created_at >= :dayAgo)  AS day_count
                FROM checks
                WHERE source_identifier = :sender
                  AND channel IN ("email", "email-clarify", "email-scamcheck")
            ');

            $stmt->execute([
                ':sender'  => $fromEmail,
                ':hourAgo' => $hourAgo,
                ':dayAgo'  => $dayAgo,
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $hourCount = (int)($row['hour_count'] ?? 0);
            $dayCount  = (int)($row['day_count'] ?? 0);

            $hit = ($hourLimit > 0 && $hourCount >= $hourLimit) || ($dayLimit > 0 && $dayCount >= $dayLimit);
            return [$hit, $hourCount, $dayCount];
        } catch (Throwable $e) {
            // Fail-safe: block if we cannot compute fair-use
            error_log('pf_sender_fairuse_limit_hit_email error (fail-safe HIT): ' . $e->getMessage());
            return [true, 0, 0];
        }
    }
}

if (!function_exists('email_inbound_dev_controller')) {
    function email_inbound_dev_controller(): void
    {
        global $config;

        header('Content-Type: application/json; charset=utf-8');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed. Use POST.']);
            return;
        }

        if (!pf_require_hook_token('EMAIL_HOOK_TOKEN')) {
            return;
        }

        $from    = trim((string)($_POST['from'] ?? ''));
        $to      = trim((string)($_POST['to'] ?? ''));
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body    = trim((string)($_POST['body'] ?? ''));

        if ($from === '' || $body === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Fields "from" and "body" are required.']);
            return;
        }

        // Decide mode based on TO address
        $toLower      = strtolower($to);
        $mode         = 'generic';
        $checkChannel = 'email';
        $emailChannel = 'noreply';

        if ($toLower !== '') {
            if (str_contains($toLower, 'scamcheck@')) {
                $mode         = 'scamcheck';
                $checkChannel = 'email-scamcheck';
                $emailChannel = 'scamcheck';
            } elseif (str_contains($toLower, 'clarify@')) {
                $mode         = 'clarify';
                $checkChannel = 'email-clarify';
                $emailChannel = 'clarify';
            }
        }

        // Determine paid status (fail-safe = free)
        $isPaid = pf_detect_paid_by_email($from);

        // ENV-driven caps
        $freeLimit = pf_env_int('FREE_CHECKS_LIMIT', 3, 0, 1000);

        $fairFreeHr  = pf_env_int('FAIRUSE_FREE_HOURLY', 5, 0, 100000);
        $fairFreeDay = pf_env_int('FAIRUSE_FREE_DAILY', 10, 0, 100000);

        $fairPaidHr  = pf_env_int('FAIRUSE_PAID_HOURLY', 60, 0, 100000);
        $fairPaidDay = pf_env_int('FAIRUSE_PAID_DAILY', 500, 0, 100000);

        // 1) Free plan hard cap (rolling 28 days)
        if (!$isPaid && $freeLimit > 0) {
            [$hit, $nextEpoch] = pf_free_plan_limit_hit($from, $freeLimit);
            if ($hit) {
                http_response_code(402);
                echo json_encode([
                    'error' => 'Free plan limit reached.',
                    'plan'  => 'free',
                    'limit' => $freeLimit,
                    'rolling_days' => PLAINFULLY_ROLLING_DAYS,
                    'next_free_epoch' => $nextEpoch,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }
        }

        // 2) Fair-use caps (always enforced)
        $hourLimit = $isPaid ? $fairPaidHr : $fairFreeHr;
        $dayLimit  = $isPaid ? $fairPaidDay : $fairFreeDay;

        if ($hourLimit > 0 || $dayLimit > 0) {
            [$hit, $hourCount, $dayCount] = pf_sender_fairuse_limit_hit_email($from, $hourLimit, $dayLimit);
            if ($hit) {
                http_response_code(429);
                echo json_encode([
                    'error'      => 'Fair-use limit reached.',
                    'plan'       => $isPaid ? 'paid' : 'free',
                    'hour_usage' => $hourCount,
                    'day_usage'  => $dayCount,
                    'hour_limit' => $hourLimit,
                    'day_limit'  => $dayLimit,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }
        }

        // Normalise HTML -> safe text
        $rawContent = plainfully_normalise_email_text($subject, $body, $from);

        // Run engine
        $pdo      = pf_db();
        $aiClient = new DummyAiClient();
        $engine   = new CheckEngine($pdo, $aiClient);

        $input = new CheckInput(
            $checkChannel,
            $from,
            'text/plain',
            $rawContent,
            $from,
            null,
            null
        );

        try {
            $result = $engine->run($input, $isPaid);

            $baseUrl = rtrim((string)($config['app']['base_url'] ?? 'https://plainfully.com'), '/');
            $checkId = (int)($result->id ?? 0);
            $viewUrl = $baseUrl . '/clarifications/view?id=' . $checkId;

            if ($mode === 'scamcheck') {
                $outSubject = 'Plainfully ScamCheck result';
                $intro      = 'We checked the message you forwarded to Plainfully ScamCheck.';
            } elseif ($mode === 'clarify') {
                $outSubject = 'Plainfully clarification result';
                $intro      = 'Here’s your Plainfully clarification summary.';
            } else {
                $outSubject = 'Plainfully check result';
                $intro      = 'Here’s the summary of the text you sent to Plainfully.';
            }

            $innerHtml =
                '<p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>' .
                '<p><strong>Verdict:</strong> ' . htmlspecialchars((string)$result->shortVerdict, ENT_QUOTES, 'UTF-8') . '</p>' .
                '<p><strong>Key things to know:</strong><br>' .
                nl2br(htmlspecialchars((string)$result->inputCapsule, ENT_QUOTES, 'UTF-8')) . '</p>' .
                '<p><a href="' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '">View full details</a></p>';

            $htmlBody = function_exists('pf_email_template')
                ? pf_email_template($outSubject, $innerHtml)
                : $innerHtml;

            $textBody =
                $intro . "\n\n" .
                'Verdict: ' . (string)$result->shortVerdict . "\n\n" .
                "Key things to know:\n" . (string)$result->inputCapsule . "\n\n" .
                "View full details:\n" . $viewUrl . "\n";

            $emailSent = false;
            $mailError = null;

            if (function_exists('pf_send_email')) {
                [$emailSent, $mailError] = pf_send_email(
                    $from,
                    $outSubject,
                    $htmlBody,
                    $emailChannel,
                    $textBody
                );
            } else {
                $mailError = 'pf_send_email helper not defined.';
            }

            echo json_encode([
                'status'        => 'ok',
                'check_id'      => $checkId,
                'short_verdict' => $result->shortVerdict,
                'is_scam'       => $result->isScam,
                'is_paid'       => $result->isPaid,
                'view_url'      => $viewUrl,
                'email_sent'    => $emailSent,
                'mail_error'    => $mailError,
                'mode'          => $mode,
                'plan'          => $isPaid ? 'paid' : 'free',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        } catch (Throwable $e) {
            error_log('email_inbound_dev_controller error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal error processing email hook.']);
        }
    }
}

if (!function_exists('sms_inbound_dev_controller')) {
    /**
     * DEV inbound SMS hook (POST: from, body)
     *
     * Your stated rule: SMS is ALWAYS paid.
     * So: no free cap + no fair-use cap (unless you later want stability).
     */
    function sms_inbound_dev_controller(): void
    {
        global $config;

        header('Content-Type: application/json; charset=utf-8');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed. Use POST.']);
            return;
        }

        if (!pf_require_hook_token('SMS_HOOK_TOKEN')) {
            return;
        }

        $from = trim((string)($_POST['from'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));

        if ($from === '' || $body === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Fields "from" and "body" are required.']);
            return;
        }

        $pdo      = pf_db();
        $aiClient = new DummyAiClient();
        $engine   = new CheckEngine($pdo, $aiClient);

        // SMS is always paid (per your rule)
        $isPaid = true;

        $input = new CheckInput(
            'sms',
            $from,
            'text/plain',
            $body,
            null,
            $from,
            null
        );

        try {
            $result = $engine->run($input, $isPaid);

            $baseUrl = rtrim((string)($config['app']['base_url'] ?? 'https://plainfully.com'), '/');
            $checkId = (int)($result->id ?? 0);
            $viewUrl = $baseUrl . '/clarifications/view?id=' . $checkId;

            $smsReply = $result->isScam
                ? 'Plainfully: This text looks like a scam. Don’t click links or share codes. Verify the sender via a trusted source.'
                : 'Plainfully: No obvious scam signs found, but stay cautious with links and requests for personal or payment details.';

            echo json_encode([
                'status'             => 'ok',
                'check_id'           => $checkId,
                'short_verdict'      => $result->shortVerdict,
                'is_scam'            => $result->isScam,
                'is_paid'            => $result->isPaid,
                'view_url'           => $viewUrl,
                'sms_reply_template' => $smsReply,
                'plan'               => 'paid',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        } catch (Throwable $e) {
            error_log('sms_inbound_dev_controller error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal error running CheckEngine.',
                'code'  => 'checkengine_failure',
            ]);
        }
    }
}
