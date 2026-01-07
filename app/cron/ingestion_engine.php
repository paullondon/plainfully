<?php declare(strict_types=1);
/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: httpdocs/app/cron/ingestion_engine.php
 * Purpose:
 *   IMAP poller that:
 *    - pulls UNSEEN emails
 *    - extracts body + attachments (safe allowlist)
 *    - builds a normalized “Text Package” for the worker
 *    - inserts into inbound_queue (with trace_id)
 *    - sends a fast acknowledgement email
 *    - deletes the original email (GDPR + idempotency)
 *
 * IMPORTANT (architecture):
 *   - This script loads bootstrap/app.php and NOTHING else.
 *   - bootstrap/includes.php loads all helpers/support/controllers.
 * ============================================================
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); echo "CLI only.
"; exit(1); }

// Always use UTC for cron consistency
date_default_timezone_set('UTC');

// ------------------------------------------------------------
// Bootstrap (must be FIRST include)
// ------------------------------------------------------------
require_once __DIR__ . '/../../bootstrap/app.php';

// Safety: verify bootstrap was loaded (helps debugging)
if (!defined('PF_BOOTSTRAPPED')) { fwrite(STDERR, "ERROR: bootstrap not loaded.
"); exit(1); }

// ------------------------------------------------------------
// Small local helpers (safe, no side-effects)
// ------------------------------------------------------------
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
    /**
     * NOTE: Channel is now tagging only; outbound mail always uses no-reply@ + hello@ reply-to.
     */
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
            $v = trim($v, " 	
<>");
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
        $txt = preg_replace("/
|/", "
", (string)$txt);
        $txt = preg_replace("/
{3,}/", "

", (string)$txt);
        return trim((string)$txt);
    }
}

if (!function_exists('pf_build_text_package')) {
    function pf_build_text_package(string $bodyText, array $attachments): string
    {
        $out = [];
        if (trim($bodyText) !== '') { $out[] = "EMAIL BODY
" . $bodyText; }

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

        return trim(implode("

---

", $out));
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
            "Hello

" .
            "Thanks — we’ve received your message and it’s in our system.
" .
            "We’ll email your Plainfully result to this address as soon as it’s ready.

" .
            "When you open the result link, we’ll ask you to confirm your email address before you can view it.

" .
            "No action needed right now.
";

        $channel = ($mode === 'clarify') ? 'clarify' : (($mode === 'scamcheck') ? 'scamcheck' : 'noreply');

        [$ok, $err] = pf_send_email($toEmail, $subject, $html, $channel, $text);
        if (!$ok) {
            error_log('[ingest] ACK send failed to=' . $toEmail . ' err=' . ($err ?? 'unknown'));
        }
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
            "Hello

" .
            $msg . "

" .
            "Supported types: PDF, TXT, JPG/PNG. (No ZIPs, no password-protected files.)
";

        $channel = ($mode === 'clarify') ? 'clarify' : (($mode === 'scamcheck') ? 'scamcheck' : 'noreply');

        [$ok, $err] = pf_send_email($toEmail, $subject, $html, $channel, $text);
        if (!$ok) {
            error_log('[ingest] reject-email send failed to=' . $toEmail . ' err=' . ($err ?? 'unknown'));
        }
    }
}

// ------------------------------------------------------------
// IMAP polling loop
// ------------------------------------------------------------
$mailbox = pf_env_str('EMAIL_IMAP_MAILBOX');
$user    = pf_env_str('EMAIL_IMAP_USER');
$pass    = pf_env_str('EMAIL_IMAP_PASS');

if ($mailbox === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "ERROR: Missing EMAIL_IMAP_MAILBOX/USER/PASS.
");
    exit(2);
}
if (!function_exists('imap_open')) {
    fwrite(STDERR, "ERROR: PHP IMAP extension not installed/enabled.
");
    exit(3);
}

$pollSeconds   = max(1, pf_env_int('EMAIL_POLL_SECONDS', 5));
$maxRuntime    = max(10, pf_env_int('EMAIL_POLL_MAX_RUNTIME_SECONDS', 55));
$maxBytesBody  = max(1000, pf_env_int('EMAIL_QUEUE_MAX_BYTES', 200000));

$maxFiles      = max(1, pf_env_int('EMAIL_ATTACH_MAX_FILES', 5));
$maxTotalBytes = max(1024, pf_env_int('EMAIL_ATTACH_MAX_TOTAL_BYTES', 10 * 1024 * 1024));
$maxFileBytes  = max(1024, pf_env_int('EMAIL_ATTACH_MAX_FILE_BYTES', 5 * 1024 * 1024));

$maintenance = function_exists('pf_env_bool') ? pf_env_bool('PLAINFULLY_MAINTENANCE', false) : false;
$mailboxName = pf_parse_mailbox_name($mailbox);

$start = time();
$loops = 0;

error_log('[ingest] start mailbox=' . $mailboxName);

while (true) {
    $loops++;
    if ((time() - $start) >= $maxRuntime) { break; }

    $inbox = @imap_open($mailbox, $user, $pass, 0, 1);
    if ($inbox === false) {
        error_log('[ingest] IMAP open failed: ' . (string)imap_last_error());
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
    error_log('[ingest] unseen_count=' . count($emails));

    $pdo = pf_db();
    if (!($pdo instanceof PDO)) {
        error_log('[ingest] DB not available');
        imap_close($inbox);
        sleep($pollSeconds);
        continue;
    }

    foreach ($emails as $msgno) {
        $msgno = (int)$msgno;
        $traceId = function_exists('pf_trace_new_id') ? pf_trace_new_id() : bin2hex(random_bytes(8));

        try {
            if (function_exists('pf_trace')) {
                pf_trace($pdo, $traceId, 'ingest', 'info', false, 'seen', 'Found unseen email', [
                    'mailbox' => $mailboxName,
                    'msgno' => $msgno,
                ]);
            }

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
                if (function_exists('pf_trace')) {
                    pf_trace($pdo, $traceId, 'ingest', 'warn', true, 'maintenance', 'Rejected (maintenance)', ['mode'=>$mode]);
                }
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

            if (function_exists('pf_trace')) {
                pf_trace($pdo, $traceId, 'ingest', 'info', false, 'body', 'Body extracted', [
                    'mode' => $mode,
                    'raw_is_html' => $rawIsHtml ? 1 : 0,
                    'body_chars' => mb_strlen($bodyText, 'UTF-8'),
                ]);
            }

            if (!function_exists('pf_imap_extract_attachments')) {
                throw new RuntimeException('pf_imap_extract_attachments() missing – ensure imap_attachments.php is included by bootstrap.');
            }

            $ing = pf_imap_extract_attachments($inbox, $msgno, [
                'max_files'       => $maxFiles,
                'max_total_bytes' => $maxTotalBytes,
                'max_file_bytes'  => $maxFileBytes,
                'allow_ext'       => ['pdf','txt','png','jpg','jpeg'], // LOCKED
            ]);

            if (($ing['ok'] ?? false) !== true) {
                $reason = (string)($ing['reason'] ?? 'generic');

                if (function_exists('pf_trace')) {
                    pf_trace($pdo, $traceId, 'attachments', 'warn', true, 'reject', 'Attachment ingest rejected', [
                        'reason' => $reason,
                        'total_attachments' => (int)($ing['total_attachments'] ?? 0),
                        'total_bytes' => (int)($ing['total_bytes'] ?? 0),
                    ]);
                }

                pf_send_ingest_failed_email($fromEmail, $mode, $reason);
                imap_delete($inbox, (string)$msgno);
                continue;
            }

            $attachments = (array)($ing['attachments'] ?? []);
            $hasAttachments = !empty($attachments);

            foreach ($attachments as $a) {
                if (is_array($a) && (($a['needs_ocr'] ?? false) === true)) {
                    if (function_exists('pf_trace')) {
                        pf_trace($pdo, $traceId, 'attachments', 'warn', true, 'ocr_block', 'OCR required (not enabled)', [
                            'filename' => (string)($a['filename'] ?? ''),
                            'kind' => (string)($a['kind'] ?? ''),
                        ]);
                    }

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

            pf_send_ack_email($fromEmail, $mode);
            imap_delete($inbox, (string)$msgno);

        } catch (Throwable $e) {
            error_log('[ingest] message failed: ' . $e->getMessage());
            if (function_exists('pf_trace')) {
                pf_trace($pdo, $traceId, 'ingest', 'error', true, 'exception', 'Poller exception', [
                    'err' => substr($e->getMessage(), 0, 300),
                ]);
            }
            @imap_setflag_full($inbox, (string)$msgno, "\\Seen");
        }
    }

    imap_expunge($inbox);
    imap_close($inbox);

    sleep($pollSeconds);
}

error_log('[ingest] end loops=' . $loops);
echo "OK poller finished. loops={$loops}
";
