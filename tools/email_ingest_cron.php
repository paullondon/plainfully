<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Email Ingest (Cron-friendly)
 * ============================================================
 * Purpose:
 *  - Poll IMAP for ALL emails in clarify@plainfully.com inbox
 *  - Normalize -> insert into pf_inbound_queue (status=new)
 *  - Mark seen or delete (config)
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
 *  - PF_EMAIL_INGEST_BLOCK_FROM=no-reply@plainfully.com
 *  - PF_EMAIL_INGEST_DEBUG=0  (1 prints safe debug details to stdout)
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
    private string $blockFrom;
    private bool   $debug;

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

        $this->blockFrom = strtolower(trim((string)pf_env('PF_EMAIL_INGEST_BLOCK_FROM', 'no-reply@plainfully.com')));

        $this->debug = ((string)pf_env('PF_EMAIL_INGEST_DEBUG', '0') === '1');
    }

    public function run(): int
    {
        if (!function_exists('imap_open')) {
            $this->out("IMAP extension not available (imap_open missing).");
            pf_log('error', 'IMAP extension not available', []);
            return 0;
        }

        if ($this->host === '' || $this->user === '' || $this->pass === '') {
            $this->out("Missing IMAP env vars (PF_IMAP_HOST/USER/PASS).");
            pf_log('error', 'Email ingest missing env', []);
            return 0;
        }

        $start = microtime(true);
        $processed = 0;

        $mailboxStr = $this->buildMailboxString();

        // Clear any prior IMAP errors so we only see fresh ones
        if (function_exists('imap_errors')) { @imap_errors(); }

        $inbox = @imap_open($mailboxStr, $this->user, $this->pass, 0, 1, [
            'DISABLE_AUTHENTICATOR' => 'GSSAPI',
        ]);

        if ($inbox === false) {
            $err = (string)imap_last_error();
            $this->out("IMAP open failed: {$err}");
            pf_log('error', 'IMAP open failed', ['err' => $err, 'mailbox' => $this->safeMailbox()]);
            if ($this->debug) { $this->dumpImapErrors(); }
            return 0;
        }

        try {
            $emails = imap_search($inbox, 'ALL', SE_UID);

            if (!is_array($emails) || empty($emails)) {
                pf_log('info', 'Email ingest: no messages found', ['criteria' => 'ALL']);
                if ($this->debug) { $this->dumpImapErrors(); }
                return 0;
            }

            sort($emails, SORT_NUMERIC);

            foreach ($emails as $uid) {
                if ($processed >= $this->maxEmails) { break; }
                if ((microtime(true) - $start) >= $this->maxSeconds) { break; }

                $ok = $this->ingestOne($inbox, (int)$uid);

                if (!$ok) {
                    pf_log('error', 'Email ingest failed (left in inbox)', ['uid' => (int)$uid]);
                    if ($this->debug) { $this->dumpImapErrors(); }
                    continue;
                }

                $processed++;
            }


            $this->out("Email ingest complete. processed={$processed}");
            pf_log('info', 'Email ingest complete', ['processed' => $processed]);

            return $processed;

        } catch (Throwable $e) {
            $ref = bin2hex(random_bytes(6));
            pf_log('error', 'Email ingest crashed', [
                'ref' => $ref,
                'err' => $e->getMessage(),
                'mailbox' => $this->safeMailbox(),
            ]);

            $this->out("Email ingest crashed (ref {$ref})");

            if ($this->debug) {
                $this->out("DEBUG: " . $e->getMessage());
                $this->dumpImapErrors();
            }

            return $processed;

        } finally {
            @imap_expunge($inbox);
            @imap_close($inbox);
        }
    }

    private function ingestOne($inbox, int $uid): bool
    {
        $overviewArr = imap_fetch_overview($inbox, (string)$uid, FT_UID);
        if (!is_array($overviewArr) || empty($overviewArr)) {
            if ($this->debug) {
                $this->out("DEBUG: Missing overview for UID {$uid}");
                $this->dumpImapErrors();
            }
            return false;
        }

        $ov = $overviewArr[0];

        $subject = isset($ov->subject) ? imap_utf8((string)$ov->subject) : '';
        $from    = isset($ov->from) ? imap_utf8((string)$ov->from) : '';
        $date    = isset($ov->date) ? (string)$ov->date : '';

        $messageId = '';
        if (isset($ov->message_id)) {
            $messageId = trim((string)$ov->message_id);
        }

        // --- Loop prevention: never ingest our own outbound ---
        $fromLower = strtolower($from);
        if ($this->blockFrom !== '' && $fromLower !== '' && str_contains($fromLower, $this->blockFrom)) {
            $this->markHandled($inbox, $uid);
            pf_log('info', 'Email ingest skipped (blocked From)', ['uid' => $uid, 'from' => $from]);
            return false;
        }

        $text = trim($this->getBestBody($inbox, $uid));
        if ($text === '' && $subject === '') {
            $text = '(empty email)';
        }

        $traceId = $this->makeTraceId($messageId);

        // inbound payload
        $payload = [
            'email' => [
                'from'       => $from,
                'subject'    => $subject,
                'date'       => $date,
                'message_id' => $messageId,
                'uid'        => $uid,
                'mailbox'    => $this->mailbox ?? 'INBOX',
            ],

            // Processor reads these
            'text_parts'  => [$text],
            'attachments' => [],

            // Optional legacy
            'text' => $text,

            'meta' => [
                'received_at' => gmdate('c'),
                'source'      => 'imap',
            ],
        ];

        // Attachments (stored now, OCR/delete happens later in Processor)
        $payload['attachments'] = $this->extractAttachmentsToPayload($inbox, $uid, $traceId);

        // Insert into inbound queue
        $pdo = pf_pdo();
        $stmt = $pdo->prepare("
            INSERT INTO pf_inbound_queue (trace_id, channel, payload_json, status, attempts, available_at, created_at)
            VALUES (:trace_id, :channel, :payload_json, 'new', 0, NOW(), NOW())
        ");

        try {
            $stmt->execute([
                ':trace_id'     => $traceId,
                ':channel'      => $this->channel,
                ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            // One retry with a random trace id (collision / unique constraint / etc.)
            $traceId = $this->randomTraceId();
            $stmt->execute([
                ':trace_id'     => $traceId,
                ':channel'      => $this->channel,
                ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }

        $this->markHandled($inbox, $uid);

        pf_log('info', 'Email ingested', ['trace_id' => $traceId, 'uid' => $uid]);
        if ($this->debug) { $this->out("DEBUG: ingested uid={$uid} trace_id={$traceId}"); }

        return true;
    }

    /**
     * Extract allowed attachments from an IMAP message UID, store via AttachmentStore,
     * and return meta rows for the inbound payload.
     */
    private function extractAttachmentsToPayload($inbox, int $uid, string $traceId): array
    {
        $store = App\Pillars\Storage\AttachmentStoreFactory::make();

        $allowed = [
            'application/pdf' => true,
            'image/jpeg'      => true,
            'image/png'       => true,
            'image/webp'      => true,
        ];

        $struct = @imap_fetchstructure($inbox, (string)$uid, FT_UID);
        if (!$struct || empty($struct->parts) || !is_array($struct->parts)) {
            return [];
        }

        $out = [];

        foreach ($struct->parts as $i => $part) {
            $disp = strtolower((string)($part->disposition ?? ''));
            $isAttachment = ($disp === 'attachment' || $disp === 'inline');

            // filename
            $filename = '';
            if (!empty($part->dparameters) && is_array($part->dparameters)) {
                foreach ($part->dparameters as $dp) {
                    if (!isset($dp->attribute, $dp->value)) continue;
                    if (strtolower((string)$dp->attribute) === 'filename') { $filename = (string)$dp->value; break; }
                }
            }
            if ($filename === '' && !empty($part->parameters) && is_array($part->parameters)) {
                foreach ($part->parameters as $p) {
                    if (!isset($p->attribute, $p->value)) continue;
                    if (strtolower((string)$p->attribute) === 'name') { $filename = (string)$p->value; break; }
                }
            }

            if (!$isAttachment && $filename === '') continue;

            // mime
            $typeMap = [0=>'text',1=>'multipart',2=>'message',3=>'application',4=>'audio',5=>'image',6=>'video',7=>'other'];
            $major = $typeMap[(int)($part->type ?? 7)] ?? 'other';
            $sub   = strtolower((string)($part->subtype ?? 'octet-stream'));
            $mime  = $major . '/' . $sub;

            if ($mime === 'image/jpg') $mime = 'image/jpeg';
            if (!isset($allowed[$mime])) continue;

            $section = (string)($i + 1);

            $raw = (string)@imap_fetchbody($inbox, (string)$uid, $section, FT_UID);
            if ($raw === '') continue;

            // decode
            $enc = (int)($part->encoding ?? 0);
            if ($enc === 3)      { $bytes = base64_decode($raw, true); }
            elseif ($enc === 4)  { $bytes = quoted_printable_decode($raw); }
            else                 { $bytes = $raw; }

            if (!is_string($bytes) || $bytes === '') continue;

            $safeName = trim($filename) !== '' ? $filename : ('attachment_' . $section);
            $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $safeName) ?: ('attachment_' . $section);

            try {
                $storeKey = $store->put($traceId, $safeName, $bytes, $mime);

                $out[] = [
                    'name'      => $safeName,
                    'mime'      => $mime,
                    'size'      => strlen($bytes),
                    'store_key' => $storeKey,
                    'sha256'    => hash('sha256', $bytes),
                ];
            } catch (Throwable $e) {
                pf_log('error', 'Attachment store failed', [
                    'trace_id' => $traceId,
                    'uid'      => $uid,
                    'name'     => $safeName,
                    'mime'     => $mime,
                    'err'      => $e->getMessage(),
                ]);
            } finally {
                unset($bytes, $raw);
            }
        }

        return $out;
    }

    private function markHandled($inbox, int $uid): void
    {
        // Delete by UID. If ingestion fails, we never call this.
        @imap_delete($inbox, (string)$uid, FT_UID);
    }


    private function getBestBody($inbox, int $uid): string
    {
        $structure = imap_fetchstructure($inbox, $uid, FT_UID);
        if (!is_object($structure)) {
            $raw = imap_body($inbox, $uid, FT_UID);
            return is_string($raw) ? $raw : '';
        }

        if (!isset($structure->parts) || !is_array($structure->parts)) {
            $raw = imap_body($inbox, $uid, FT_UID);
            return is_string($raw) ? $this->decodePart($raw, (int)($structure->encoding ?? 0)) : '';
        }

        $plain = $this->findPart($inbox, $uid, $structure, 'TEXT/PLAIN');
        if ($plain !== '') { return $plain; }

        $html = $this->findPart($inbox, $uid, $structure, 'TEXT/HTML');
        if ($html !== '') { return trim(strip_tags($html)); }

        $raw = imap_body($inbox, $uid, FT_UID);
        return is_string($raw) ? $raw : '';
    }

    private function findPart($inbox, int $uid, object $structure, string $mime): string
    {
        $parts = $structure->parts ?? [];
        $i = 1;

        foreach ($parts as $part) {
            $partMime = $this->mimeType($part);
            if ($partMime === $mime) {
                $raw = imap_fetchbody($inbox, $uid, (string)$i, FT_UID);
                if (!is_string($raw)) { return ''; }
                return trim($this->decodePart($raw, (int)($part->encoding ?? 0)));
            }
            $i++;
        }
        return '';
    }

    private function mimeType(object $part): string
    {
        $primary = (int)($part->type ?? 0);
        $sub     = strtoupper((string)($part->subtype ?? ''));

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

    private function safeMailbox(): string
    {
        // host + port + enc only (no user/pass)
        return $this->host . ':' . $this->port . ' ' . $this->enc . ' ' . $this->mailbox;
    }

    private function makeTraceId(string $messageId): string
    {
        if ($messageId !== '') {
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

    private function out(string $msg): void
    {
        echo $msg . "\n";
    }

    private function dumpImapErrors(): void
    {
        $errs = imap_errors();
        if (is_array($errs) && !empty($errs)) {
            $this->out("DEBUG: imap_errors=" . json_encode($errs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $last = imap_last_error();
        if (is_string($last) && $last !== '') {
            $this->out("DEBUG: imap_last_error=" . $last);
        }
    }
}

(new PfEmailIngestCron())->run();
