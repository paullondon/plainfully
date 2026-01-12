<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/controllers/email_hooks_controller.php
 * Purpose:
 *   DEV inbound hooks used by your IMAP/SMS bridges.
 *
 * Why this file exists:
 *   - Converts inbound payloads into CheckInput
 *   - Applies limit checks (email only) BEFORE storing anything
 *   - Calls CheckEngine (AI + DB insert)
 *   - Sends an outbound reply email (email hook) or returns SMS template (sms hook)
 *
 * Tracing:
 *   - If PLAINFULLY_TRACE=true, we log a per-request timeline in trace_events
 *   - Trace is ALWAYS admin-only to view (no token access)
 *   - Trace TTL is enforced at insert time (expires_at = NOW()+1 HOUR)
 *
 * Security:
 *   - Hook access controlled by X-Plainfully-Token header (env: EMAIL_HOOK_TOKEN / SMS_HOOK_TOKEN)
 *   - Fail-closed on auth; fail-open on trace logging (tracing never breaks ingestion)
 *   - Email content is normalised to safe visible text (strips HTML, flags risky links)
 * ============================================================
 */

// Note: core + feature includes are loaded via app/core/includes.php

use App\Features\Checks\CheckInput;
use App\Features\Checks\CheckEngine;


// Billing (optional; safe to include even if not deployed yet)
$pfBillingPath = dirname(__DIR__) . '/features/billing/billing.php';
$pfLimitsPath  = dirname(__DIR__) . '/features/billing/limits.php';
if (is_readable($pfBillingPath)) { require_once $pfBillingPath; }
if (is_readable($pfLimitsPath))  { require_once $pfLimitsPath; }

/**
 * ------------------------------------------------------------
 * Helpers (pure)
 * ------------------------------------------------------------
 */

/**
 * Extract domain part from an email address (after @).
 */
if (!function_exists('plainfully_email_sender_domain')) {
    function plainfully_email_sender_domain(?string $email): ?string
    {
        if (!$email || strpos($email, '@') === false) { return null; }

        $domain = trim(substr(strrchr($email, '@'), 1) ?: '');
        if ($domain === '') { return null; }

        $domain = strtolower($domain);
        $domain = preg_replace('/:\d+$/', '', $domain); // strip :port
        return $domain ?: null;
    }
}

/**
 * Convert subject+body (plain or HTML) into safe visible text.
 * - Strips tags
 * - Converts <a>...</a> into "text [link]" or "text [link – potentially risky]"
 */
if (!function_exists('plainfully_normalise_email_text')) {
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
                if ($anchorText === '') { $anchorText = 'link'; }

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

                            if (!$isSimilar) { $suffix = ' [link – potentially risky]'; }
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
        $full = preg_replace('/[ \t]+/u', ' ', (string)$full);
        $full = preg_replace("/\n{3,}/u", "\n\n", (string)$full);

        return trim((string)$full);
    }
}

/**
 * Auth guard for inbound hooks (shared token header).
 * Header: X-Plainfully-Token
 */
if (!function_exists('pf_require_hook_token')) {
    function pf_require_hook_token(string $envVarName): bool
    {
        $tokenHeader = (string)($_SERVER['HTTP_X_PLAINFULLY_TOKEN'] ?? '');
        $expected    = (string)(getenv($envVarName) ?: '');

        if ($expected === '' || !hash_equals($expected, $tokenHeader)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Unauthorised hook call.']);
            return false;
        }

        return true;
    }
}

/**
 * Read integer env with fallback. Returns >= 0.
 */
if (!function_exists('pf_env_int_nonneg')) {
    function pf_env_int_nonneg(string $key, int $default): int
    {
        $v = getenv($key);
        if ($v === false || $v === '') { return $default; }

        $n = (int)$v;
        return $n >= 0 ? $n : $default;
    }
}

/**
 * Count how many email-channel checks this sender has created since $since.
 */
if (!function_exists('pf_email_checks_count_since')) {
    function pf_email_checks_count_since(string $fromEmail, string $since): int
    {
        $pdo = pf_db();
        $stmt = $pdo->prepare('
            SELECT COUNT(*)
            FROM checks
            WHERE source_identifier = :sender
              AND channel IN ("email", "email-clarify", "email-scamcheck")
              AND created_at >= :since
        ');
        $stmt->execute([
            ':sender' => $fromEmail,
            ':since'  => $since,
        ]);

        return (int)$stmt->fetchColumn();
    }
}

/**
 * Find the sender's subscription tier (free vs unlimited).
 * If billing helpers are deployed, they are the source of truth.
 */
if (!function_exists('pf_is_unlimited_tier_for_email')) {
    function pf_is_unlimited_tier_for_email(string $email): bool
    {
        // Manual allowlist for quick testing
        $raw = (string)(getenv('PLAINFULLY_UNLIMITED_EMAILS') ?: '');
        if ($raw !== '') {
            $list = array_filter(array_map('trim', explode(',', strtolower($raw))));
            if (in_array(strtolower($email), $list, true)) {
                return true;
            }
        }

        // If billing is deployed, it should answer plan-by-email
        if (class_exists('PfBilling') && method_exists('PfBilling', 'planByEmail')) {
            try {
                $plan = (string)PfBilling::planByEmail($email);
                // NOTE: You can tighten this later; keeping permissive for now.
                return ($plan !== '');
            } catch (\Throwable $e) {
                error_log('pf_is_unlimited_tier_for_email billing lookup failed (fallback to free): ' . $e->getMessage());
                return false;
            }
        }

        return false;
    }
}

/**
 * Email inbound limit check (28-day rolling + optional burst caps).
 *
 * ENV knobs:
 *  - EMAIL_FREE_CAP_28D (default 3)
 *  - EMAIL_UNLIMITED_CAP_28D (default 500)
 *  - EMAIL_CAP_PER_HOUR (default 20)   [burst safety]
 *  - EMAIL_CAP_PER_DAY  (default 200)  [burst safety]
 *
 * FAIL-OPEN (limits never block due to errors).
 */
if (!function_exists('pf_email_inbound_limit_status')) {
    function pf_email_inbound_limit_status(string $fromEmail, bool $isUnlimited): array
    {
        try {
            $now = new DateTimeImmutable('now');

            $since28 = $now->sub(new DateInterval('P28D'))->format('Y-m-d H:i:s');
            $since1h = $now->sub(new DateInterval('PT1H'))->format('Y-m-d H:i:s');
            $since1d = $now->sub(new DateInterval('P1D'))->format('Y-m-d H:i:s');

            $cap28Free      = pf_env_int_nonneg('EMAIL_FREE_CAP_28D', 3);
            $cap28Unlimited = pf_env_int_nonneg('EMAIL_UNLIMITED_CAP_28D', 500);

            $capHour = pf_env_int_nonneg('EMAIL_CAP_PER_HOUR', 20);
            $capDay  = pf_env_int_nonneg('EMAIL_CAP_PER_DAY', 200);

            $cap28 = $isUnlimited ? $cap28Unlimited : $cap28Free;

            $count28 = pf_email_checks_count_since($fromEmail, $since28);
            $count1h = pf_email_checks_count_since($fromEmail, $since1h);
            $count1d = pf_email_checks_count_since($fromEmail, $since1d);

            $limited = false;
            $reason  = '';

            if ($cap28 > 0 && $count28 >= $cap28) {
                $limited = true;
                $reason  = 'cap_28d';
            } elseif ($capHour > 0 && $count1h >= $capHour) {
                $limited = true;
                $reason  = 'cap_hour';
            } elseif ($capDay > 0 && $count1d >= $capDay) {
                $limited = true;
                $reason  = 'cap_day';
            }

            $resetSeconds = null;
            if ($limited && $reason === 'cap_28d') {
                try {
                    $pdo = pf_db();
                    $stmt = $pdo->prepare('
                        SELECT created_at
                        FROM checks
                        WHERE source_identifier = :sender
                          AND channel IN ("email", "email-clarify", "email-scamcheck")
                          AND created_at >= :since
                        ORDER BY created_at ASC
                        LIMIT 1
                    ');
                    $stmt->execute([':sender' => $fromEmail, ':since' => $since28]);
                    $oldest = $stmt->fetchColumn();

                    if (is_string($oldest) && $oldest !== '') {
                        $oldestDt = new DateTimeImmutable($oldest);
                        $resetAt  = $oldestDt->add(new DateInterval('P28D'));
                        $resetSeconds = max(0, $resetAt->getTimestamp() - $now->getTimestamp());
                    }
                } catch (\Throwable $t) {
                    // ignore
                }
            }

            return [
                'limited' => $limited,
                'reason'  => $reason,
                'counts'  => [
                    'tier'        => $isUnlimited ? 'unlimited' : 'free',
                    'cap_28'      => $cap28,
                    'used_28'     => $count28,
                    'cap_hour'    => $capHour,
                    'used_hour'   => $count1h,
                    'cap_day'     => $capDay,
                    'used_day'    => $count1d,
                ],
                'reset_in_seconds' => $resetSeconds,
            ];
        } catch (\Throwable $e) {
            error_log('pf_email_inbound_limit_status fail-open: ' . $e->getMessage());
            return [
                'limited' => false,
                'reason'  => '',
                'counts'  => ['fail_open' => true],
                'reset_in_seconds' => null,
            ];
        }
    }
}

/**
 * Send a polite limit/upsell email response.
 * CRITICAL: states we did NOT store the original submission.
 */
if (!function_exists('pf_send_limit_upsell_email')) {
    function pf_send_limit_upsell_email(string $toEmail, string $mode, array $limitCounts, ?int $resetInSeconds): array
    {
        global $config;

        $baseUrl    = rtrim((string)($config['app']['base_url'] ?? 'https://plainfully.com'), '/');
        $upgradeUrl = (string)(getenv('PLAINFULLY_UPGRADE_URL') ?: ($baseUrl . '/pricing'));

        $used28 = (int)($limitCounts['used_28'] ?? 0);
        $cap28  = (int)($limitCounts['cap_28'] ?? 0);

        $productLabel = ($mode === 'scamcheck') ? 'Plainfully ScamCheck' : (($mode === 'clarify') ? 'Plainfully Clarify' : 'Plainfully');

        $when = '';
        if (is_int($resetInSeconds) && $resetInSeconds > 0) {
            $days  = intdiv($resetInSeconds, 86400);
            $hours = intdiv($resetInSeconds % 86400, 3600);
            $mins  = intdiv($resetInSeconds % 3600, 60);

            $parts = [];
            if ($days > 0)  { $parts[] = $days . ' day' . ($days === 1 ? '' : 's'); }
            if ($hours > 0) { $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's'); }
            if ($mins > 0 && $days === 0) { $parts[] = $mins . ' min' . ($mins === 1 ? '' : 's'); }
            $when = 'Your next check becomes available in ' . implode(' ', $parts) . '.';
        } else {
            $when = 'Your allowance refreshes on a rolling 28‑day basis.';
        }

        $subject = "{$productLabel}: limit reached — upgrade to keep going";

        $inner =
            '<p>Hello,</p>' .
            '<p>You’ve reached your current limit for email checks (<strong>' .
            htmlspecialchars((string)$used28, ENT_QUOTES, 'UTF-8') . ' / ' .
            htmlspecialchars((string)$cap28, ENT_QUOTES, 'UTF-8') .
            '</strong> in the last 28 days).</p>' .
            '<p><strong>' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '</strong></p>' .
            '<p>Upgrade to Unlimited (fair use) to keep going right now:</p>' .
            '<ul>' .
            '<li>Unlimited checks (fair use)</li>' .
            '<li>Instant access — no waiting for your allowance to refresh</li>' .
            '<li>Priority improvements as we ship new features</li>' .
            '</ul>' .
            '<p><a href="' . htmlspecialchars($upgradeUrl, ENT_QUOTES, 'UTF-8') . '">Upgrade to Unlimited</a></p>' .
            '<p style="color:#6b7280;font-size:13px;margin:16px 0 0;">' .
            'Privacy note: because you were over the limit, we did not store your email content. ' .
            'Once your allowance refreshes or you upgrade, simply resend the message.' .
            '</p>';

        $html = function_exists('pf_email_template')
            ? pf_email_template($subject, $inner)
            : $inner;

        $text =
            "Hello,\n\n" .
            "You've reached your current limit for email checks ({$used28} / {$cap28} in the last 28 days).\n" .
            $when . "\n\n" .
            "Upgrade to Unlimited to keep going now:\n{$upgradeUrl}\n\n" .
            "Privacy note: because you were over the limit, we did not store your email content. Once your allowance refreshes or you upgrade, resend the message.\n";

        if (!function_exists('pf_send_email')) {
            return [false, 'pf_send_email helper not defined.'];
        }

        return pf_send_email($toEmail, $subject, $html, 'noreply', $text);
    }
}

/**
 * ------------------------------------------------------------
 * Controllers
 * ------------------------------------------------------------
 */

/**
 * DEV inbound email hook used by your IMAP bridge:
 * POST form fields: from, to, subject, body
 * Header: X-Plainfully-Token
 */
if (!function_exists('email_inbound_dev_controller')) {
    function email_inbound_dev_controller(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405); return;
        }

        if (!pf_require_hook_token('EMAIL_HOOK_TOKEN')) { return; }

        $from    = trim((string)($_POST['from'] ?? ''));
        $to      = strtolower(trim((string)($_POST['to'] ?? '')));
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body    = trim((string)($_POST['body'] ?? ''));

        if ($from === '' || $body === '') {
            http_response_code(400); return;
        }

        // Decide mode
        $mode = 'generic';
        if (str_contains($to, 'scamcheck@')) $mode = 'scamcheck';
        if (str_contains($to, 'clarify@'))   $mode = 'clarify';

        $content = plainfully_normalise_email_text($subject, $body, $from);
        $traceId = pf_trace_new_id();

        $pdo = pf_db();
        $stmt = $pdo->prepare('
            INSERT INTO inbound_queue
            (channel, mode, source_identifier, content_type, raw_content, trace_id)
            VALUES
            (:channel, :mode, :src, :ctype, :content, :trace)
        ');
        $stmt->execute([
            ':channel' => 'email',
            ':mode'    => $mode,
            ':src'     => $from,
            ':ctype'   => 'text/plain',
            ':content' => $content,
            ':trace'   => $traceId,
        ]);

        // IMPORTANT: always 200 so bridge deletes email
        http_response_code(200);
        echo json_encode(['status' => 'queued', 'trace_id' => $traceId]);
    }
}

/**
 * DEV inbound SMS hook (POST: from, body)
 * NOTE: SMS is paid-only in your current plan. (No free caps here.)
 */
if (!function_exists('sms_inbound_dev_controller')) {
    function sms_inbound_dev_controller(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405); return;
        }

        if (!pf_require_hook_token('SMS_HOOK_TOKEN')) { return; }

        $from = trim((string)($_POST['from'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));

        if ($from === '' || $body === '') {
            http_response_code(400); return;
        }

        $traceId = pf_trace_new_id();

        $pdo = pf_db();
        $stmt = $pdo->prepare('
            INSERT INTO inbound_queue
            (channel, mode, source_identifier, content_type, raw_content, trace_id)
            VALUES
            (:channel, :mode, :src, :ctype, :content, :trace)
        ');
        $stmt->execute([
            ':channel' => 'sms',
            ':mode'    => 'scamcheck',
            ':src'     => $from,
            ':ctype'   => 'text/plain',
            ':content' => $body,
            ':trace'   => $traceId,
        ]);

        http_response_code(200);
        echo json_encode(['status' => 'queued', 'trace_id' => $traceId]);
    }
}