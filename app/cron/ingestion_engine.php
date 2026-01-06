<?php declare(strict_types=1);
/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: httpdocs/tools/queue_ingest_loop.php
 * Purpose:
 *   IMAP poller that:
 *    - pulls UNSEEN emails
 *    - extracts body + attachments (safe allowlist)
 *    - builds a normalized “Text Package” for the worker
 *    - inserts into inbound_queue (with trace_id)
 *    - sends a fast acknowledgement email
 *    - deletes the original email (GDPR + idempotency)
 *
 * Attachment MVP rules (LOCKED):
 *   - Allowlist only: pdf, txt, png, jpg, jpeg
 *   - Max 5 attachments
 *   - Max 10MB total, max 5MB per file
 *   - If any attachment needs OCR => reject (OCR pipeline not enabled in poller)
 *
 * Trace timeline:
 *   - trace_id per email
 *   - pf_trace() writes metadata (not raw content) if PLAINFULLY_TRACE=1
 * ============================================================
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }

date_default_timezone_set('UTC');

$ROOT = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');

require_once $ROOT . '/support/email_templates.php';
require_once $ROOT . '/support/imap_attachments.php';
require_once $ROOT . '/support/trace.php';
require_once $ROOT . '/support/db.php';

$mailerPath = $ROOT . '/support/mailer.php';
if (!is_readable($mailerPath)) { fwrite(STDERR, "ERROR: mailer.php not found at {$mailerPath}\n"); exit(1); }
require_once $mailerPath;

// Optional normaliser helpers
$hooksControllerPath = $ROOT . '/controllers/email_hooks_controller.php';
if (is_readable($hooksControllerPath)) { require_once $hooksControllerPath; }

// ------------------------------------------------------------
// Small helpers
// ------------------------------------------------------------
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
            $k = trim($k); $v = trim($v);
            if (
                (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                (str_starts_with($v, "'") && str_ends_with($v, "'"))
            ) { $v = substr($v, 1, -1); }
            if ($k !== '' && getenv($k) === false) { putenv($k . '=' . $v); $_ENV[$k] = $v; }
        }
    }
}
pf_load_env_file($ROOT . '/.env');

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
        return in_array(strtolower((string)$v), ['1','true','yes','on'], true);
    }
}
if (!function_exists('pf_extract_email')) {
    function pf_extract_email(string $header): string
    {
        if (preg_match('/<([^>]+)>/', $header, $m)) { return strtolower(trim($m[1])); }
        return strtolower(trim($header));
    }
}
if (!function_exists('pf_parse_mailbox_name')) {
    function pf_parse_mailbox_name(string $mailbox): string
    {
        $pos = strrpos($mailbox, '}');
        if ($pos === false) { return $mailbox !== '' ? $mailbox : 'INBOX'; }
        $name = trim(substr($mailbox, $pos + 1));
        return $name !== '' ? $name : 'INBOX';
    }
}
if (!function_exists('pf_mail_channel_for_to')) {
    function pf_mail_channel_for_to(string $to): array
    {
        $toLower = strtolower($to);
        if (str_contains($toLower, 'clarify@') || str_contains($toLower, 'hello@')) {
            return ['mode' => 'clarify', 'email_channel' => 'clarify'];
        }
        if (str_contains($toLower, 'scamcheck@')) { return ['mode' => 'scamcheck', 'email_channel' => 'scamcheck']; }
        return ['mode' => 'generic', 'email_channel' => 'noreply'];
    }
}
if (!function_exists('pf_extract_message_id')) {
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
if (!function_exists('pf_clean_email_body_to_text')) {
    function pf_clean_email_body_to_text(string $rawBody, bool $isHtml): string
    {
        $rawBody = trim($rawBody);
        if ($rawBody === '') { return ''; }

        if (function_exists('pf_normalise_inbound_email_text')) {
            try { return (string)pf_normalise_inbound_email_text($rawBody, $isHtml); } catch (Throwable $e) { /* ignore */ }
        }

        $txt = $isHtml ? strip_tags($rawBody) : $rawBody;
        $txt = preg_replace("/\r\n|\r/", "\n", (string)$txt);
        $txt = preg_replace("/\n{3,}/", "\n\n", (string)$txt);
        return trim((string)$txt);
    }
}
if (!function_exists('pf_build_text_package')) {
    function pf_build_text_package(string $bodyText, array $attachments): string
    {
        $out = [];
        if (trim($bodyText) !== '') { $out[] = "EMAIL BODY\n" . $bodyText; }

        foreach ($attachments as $i => $a) {
            if (!is_array($a)) { continue; }
            $fn = (string)($a['filename'] ?? ('attachment_' . ($i + 1)));
            $kind = (string)($a['kind'] ?? 'other');

            $text = (string)($a['extracted_text'] ?? '');
            $needsOcr = (bool)($a['needs_ocr'] ?? false);

            $out[] = "ATTACHMENT " . ($i + 1) . " — {$fn} ({$kind})";

            if ($text !== '') {
                $out[] = $text;
            } elseif ($needsOcr) {
                $out[] = "[OCR REQUIRED] Attachment looks like an image/scanned document.";
            } else {
                $note = (string)($a['notes'] ?? '');
                $out[] = $note !== '' ? "[NO TEXT] {$note}" : "[NO TEXT] Could not extract text.";
            }
        }

        return trim(implode("\n\n---\n\n", $out));
    }
}
if (!function_exists('pf_send_ack_email')) {
    function pf_send_ack_email(string $toEmail, string $mode): void
    {
        if (!function_exists('pf_send_email')) { return; }

        $subject = 'Plainfully — received (we’ve got it)';

        $inner =
            '<p>Hello,</p>' .
            '<p>Thanks — we’ve received your message and it’s in our system.</p>' .
            '<p>We’ll email your Plainfully result to this address as soon as it’s ready.</p>' .
            '<p style="color:#6b7280;font-size:13px;margin:16px 0 0;">' .
                'When you open the result link, we’ll ask you to confirm your email address before you can view it.' .
            '</p>' .
            '<p style="color:#6b7280;font-size:13px;margin:12px 0 0;">No action needed right now.</p>';

        $html = function_exists('pf_email_template') ? pf_email_template($subject, $inner) : $inner;

        $text =
            "Hello\n\n" .
            "Thanks — we’ve received your message and it’s in our system.\n" .
            "We’ll email your Plainfully result to this address as soon as it’s ready.\n\n" .
            "When you open the result link, we’ll ask you to confirm your email address before you can view it.\n\n" .
            "No action needed right now.\n";

        $channel = ($mode === 'clarify') ? 'clarify' : (($mode === 'scamcheck') ? 'scamcheck' : 'noreply');
        pf_send_email($toEmail, $subject, $html, $channel, $text);
    }
}
if (!function_exists('pf_send_ingest_failed_email')) {
    function pf_send_ingest_failed_email(string $toEmail, string $mode, string $reasonKey): void
    {
        if (!function_exists('pf_send_email')) { return; }

        $subject = 'Plainfully — we couldn’t process that email';

        $reasons = [
            'too_many_attachments' => 'Too many attachments. Please resend with up to 5 attachments.',
            'unsupported_attachment_type' => 'One of the attachments is a file type we don’t support yet.',
            'attachment_too_large' => 'One attachment was too large. Please resend with smaller files (max 5MB each).',
            'attachments_total_too_large' => 'The total attachment size was too large. Please keep total under 10MB.',
            'ocr_required' => 'We received an image/scanned document. OCR upload is not enabled yet.',
            'generic' => 'We couldn’t safely process the attachments in that email.',
        ];

        $msg = $reasons[$reasonKey] ?? $reasons['generic'];

        $inner =
            '<p>Hello,</p>' .
            '<p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>' .
            '<p style="color:#6b7280;font-size:13px;margin:16px 0 0;">' .
            'Supported types: PDF, TXT, JPG/PNG. (No ZIPs, no password-protected files.)' .
            '</p>';

        $html = function_exists('pf_email_template') ? pf_email_template($subject, $inner) : $inner;

        $text =
            "Hello\n\n" .
            $msg . "\n\n" .
            "Supported types: PDF, TXT, JPG/PNG. (No ZIPs, no password-protected files.)\n";

        $channel = ($mode === 'clarify') ? 'clarify' : (($mode === 'scamcheck') ? 'scamcheck' : 'noreply');
        pf_send_email($toEmail, $subject, $html, $channel, $text);
    }
}

// ------------------------------------------------------------
// IMAP polling loop
// ------------------------------------------------------------
$mailbox = (string)(getenv('EMAIL_IMAP_MAILBOX') ?: '');
$user    = (string)(getenv('EMAIL_IMAP_USER') ?: '');
$pass    = (string)(getenv('EMAIL_IMAP_PASS') ?: '');

if ($mailbox === '' || $user === '' || $pass === '') { fwrite(STDERR, "ERROR: Missing EMAIL_IMAP_MAILBOX/USER/PASS.\n"); exit(2); }
if (!function_exists('imap_open')) { fwrite(STDERR, "ERROR: PHP IMAP extension not installed/enabled.\n"); exit(3); }

$pollSeconds   = max(1, pf_env_int('EMAIL_POLL_SECONDS', 5));
$maxRuntime    = max(10, pf_env_int('EMAIL_POLL_MAX_RUNTIME_SECONDS', 55));
$maxBytesBody  = max(1000, pf_env_int('EMAIL_QUEUE_MAX_BYTES', 200000));

$maxFiles      = max(1, pf_env_int('EMAIL_ATTACH_MAX_FILES', 5));
$maxTotalBytes = max(1024, pf_env_int('EMAIL_ATTACH_MAX_TOTAL_BYTES', 10 * 1024 * 1024));
$maxFileBytes  = max(1024, pf_env_int('EMAIL_ATTACH_MAX_FILE_BYTES', 5 * 1024 * 1024));

$maintenance = pf_env_bool('PLAINFULLY_MAINTENANCE', false);
$mailboxName = pf_parse_mailbox_name($mailbox);

$start = time();
$loops = 0;

while (true) {
    $loops++;
    if ((time() - $start) >= $maxRuntime) { break; }

    $inbox = @imap_open($mailbox, $user, $pass, 0, 1);
    if ($inbox === false) { error_log('IMAP open failed: ' . (string)imap_last_error()); sleep($pollSeconds); continue; }

    $emails = imap_search($inbox, 'UNSEEN');
    if ($emails === false) { imap_close($inbox); sleep($pollSeconds); continue; }
    sort($emails);

    $pdo = pf_db();
    if (!($pdo instanceof PDO)) { imap_close($inbox); sleep($pollSeconds); continue; }

    foreach ($emails as $msgno) {
        $msgno = (int)$msgno;
        $traceId = pf_trace_new_id();

        try {
            pf_trace($pdo, $traceId, 'ingest', 'info', false, 'seen', 'Found unseen email', [
                'mailbox' => $mailboxName,
                'msgno' => $msgno,
            ]);

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
                pf_trace($pdo, $traceId, 'ingest', 'warn', true, 'maintenance', 'Rejected (maintenance)', ['mode'=>$mode]);
                imap_delete($inbox, (string)$msgno);
                continue;
            }

            $imapUid = @imap_uid($inbox, $msgno);
            $imapUid = is_int($imapUid) ? $imapUid : null;

            $rawHeaders = (string)@imap_fetchheader($inbox, $msgno, FT_PREFETCHTEXT);
            $messageId  = pf_extract_message_id($rawHeaders);

            $rawBody = (string)@imap_body($inbox, $msgno, FT_PEEK);
            if ($rawBody === '') { $rawBody = (string)@imap_fetchbody($inbox, $msgno, '1', FT_PEEK); }
            if (strlen($rawBody) > $maxBytesBody) { $rawBody = substr($rawBody, 0, $maxBytesBody); }

            $rawIsHtml = (
                stripos($rawHeaders, 'Content-Type: text/html') !== false ||
                stripos($rawBody, '<html') !== false ||
                stripos($rawBody, '<body') !== false
            );

            $bodyText = pf_clean_email_body_to_text($rawBody, (bool)$rawIsHtml);

            pf_trace($pdo, $traceId, 'ingest', 'info', false, 'body', 'Body extracted', [
                'mode' => $mode,
                'raw_is_html' => $rawIsHtml ? 1 : 0,
                'body_chars' => mb_strlen($bodyText, 'UTF-8'),
            ]);

            $ing = pf_imap_extract_attachments($inbox, $msgno, [
                'max_files'       => $maxFiles,
                'max_total_bytes' => $maxTotalBytes,
                'max_file_bytes'  => $maxFileBytes,
                'allow_ext'       => ['pdf','txt','png','jpg','jpeg'], // LOCKED
            ]);

            if (($ing['ok'] ?? false) !== true) {
                $reason = (string)($ing['reason'] ?? 'generic');

                pf_trace($pdo, $traceId, 'attachments', 'warn', true, 'reject', 'Attachment ingest rejected', [
                    'reason' => $reason,
                    'total_attachments' => (int)($ing['total_attachments'] ?? 0),
                    'total_bytes' => (int)($ing['total_bytes'] ?? 0),
                ]);

                pf_send_ingest_failed_email($fromEmail, $mode, $reason);
                imap_delete($inbox, (string)$msgno);
                continue;
            }

            $attachments = (array)($ing['attachments'] ?? []);
            $hasAttachments = !empty($attachments);

            pf_trace($pdo, $traceId, 'attachments', 'info', false, 'ok', 'Attachments extracted', [
                'count' => count($attachments),
                'total_bytes' => (int)($ing['total_bytes'] ?? 0),
            ]);

            foreach ($attachments as $a) {
                if (is_array($a) && (($a['needs_ocr'] ?? false) === true)) {
                    pf_trace($pdo, $traceId, 'attachments', 'warn', true, 'ocr_block', 'OCR required (not enabled)', [
                        'filename' => (string)($a['filename'] ?? ''),
                        'kind' => (string)($a['kind'] ?? ''),
                    ]);

                    pf_send_ingest_failed_email($fromEmail, $mode, 'ocr_required');
                    imap_delete($inbox, (string)$msgno);
                    continue 2;
                }
            }

            $normalisedText = pf_build_text_package($bodyText, $attachments);

            $ingestionJson = json_encode([
                'version' => 1,
                'trace_id' => $traceId,
                'source' => 'email',
                'mailbox' => $mailboxName,
                'message_id' => $messageId,
                'has_attachments' => $hasAttachments,
                'attachments' => array_map(static function($a) {
                    if (!is_array($a)) { return []; }
                    return [
                        'filename' => (string)($a['filename'] ?? ''),
                        'mime' => (string)($a['mime'] ?? ''),
                        'bytes' => (int)($a['bytes'] ?? 0),
                        'kind' => (string)($a['kind'] ?? ''),
                        'extract_method' => (string)($a['extract_method'] ?? ''),
                        'needs_ocr' => (bool)($a['needs_ocr'] ?? false),
                        'notes' => (string)($a['notes'] ?? ''),
                        'extracted_chars' => mb_strlen((string)($a['extracted_text'] ?? ''), 'UTF-8'),
                    ];
                }, $attachments),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (!is_string($ingestionJson) || $ingestionJson === '') { $ingestionJson = '{}'; }

            $receivedAt = null;
            if ($dateStr !== '') {
                try { $receivedAt = (new DateTimeImmutable($dateStr))->format('Y-m-d H:i:s'); }
                catch (Throwable $t) { $receivedAt = null; }
            }

            $stmt = $pdo->prepare('
                INSERT INTO inbound_queue
                    (trace_id, source, mailbox, imap_uid, message_id, from_email, to_email, subject, received_at, mode,
                     raw_body, raw_is_html, has_attachments, ingestion_json, normalised_text,
                     status, attempts, last_error)
                VALUES
                    (:trace_id, "email", :mailbox, :imap_uid, :message_id, :from_email, :to_email, :subject, :received_at, :mode,
                     :raw_body, :raw_is_html, :has_attachments, :ingestion_json, :normalised_text,
                     "queued", 0, NULL)
            ');

            $stmt->execute([
                ':trace_id'        => $traceId,
                ':mailbox'         => $mailboxName,
                ':imap_uid'        => $imapUid,
                ':message_id'      => $messageId,
                ':from_email'      => $fromEmail,
                ':to_email'        => $toEmail !== '' ? $toEmail : null,
                ':subject'         => $subject !== '' ? $subject : null,
                ':received_at'     => $receivedAt,
                ':mode'            => $mode,
                ':raw_body'        => $rawBody,
                ':raw_is_html'     => $rawIsHtml ? 1 : 0,
                ':has_attachments' => $hasAttachments ? 1 : 0,
                ':ingestion_json'  => $ingestionJson,
                ':normalised_text' => $normalisedText,
            ]);

            $queueId = (int)$pdo->lastInsertId();

            pf_trace($pdo, $traceId, 'ingest', 'info', false, 'db_insert', 'Inserted inbound_queue row', [
                'queue_id' => $queueId,
                'mode' => $mode,
                'has_attachments' => $hasAttachments ? 1 : 0,
                'normalised_chars' => mb_strlen($normalisedText, 'UTF-8'),
            ]);

            pf_send_ack_email($fromEmail, $mode);
            pf_trace($pdo, $traceId, 'output', 'info', false, 'ack', 'ACK sent', [
                'email_channel' => (string)($chan['email_channel'] ?? 'noreply'),
            ]);

            imap_delete($inbox, (string)$msgno);
            pf_trace($pdo, $traceId, 'cleanup', 'info', false, 'imap_delete', 'Deleted original email', [
                'imap_uid' => $imapUid,
                'message_id' => $messageId,
            ]);

        } catch (Throwable $e) {
            error_log('Poller message failed: ' . $e->getMessage());
            pf_trace($pdo, $traceId, 'ingest', 'error', true, 'exception', 'Poller exception', [
                'err' => substr($e->getMessage(), 0, 300),
            ]);
            @imap_setflag_full($inbox, (string)$msgno, "\\Seen");
        }
    }

    imap_expunge($inbox);
    imap_close($inbox);

    sleep($pollSeconds);
}

echo "OK poller finished. loops={$loops}\n";
