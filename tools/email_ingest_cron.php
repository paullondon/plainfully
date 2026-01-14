<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Email Ingest (Cron-friendly)
 * ============================================================
 * Purpose:
 *  - Poll IMAP for UNSEEN emails
 *  - Normalize -> insert into pf_inbound_queue (status=new)
 *  - Mark seen or delete (config)
 *
 * Scheduled task:
 *  - Run every 1 minute
 *
 * Env required:
 *  - PF_IMAP_HOST
 *  - PF_IMAP_USER
 *  - PF_IMAP_PASS
 *
 * Env optional:
 *  - PF_IMAP_PORT=993
 *  - PF_IMAP_ENCRYPTION=ssl   (ssl|tls|none)
 *  - PF_IMAP_MAILBOX=INBOX
 *  - PF_IMAP_MAX_SECONDS=50
 *  - PF_IMAP_MAX_EMAILS=20
 *  - PF_IMAP_ACTION=seen      (seen|delete)
 *  - PF_EMAIL_CHANNEL=email-clarify
 */

require_once __DIR__ . '/../app/bootstrap.php';

final class PfEmailIngestCron
{
    private string $host;
    private string $user;
    private string $pass;
    private int    $port;
    private string $enc;
    private string $mailbox;
    private int    $maxSeconds;
    private int    $maxEmails;
    private string $action;
    private string $channel;

    public function __construct()
    {
        $this->host = (string)pf_env('PF_IMAP_HOST', '');
        $this->user = (string)pf_env('PF_IMAP_USER', '');
        $this->pass = (string)pf_env('PF_IMAP_PASS', '');

        $this->port = (int)(pf_env('PF_IMAP_PORT', '993') ?? '993');
        if ($this->port < 1) { $this->port = 993; }

        $this->enc = strtolower((string)pf_env('PF_IMAP_ENCRYPTION', 'ssl'));
        if (!in_array($this->enc, ['ssl','tls','none'], true)) { $this->enc = 'ssl'; }

        $this->mailbox = (string)pf_env('PF_IMAP_MAILBOX', 'INBOX');
        if ($this->mailbox === '') { $this->mailbox = 'INBOX'; }

        $this->maxSeconds = $this->clampInt((int)(pf_env('PF_IMAP_MAX_SECONDS', '50') ?? '50'), 5, 55);
        $this->maxEmails  = $this->clampInt((int)(pf_env('PF_IMAP_MAX_EMAILS', '20') ?? '20'), 1, 200);

        $this->action = strtolower((string)pf_env('PF_IMAP_ACTION', 'seen'));
        if (!in_array($this->action, ['seen','delete'], true)) { $this->action = 'seen'; }

        $this->channel = (string)pf_env('PF_EMAIL_CHANNEL', 'email-clarify');
        if ($this->channel === '') { $this->channel = 'email-clarify'; }
    }

    public function run(): int
    {
        if (!function_exists('imap_open')) {
            pf_log('error', 'IMAP extension not available (imap_open missing)', []);
            fwrite(STDERR, "IMAP extension not available.\n");
            return 0;
        }

        if ($this->host === '' || $this->user === '' || $this->pass === '') {
            pf_log('error', 'Email ingest missing env (PF_IMAP_HOST/USER/PASS)', []);
            fwrite(STDERR, "Missing IMAP env vars.\n");
            return 0;
        }

        $start = microtime(true);
        $processed = 0;

        $mailboxStr = $this->buildMailboxString();

        $inbox = @imap_open($mailboxStr, $this->user, $this->pass, 0, 1, [
            'DISABLE_AUTHENTICATOR' => 'GSSAPI',
        ]);

        if ($inbox === false) {
            $err = (string)imap_last_error();
            pf_log('error', 'IMAP open failed', ['err' => $err]);
            fwrite(STDERR, "IMAP open failed: {$err}\n");
            return 0;
        }

        try {
            // UNSEEN only (fast + safe)
            $emails = imap_search($inbox, 'UNSEEN', SE_UID);

            if (!is_array($emails) || empty($emails)) {
                echo "Email ingest complete. processed=0\n";
                return 0;
            }

            // Process oldest first (FIFO-ish)
            sort($emails, SORT_NUMERIC);

            foreach ($emails as $uid) {
                if ($processed >= $this->maxEmails) { break; }
                if ((microtime(true) - $start) >= $this->maxSeconds) { break; }

                $did = $this->ingestOne($inbox, (int)$uid);
                if ($did) { $processed++; }
            }

            // Expunge deletes if configured
            if ($this->action === 'delete') {
                @imap_expunge($inbox);
            }

            echo "Email ingest complete. processed={$processed}\n";
            pf_log('info', 'Email ingest complete', ['processed' => $processed]);

            return $processed;
        } catch (Throwable $e) {
            $ref = bin2hex(random_bytes(6));
            pf_log('error', 'Email ingest crashed', ['ref' => $ref, 'err' => $e->getMessage()]);
            fwrite(STDERR, "Email ingest crashed (ref {$ref})\n");
            return $processed;
        } finally {
            @imap_close($inbox);
        }
    }

    private function ingestOne($inbox, int $uid): bool
    {
        // Fetch headers + structure
        $overviewArr = imap_fetch_overview($inbox, (string)$uid, FT_UID);
        if (!is_array($overviewArr) || empty($overviewArr)) {
            return false;
        }
        $ov = $overviewArr[0];

        $subject = isset($ov->subject) ? imap_utf8((string)$ov->subject) : '';
        $from    = isset($ov->from) ? imap_utf8((string)$ov->from) : '';
        // --- Loop prevention: never ingest our own outbound ---
        $blockFrom = strtolower(trim((string)pf_env('PF_EMAIL_INGEST_BLOCK_FROM', 'no-reply@plainfully.com')));
        $fromLower = strtolower($from);

        // crude but effective: if our outbound address appears anywhere in From, skip it
        if ($blockFrom !== '' && $fromLower !== '' && str_contains($fromLower, $blockFrom)) {
            // mark handled so it doesn't keep appearing
            if ($this->action === 'delete') {
                @imap_delete($inbox, (string)$uid, FT_UID);
            } else {
                @imap_setflag_full($inbox, (string)$uid, "\\Seen", ST_UID);
            }
            pf_log('info', 'Email ingest skipped (blocked From)', ['uid' => $uid, 'from' => $from]);
            return false;
        }

        $date    = isset($ov->date) ? (string)$ov->date : '';

        $messageId = '';
        if (isset($ov->message_id)) {
            $messageId = trim((string)$ov->message_id);
        }

        // Body (prefer text/plain, fallback to stripped html)
        $text = $this->getBestBody($inbox, $uid);
        $text = trim($text);

        if ($text === '' && $subject === '') {
            $text = '(empty email)';
        }

        // Trace id stable-ish from message-id if present, else random
        $traceId = $this->makeTraceId($messageId);

        // Write inbound queue
        $payload = [
            'email' => [
                'from'       => $from,
                'subject'    => $subject,
                'date'       => $date,
                'message_id' => $messageId,
                'uid'        => $uid,
                'mailbox'    => $this->mailbox,
            ],
            'text' => $text,
            'meta' => [
                'received_at' => gmdate('c'),
                'source'      => 'imap',
            ],
        ];

        try {
            $pdo = pf_pdo();
            $stmt = $pdo->prepare("
                INSERT INTO pf_inbound_queue (trace_id, channel, payload_json, status, attempts, available_at, created_at)
                VALUES (:trace_id, :channel, :payload_json, 'new', 0, NOW(), NOW())
            ");
            $stmt->execute([
                ':trace_id'     => $traceId,
                ':channel'      => $this->channel,
                ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            // If trace_id collides (rare) or DB error, fall back to random trace id once
            $fallback = $this->randomTraceId();
            try {
                $pdo = pf_pdo();
                $stmt = $pdo->prepare("
                    INSERT INTO pf_inbound_queue (trace_id, channel, payload_json, status, attempts, available_at, created_at)
                    VALUES (:trace_id, :channel, :payload_json, 'new', 0, NOW(), NOW())
                ");
                $stmt->execute([
                    ':trace_id'     => $fallback,
                    ':channel'      => $this->channel,
                    ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
                $traceId = $fallback;
            } catch (Throwable $e2) {
                pf_log('error', 'Email ingest DB insert failed', ['err' => $e2->getMessage()]);
                return false;
            }
        }

        // Mark message handled
        if ($this->action === 'delete') {
            @imap_delete($inbox, (string)$uid, FT_UID);
        } else {
            @imap_setflag_full($inbox, (string)$uid, "\\Seen", ST_UID);
        }

        pf_log('info', 'Email ingested', ['trace_id' => $traceId, 'uid' => $uid]);
        return true;
    }

    private function getBestBody($inbox, int $uid): string
    {
        $structure = imap_fetchstructure($inbox, (string)$uid, FT_UID);
        if (!is_object($structure)) {
            $raw = imap_body($inbox, (string)$uid, FT_UID);
            return is_string($raw) ? $raw : '';
        }

        // Single-part
        if (!isset($structure->parts) || !is_array($structure->parts)) {
            $raw = imap_body($inbox, (string)$uid, FT_UID);
            return is_string($raw) ? $this->decodePart($raw, (int)($structure->encoding ?? 0)) : '';
        }

        // Multi-part: try text/plain first, then text/html
        $plain = $this->findPart($inbox, $uid, $structure, 'TEXT/PLAIN');
        if ($plain !== '') {
            return $plain;
        }

        $html = $this->findPart($inbox, $uid, $structure, 'TEXT/HTML');
        if ($html !== '') {
            return trim(strip_tags($html));
        }

        // Fallback: whole body
        $raw = imap_body($inbox, (string)$uid, FT_UID);
        return is_string($raw) ? $raw : '';
    }

    private function findPart($inbox, int $uid, object $structure, string $mime): string
    {
        $parts = $structure->parts ?? [];
        $i = 1;

        foreach ($parts as $part) {
            $partMime = $this->mimeType($part);
            if ($partMime === $mime) {
                $raw = imap_fetchbody($inbox, (string)$uid, (string)$i, FT_UID);
                if (!is_string($raw)) { return ''; }
                $decoded = $this->decodePart($raw, (int)($part->encoding ?? 0));
                return trim($decoded);
            }
            $i++;
        }

        return '';
    }

    private function mimeType(object $part): string
    {
        $primary = (int)($part->type ?? 0);
        $sub     = strtoupper((string)($part->subtype ?? ''));

        // Common: type 0 = text
        $map = [
            0 => 'TEXT',
            1 => 'MULTIPART',
            2 => 'MESSAGE',
            3 => 'APPLICATION',
            4 => 'AUDIO',
            5 => 'IMAGE',
            6 => 'VIDEO',
            7 => 'OTHER',
        ];

        $p = $map[$primary] ?? 'OTHER';
        return $p . '/' . $sub;
    }

    private function decodePart(string $raw, int $encoding): string
    {
        return match ($encoding) {
            3 => base64_decode($raw, true) ?: '',
            4 => quoted_printable_decode($raw),
            default => $raw,
        };
    }

    private function buildMailboxString(): string
    {
        $enc = '';
        if ($this->enc === 'ssl') { $enc = '/imap/ssl'; }
        elseif ($this->enc === 'tls') { $enc = '/imap/tls'; }
        else { $enc = '/imap/notls'; }

        return '{' . $this->host . ':' . $this->port . $enc . '}' . $this->mailbox;
    }

    private function makeTraceId(string $messageId): string
    {
        if ($messageId !== '') {
            // Deterministic-ish trace id from message id (keeps duplicate spam grouped)
            $h = hash('sha256', strtolower($messageId));
            return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
        }
        return $this->randomTraceId();
    }

    private function randomTraceId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }

    private function clampInt(int $v, int $min, int $max): int
    {
        if ($v < $min) return $min;
        if ($v > $max) return $max;
        return $v;
    }
}

(new PfEmailIngestCron())->run();