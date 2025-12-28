<?php declare(strict_types=1);
/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: httpdocs/tools/queue_ingest_loop.php
 * Purpose:
 *   IMAP poller that:
 *    - pulls UNSEEN emails
 *    - enforces maintenance/limits
 *    - inserts into inbound_queue
 *    - sends a fast acknowledgement email
 *    - deletes the original email (GDPR + idempotency)
 *
 * Key UX rule (ACK email):
 *   - Confirm receipt and next step WITHOUT queue numbers or ETAs.
 *   - We avoid “Position/ETA” because it becomes a trust test and
 *     often backfires when timings vary.
 *
 * Change history:
 *   - 2025-12-28 17:xx:xxZ  ACK email: remove position/ETA, add email-confirm note (Flow B)
 * ============================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

date_default_timezone_set('UTC');

$ROOT = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');

/**
 * Minimal .env loader (does not override existing env).
 */
if (!function_exists('pf_load_env_file')) {
    function pf_load_env_file(string $path): void
    {
        if (!is_readable($path)) { return; }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) { return; }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) { continue; }
            if (!str_contains($line, '=')) { continue; }

            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);

            if (
                (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                (str_starts_with($v, "'") && str_ends_with($v, "'"))
            ) {
                $v = substr($v, 1, -1);
            }

            if ($k !== '' && getenv($k) === false) {
                putenv($k . '=' . $v);
                $_ENV[$k] = $v;
            }
        }
    }
}
pf_load_env_file($ROOT . '/.env');

// Optional app config include
$config = $GLOBALS['config'] ?? null;
$appConfigPath = $ROOT . '/config/app.php';
if ($config === null && is_readable($appConfigPath)) {
    /** @noinspection PhpIncludeInspection */
    $config = require $appConfigPath;
    $GLOBALS['config'] = $config;
}

// DB helper
require_once __DIR__ . '/../app/support/db.php';

// Mailer include
$mailerPath = $ROOT . '/app/support/mailer.php';
if (is_readable($mailerPath)) {
    /** @noinspection PhpIncludeInspection */
    require_once $mailerPath;
} else {
    fwrite(STDERR, "ERROR: mailer.php not found at {$mailerPath}\n");
    exit(1);
}

// Reuse limit + normaliser helpers if present
$hooksControllerPath = $ROOT . '/app/controllers/email_hooks_controller.php';
if (is_readable($hooksControllerPath)) {
    /** @noinspection PhpIncludeInspection */
    require_once $hooksControllerPath;
}

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
if (!function_exists('pf_env_int')) {
    function pf_env_int(string $k, int $default): int
    {
        $v = getenv($k);
        if ($v === false || $v === '') { return $default; }
        return (int)$v;
    }
}

if (!function_exists('pf_env_bool')) {
    function pf_env_bool(string $k, bool $default = false): bool
    {
        $v = getenv($k);
        if ($v === false || $v === '') { return $default; }
        return in_array(strtolower($v), ['1','true','yes','on'], true);
    }
}

if (!function_exists('pf_extract_email')) {
    function pf_extract_email(string $header): string
    {
        if (preg_match('/<([^>]+)>/', $header, $m)) {
            return strtolower(trim($m[1]));
        }
        return strtolower(trim($header));
    }
}

if (!function_exists('pf_mail_channel_for_to')) {
    function pf_mail_channel_for_to(string $to): array
    {
        $toLower = strtolower($to);
        if (str_contains($toLower, 'scamcheck@')) { return ['mode' => 'scamcheck', 'email_channel' => 'scamcheck']; }
        if (str_contains($toLower, 'clarify@'))   { return ['mode' => 'clarify',   'email_channel' => 'clarify']; }
        return ['mode' => 'generic', 'email_channel' => 'noreply'];
    }
}

if (!function_exists('pf_parse_mailbox_name')) {
    /**
     * Extracts the mailbox name (e.g. "INBOX") from an IMAP mailbox string like "{host:993/imap/ssl}INBOX".
     */
    function pf_parse_mailbox_name(string $mailbox): string
    {
        $pos = strrpos($mailbox, '}');
        if ($pos === false) { return $mailbox !== '' ? $mailbox : 'INBOX'; }
        $name = substr($mailbox, $pos + 1);
        $name = trim($name);
        return $name !== '' ? $name : 'INBOX';
    }
}

if (!function_exists('pf_queue_position')) {
    function pf_queue_position(PDO $pdo, int $newId): int
    {
        // Items ahead = rows not finished yet, with lower id.
        $stmt = $pdo->prepare('
            SELECT COUNT(*) AS c
            FROM inbound_queue
            WHERE status IN ("queued","prepped","ai_done","sending")
              AND id < :id
        ');
        $stmt->execute([':id' => $newId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }
}

if (!function_exists('pf_send_ack_email')) {
    /**
     * ACK email: confidence > metrics.
     *
     * We intentionally do NOT show:
     *  - queue position
     *  - ETA
     *
     * But we keep the signature stable so callers don’t break.
     */
    function pf_send_ack_email(string $toEmail, string $mode, int $posAhead, int $throughputPerMin): void
    {
        if (!function_exists('pf_send_email')) {
            error_log('pf_send_email not available; cannot send ack.');
            return;
        }
        $subject = 'Plainfully — received (we’ve got it)';

        $inner =
            '<p>Hello,</p>' .
            '<p>Thanks — we’ve received your message and it’s in our system.</p>' .
            '<p>We’ll email your Plainfully result to this address as soon as it’s ready.</p>' .
            '<p style="color:#6b7280;font-size:13px;margin:16px 0 0;">' .
                'When you open the result link, we’ll ask you to confirm your email address before you can view it.' .
            '</p>' .
            '<p style="color:#6b7280;font-size:13px;margin:12px 0 0;">No action needed right now.</p>';

        $html = function_exists('pf_email_template')
            ? pf_email_template($subject, $inner)
            : $inner;

        $text =
            "Hello,\n\n" .
            "Thanks — we’ve received your message and it’s in our system.\n" .
            "We’ll email your Plainfully result to this address as soon as it’s ready.\n\n" .
            "When you open the result link, we’ll ask you to confirm your email address before you can view it.\n\n" .
            "No action needed right now.\n";
            
        $channel = ($mode === 'scamcheck') ? 'scamcheck' : (($mode === 'clarify') ? 'clarify' : 'noreply');
        pf_send_email($toEmail, $subject, $html, $channel, $text);
    }
}

if (!function_exists('pf_send_maintenance_email')) {
    function pf_send_maintenance_email(string $toEmail, string $mode, string $message): void
    {
        if (!function_exists('pf_send_email')) {
            error_log('pf_send_email not available; cannot send maintenance msg.');
            return;
        }

        $subject = 'Plainfully: temporary maintenance';

        $inner =
            '<p>Hello,</p>' .
            '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>' .
            '<p style="color:#6b7280;font-size:13px;margin:16px 0 0;">Please resend your message a little later.</p>';

        $html = function_exists('pf_email_template')
            ? pf_email_template($subject, $inner)
            : $inner;

        $text =
            "Hello,\n\n" .
            $message . "\n\n" .
            "Please resend your message a little later.\n";

        $channel = ($mode === 'scamcheck') ? 'scamcheck' : (($mode === 'clarify') ? 'clarify' : 'noreply');
        pf_send_email($toEmail, $subject, $html, $channel, $text);
    }
}

if (!function_exists('pf_extract_message_id')) {
    /**
     * Extract RFC Message-ID header if present.
     */
    function pf_extract_message_id(string $rawHeaders): ?string
    {
        if (preg_match('/^Message-ID:\s*(.+)$/im', $rawHeaders, $m)) {
            $v = trim($m[1]);
            $v = trim($v, " \t\r\n<>");
            return $v !== '' ? $v : null;
        }
        return null;
    }
}

// ------------------------------------------------------------
// IMAP polling loop
// ------------------------------------------------------------
$mailbox = (string)(getenv('EMAIL_IMAP_MAILBOX') ?: '');
$user    = (string)(getenv('EMAIL_IMAP_USER') ?: '');
$pass    = (string)(getenv('EMAIL_IMAP_PASS') ?: '');

if ($mailbox === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "ERROR: Missing EMAIL_IMAP_MAILBOX/USER/PASS env vars.\n");
    exit(2);
}

if (!function_exists('imap_open')) {
    fwrite(STDERR, "ERROR: PHP IMAP extension not installed/enabled.\n");
    exit(3);
}

$pollSeconds = max(1, pf_env_int('EMAIL_POLL_SECONDS', 5));
$maxRuntime  = max(10, pf_env_int('EMAIL_POLL_MAX_RUNTIME_SECONDS', 55));
$maxBytes    = max(1000, pf_env_int('EMAIL_QUEUE_MAX_BYTES', 200000));
$throughput  = max(1, pf_env_int('EMAIL_QUEUE_THROUGHPUT_PER_MIN', 250));

$maintenance = pf_env_bool('PLAINFULLY_MAINTENANCE', false);
$maintMsg    = (string)(getenv('PLAINFULLY_MAINTENANCE_MESSAGE') ?: 'We’re doing a quick maintenance update right now.');

$mailboxName = pf_parse_mailbox_name($mailbox);

$start = time();
$loops = 0;

while (true) {
    $loops++;
    if ((time() - $start) >= $maxRuntime) { break; }

    $inbox = @imap_open($mailbox, $user, $pass, 0, 1);
    if ($inbox === false) {
        error_log('IMAP open failed: ' . (string)imap_last_error());
        sleep($pollSeconds);
        continue;
    }

    $emails = imap_search($inbox, 'UNSEEN');
    if ($emails === false) {
        imap_close($inbox);
        sleep($pollSeconds);
        continue;
    }

    sort($emails);

    try {
        $pdo = pf_db();
    } catch (Throwable $e) {
        $pdo = null;
    }

    foreach ($emails as $msgno) {
        $msgno = (int)$msgno;

        try {
            $overview = imap_fetch_overview($inbox, (string)$msgno, 0);
            $ov = is_array($overview) && isset($overview[0]) ? $overview[0] : null;

            $fromHeader = is_object($ov) && isset($ov->from) ? (string)$ov->from : '';
            $toHeader   = is_object($ov) && isset($ov->to) ? (string)$ov->to : '';
            $subject    = is_object($ov) && isset($ov->subject) ? (string)$ov->subject : '';
            $dateStr    = is_object($ov) && isset($ov->date) ? (string)$ov->date : '';

            $fromEmail  = pf_extract_email($fromHeader);
            $toEmail    = pf_extract_email($toHeader);

            $chan = pf_mail_channel_for_to($toEmail);
            $mode = (string)$chan['mode'];

            if ($maintenance) {
                pf_send_maintenance_email($fromEmail, $mode, $maintMsg);
                imap_delete($inbox, (string)$msgno);
                continue;
            }

            $isUnlimited = false;
            if (function_exists('pf_is_unlimited_tier_for_email')) {
                $isUnlimited = (bool)pf_is_unlimited_tier_for_email($fromEmail);
            }

            if (function_exists('pf_email_inbound_limit_status') && function_exists('pf_send_limit_upsell_email')) {
                $limit = pf_email_inbound_limit_status($fromEmail, $isUnlimited);
                if (($limit['limited'] ?? false) === true) {
                    pf_send_limit_upsell_email(
                        $fromEmail,
                        $mode,
                        (array)($limit['counts'] ?? []),
                        is_int($limit['reset_in_seconds'] ?? null) ? (int)$limit['reset_in_seconds'] : null
                    );
                    imap_delete($inbox, (string)$msgno);
                    continue;
                }
            }

            $imapUid = @imap_uid($inbox, $msgno);
            $imapUid = is_int($imapUid) ? $imapUid : null;

            $rawHeaders = (string)@imap_fetchheader($inbox, $msgno, FT_PREFETCHTEXT);
            $messageId  = pf_extract_message_id($rawHeaders);

            $rawBody = (string)@imap_body($inbox, $msgno, FT_PEEK);
            if ($rawBody === '') {
                $rawBody = (string)@imap_fetchbody($inbox, $msgno, '1', FT_PEEK);
            }

            if (strlen($rawBody) > $maxBytes) {
                $rawBody = substr($rawBody, 0, $maxBytes);
            }

            $rawIsHtml = (
                stripos($rawHeaders, 'Content-Type: text/html') !== false ||
                stripos($rawBody, '<html') !== false ||
                stripos($rawBody, '<body') !== false
            ) ? 1 : 0;

            if (!$pdo instanceof PDO) {
                throw new RuntimeException('DB unavailable (pf_db failed).');
            }

            $stmt = $pdo->prepare('
                INSERT INTO inbound_queue
                    (source, mailbox, imap_uid, message_id, from_email, to_email, subject, received_at, mode,
                     raw_body, raw_is_html, status,
                     attempts, last_error)
                VALUES
                    ("email", :mailbox, :imap_uid, :message_id, :from_email, :to_email, :subject, :received_at, :mode,
                     :raw_body, :raw_is_html, "queued",
                     0, NULL)
            ');

            $receivedAt = null;
            if ($dateStr !== '') {
                try {
                    $dt = new DateTimeImmutable($dateStr);
                    $receivedAt = $dt->format('Y-m-d H:i:s');
                } catch (Throwable $t) {
                    $receivedAt = null;
                }
            }

            $stmt->execute([
                ':mailbox'     => $mailboxName,
                ':imap_uid'    => $imapUid,
                ':message_id'  => $messageId,
                ':from_email'  => $fromEmail,
                ':to_email'    => $toEmail !== '' ? $toEmail : null,
                ':subject'     => $subject !== '' ? $subject : null,
                ':received_at' => $receivedAt,
                ':mode'        => $mode,
                ':raw_body'    => $rawBody,
                ':raw_is_html' => $rawIsHtml,
            ]);

            $newId = (int)$pdo->lastInsertId();
            $ahead = pf_queue_position($pdo, $newId);

            // ACK email (best-effort)
            pf_send_ack_email($fromEmail, $mode, $ahead, $throughput);

            // Store ack metadata (best-effort) — kept for internal analytics only
            try {
                $position = $ahead + 1;
                $mins = max(1, (int)ceil($position / max(1, $throughput)));

                $upd = $pdo->prepare('
                    UPDATE inbound_queue
                    SET ack_sent_at = NOW(),
                        ack_message = "queued",
                        queue_position_at_ack = :pos,
                        eta_minutes_at_ack = :eta
                    WHERE id = :id
                ');
                $upd->execute([':pos' => $position, ':eta' => $mins, ':id' => $newId]);
            } catch (Throwable $t) {
                // ignore
            }

            imap_delete($inbox, (string)$msgno);

        } catch (Throwable $e) {
            error_log('Poller message failed: ' . $e->getMessage());
            @imap_setflag_full($inbox, (string)$msgno, "\\Seen");
        }
    }

    imap_expunge($inbox);
    imap_close($inbox);

    sleep($pollSeconds);
}

echo "OK poller finished. loops={$loops}\n";
