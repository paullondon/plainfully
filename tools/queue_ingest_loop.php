<?php declare(strict_types=1);
/**
 * tools/email_inbox_poller.php
 *
 * Plainfully — IMAP poller (fast acknowledgement + queue insert).
 *
 * Intended cron:
 *   * * * * * /usr/bin/php -d detect_unicode=0 /var/www/vhosts/plainfully.com/httpdocs/tools/email_inbox_poller.php >> /var/www/vhosts/plainfully.com/logs/email_inbox_poller.log 2>&1
 *
 * What it does:
 * - Loops for up to ~55 seconds (sleeping 5s between polls)
 * - Pulls UNSEEN emails from IMAP
 * - Enforces limits/maintenance quickly (reply & delete email)
 * - Otherwise inserts into `email_queue` then sends an acknowledgement email
 * - Deletes the original email from the mailbox (GDPR + idempotency)
 *
 * ENV required (IMAP):
 *   EMAIL_IMAP_MAILBOX   e.g. "{imap.ionos.co.uk:993/imap/ssl}INBOX"
 *   EMAIL_IMAP_USER
 *   EMAIL_IMAP_PASS
 *
 * Optional ENV:
 *   EMAIL_POLL_SECONDS=5
 *   EMAIL_POLL_MAX_RUNTIME_SECONDS=55
 *   EMAIL_QUEUE_MAX_BYTES=200000
 *   EMAIL_QUEUE_THROUGHPUT_PER_MIN=250
 *   PLAINFULLY_MAINTENANCE=0|1
 *   PLAINFULLY_MAINTENANCE_MESSAGE="..."
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

date_default_timezone_set('UTC');

// ------------------------------------------------------------
// Bootstrap (best-effort)
// ------------------------------------------------------------
$ROOT = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';

// Load .env if present (minimal parser; does not override existing env)
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

            // Remove surrounding quotes
            if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
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

// Optional app config include (if you have it)
$config = $GLOBALS['config'] ?? null;
$appConfigPath = $ROOT . '/config/app.php';
if ($config === null && is_readable($appConfigPath)) {
    /** @noinspection PhpIncludeInspection */
    $config = require $appConfigPath;
    $GLOBALS['config'] = $config;
}

// DB helper (fallback) --------------------------------------------------------
if (!function_exists('pf_db')) {
    function pf_db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $dsn  = getenv('DB_DSN') ?: '';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS') ?: '';

        if ($dsn === '') {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $name = getenv('DB_NAME') ?: '';
            $charset = getenv('DB_CHARSET') ?: 'utf8mb4';
            if ($name === '') {
                throw new RuntimeException('DB_DSN or DB_NAME must be set.');
            }
            $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
        }

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return $pdo;
    }
}

// Mailer include --------------------------------------------------------------
$mailerPath = $ROOT . '/app/support/mailer.php';
if (is_readable($mailerPath)) {
    /** @noinspection PhpIncludeInspection */
    require_once $mailerPath;
} else {
    // If you move mailer.php, update this path.
    fwrite(STDERR, "ERROR: mailer.php not found at {$mailerPath}\n");
    exit(1);
}

// Include email hook controller functions so we can reuse limit helpers if present.
$hooksControllerPath = $ROOT . '/app/controllers/email_hooks_controller.php';
if (is_readable($hooksControllerPath)) {
    /** @noinspection PhpIncludeInspection */
    require_once $hooksControllerPath;
}

// ------------------------------------------------------------
// Small helpers
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
    function pf_extract_email(string $fromHeader): string
    {
        // Handles "Name <email@domain>" or plain email
        if (preg_match('/<([^>]+)>/', $fromHeader, $m)) {
            return strtolower(trim($m[1]));
        }
        return strtolower(trim($fromHeader));
    }
}

if (!function_exists('pf_mail_channel_for_to')) {
    function pf_mail_channel_for_to(string $to): array
    {
        $toLower = strtolower($to);
        if (str_contains($toLower, 'scamcheck@')) { return ['mode' => 'scamcheck', 'check_channel' => 'email-scamcheck', 'email_channel' => 'scamcheck']; }
        if (str_contains($toLower, 'clarify@'))   { return ['mode' => 'clarify',   'check_channel' => 'email-clarify',   'email_channel' => 'clarify']; }
        return ['mode' => 'generic', 'check_channel' => 'email', 'email_channel' => 'noreply'];
    }
}

if (!function_exists('pf_queue_position')) {
    function pf_queue_position(PDO $pdo, int $newId): int
    {
        // Number of queued/processing items ahead of this one (strict FIFO)
        $stmt = $pdo->prepare('
            SELECT COUNT(*) AS c
            FROM email_queue
            WHERE status IN ("queued","processing")
              AND id < :id
        ');
        $stmt->execute([':id' => $newId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }
}

if (!function_exists('pf_send_ack_email')) {
    function pf_send_ack_email(string $toEmail, string $mode, int $posAhead, int $throughputPerMin): void
    {
        if (!function_exists('pf_send_email')) {
            error_log('pf_send_email not available; cannot send ack.');
            return;
        }

        $position = $posAhead + 1;
        $mins = max(1, (int)ceil($position / max(1, $throughputPerMin)));

        $subject = 'Plainfully: received — we’re on it';

        $inner =
            '<p>Hello,</p>' .
            '<p>We’ve received your message and added it to the queue.</p>' .
            '<p><strong>Position:</strong> #' . htmlspecialchars((string)$position, ENT_QUOTES, 'UTF-8') . '</p>' .
            '<p><strong>Estimated time:</strong> ~' . htmlspecialchars((string)$mins, ENT_QUOTES, 'UTF-8') . ' minute' . ($mins === 1 ? '' : 's') . '</p>' .
            '<p style="color:#6b7280;font-size:13px;margin:16px 0 0;">' .
            'We’ll email you again as soon as your result is ready.' .
            '</p>';

        $html = function_exists('pf_email_template')
            ? pf_email_template($subject, $inner)
            : $inner;

        $text =
            "Hello,\n\n" .
            "We've received your message and added it to the queue.\n" .
            "Position: #{$position}\n" .
            "Estimated time: ~{$mins} minute" . ($mins === 1 ? '' : 's') . "\n\n" .
            "We'll email you again as soon as your result is ready.\n";

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
            '<p style="color:#6b7280;font-size:13px;margin:16px 0 0;">' .
            'Please resend your message a little later.' .
            '</p>';

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

$start = time();
$loops = 0;

while (true) {
    $loops++;
    if ((time() - $start) >= $maxRuntime) {
        break;
    }

    $inbox = @imap_open($mailbox, $user, $pass, 0, 1);
    if ($inbox === false) {
        error_log('IMAP open failed: ' . (string)imap_last_error());
        sleep($pollSeconds);
        continue;
    }

    // Search UNSEEN messages
    $emails = imap_search($inbox, 'UNSEEN');
    if ($emails === false) {
        // nothing
        imap_close($inbox);
        sleep($pollSeconds);
        continue;
    }

    // Ensure oldest-first
    sort($emails);

    $pdo = null;
    try { $pdo = pf_db(); } catch (Throwable $e) { $pdo = null; }

    foreach ($emails as $msgno) {
        try {
            $overview = imap_fetch_overview($inbox, (string)$msgno, 0);
            $ov = is_array($overview) && isset($overview[0]) ? $overview[0] : null;

            $fromHeader = is_object($ov) && isset($ov->from) ? (string)$ov->from : '';
            $toHeader   = is_object($ov) && isset($ov->to) ? (string)$ov->to : '';
            $subject    = is_object($ov) && isset($ov->subject) ? (string)$ov->subject : '';

            $fromEmail  = pf_extract_email($fromHeader);
            $toEmail    = pf_extract_email($toHeader);

            $chan = pf_mail_channel_for_to($toEmail);
            $mode = $chan['mode'];

            // Maintenance: reply + delete without storing
            if ($maintenance) {
                pf_send_maintenance_email($fromEmail, $mode, $maintMsg);
                imap_delete($inbox, (string)$msgno);
                continue;
            }

            // Limit check (reuse helpers if present). If not present, fail-open.
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

            // Fetch best-effort body (plain first; fallback to full body)
            $body = (string)imap_body($inbox, (string)$msgno, FT_PEEK);
            if ($body === '') {
                $body = (string)imap_fetchbody($inbox, (string)$msgno, '1', FT_PEEK);
            }

            if (strlen($body) > $maxBytes) {
                $body = substr($body, 0, $maxBytes);
            }

            if (!$pdo instanceof PDO) {
                throw new RuntimeException('DB unavailable (pf_db failed).');
            }

            // Insert into queue
            $stmt = $pdo->prepare('
                INSERT INTO email_queue
                    (mode, from_email, to_email, subject, body, status, attempts, last_error, available_at, created_at)
                VALUES
                    (:mode, :from_email, :to_email, :subject, :body, "queued", 0, NULL, NOW(), NOW())
            ');

            $stmt->execute([
                ':mode'       => $mode,
                ':from_email' => $fromEmail,
                ':to_email'   => $toEmail,
                ':subject'    => $subject,
                ':body'       => $body,
            ]);

            $newId = (int)$pdo->lastInsertId();
            $ahead = pf_queue_position($pdo, $newId);

            // Ack email (best-effort)
            pf_send_ack_email($fromEmail, $mode, $ahead, $throughput);

            // Delete original email
            imap_delete($inbox, (string)$msgno);

        } catch (Throwable $e) {
            error_log('Poller message failed: ' . $e->getMessage());
            // Mark seen to prevent infinite loops if body parsing is broken
            @imap_setflag_full($inbox, (string)$msgno, "\\Seen");
        }
    }

    imap_expunge($inbox);
    imap_close($inbox);

    sleep($pollSeconds);
}

echo "OK poller finished. loops={$loops}\n";
