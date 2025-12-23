<?php declare(strict_types=1);

/**
 * Plainfully Email Bridge (IMAP -> HTTP hook)
 *
 * - Pulls UNSEEN emails from IONOS (per mailbox)
 * - Forwards to /hooks/email/inbound-dev
 * - On success (2xx): deletes message (GDPR-safe cleanup)
 * - On deferral (402/429): marks SEEN + moves to "Deferred"
 * - On failure (other non-2xx): moves to "Failed" and marks SEEN (prevents infinite retries)
 *
 * Usage:
 *   php app/cli/email_bridge.php --dry-run
 *   php app/cli/email_bridge.php
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

const PF_MAX_EMAILS_PER_RUN = 50;       // per mailbox per run
const PF_MAX_EMAIL_BYTES    = 200000;   // 200KB guard rail
const PF_HOOK_TIMEOUT_SECS  = 20;

require_once __DIR__ . '/../support/debug_trace.php';

/**
 * Local-safe hash helper (prevents fatal if core helper not loaded in CLI).
 */
if (!function_exists('pf_safe_hash')) {
    function pf_safe_hash(string $s): string
    {
        return substr(hash('sha256', $s), 0, 12);
    }
}

/**
 * Convert PHP notices/warnings into exceptions inside the per-message try/catch,
 * so the script logs precisely where it failed instead of half-continuing.
 */
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false; // respect @ suppression
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$runId = pf_trace_run_id();
fwrite(STDOUT, "[INFO] Bridge run_id={$runId}\n");
pf_trace($runId, 'email-bridge', 'start', 'info', 'Bridge run started', ['argv' => $argv]);

// ---------------------------------------------------------
// 0) Simple .env loader (root-level .env) - SAFE
// ---------------------------------------------------------
$envPath = dirname(__DIR__, 2) . '/.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));

        if ($v !== '' && (
            ($v[0] === '"' && str_ends_with($v, '"')) ||
            ($v[0] === "'" && str_ends_with($v, "'"))
        )) {
            $v = substr($v, 1, -1);
        }

        if ($k !== '') {
            putenv($k . '=' . $v);
        }
    }
}

// ---------------------------------------------------------
// 0.A) Overlap stopper (lock file)
// ---------------------------------------------------------
$lockFile = sys_get_temp_dir() . '/plainfully_email_bridge.lock';
$lockFp = fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "[INFO] Another bridge run is active. Exiting.\n");
    pf_trace($runId, 'email-bridge', 'lock', 'warn', 'Another bridge run active, exiting');
    exit(0);
}
// keep $lockFp open until script ends

// ---------------------------------------------------------
// 1) Core config from env
// ---------------------------------------------------------
$hookUrl   = getenv('EMAIL_BRIDGE_HOOK_URL')   ?: 'https://plainfully.com/hooks/email/inbound-dev';
$hookToken = getenv('EMAIL_BRIDGE_HOOK_TOKEN') ?: (getenv('EMAIL_HOOK_TOKEN') ?: '');

if ($hookToken === '') {
    fwrite(STDERR, "[FATAL] EMAIL_BRIDGE_HOOK_TOKEN or EMAIL_HOOK_TOKEN not set in .env\n");
    pf_trace($runId, 'email-bridge', 'config', 'error', 'Missing hook token');
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);

pf_trace($runId, 'email-bridge', 'config', 'info', 'Loaded config', [
    'hook_url' => $hookUrl,
    'dry_run'  => $dryRun,
]);

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------

function pf_build_imap_mailbox(string $host, int $port, string $encryption): string
{
    $enc = strtolower($encryption);
    $flags = '/imap';

    if ($enc === 'ssl') {
        $flags .= '/ssl';
    } elseif ($enc === 'tls') {
        $flags .= '/tls';
    }

    return sprintf('{%s:%d%s}INBOX', $host, $port, $flags);
}

/**
 * Safely extract a single email address from an IMAP header address list.
 *
 * @param mixed $addrList e.g. $header->from or $header->to
 */
function pf_imap_first_email($addrList): string
{
    if (!is_array($addrList) || empty($addrList)) {
        return '';
    }

    $a = $addrList[0] ?? null;
    if (!is_object($a)) {
        return '';
    }

    $mailbox = isset($a->mailbox) ? (string)$a->mailbox : '';
    $host    = isset($a->host) ? (string)$a->host : '';

    if ($mailbox === '' || $host === '') {
        return '';
    }

    return $mailbox . '@' . $host;
}

function pf_decode_subject($raw): string
{
    $s = is_string($raw) ? $raw : '';
    if ($s === '') {
        return '';
    }

    $decoded = @imap_utf8($s);
    return (is_string($decoded) && $decoded !== '') ? trim($decoded) : trim($s);
}

/**
 * Ensure a folder exists (best-effort).
 * $mailboxBase example: "{host:port/imap/ssl}"
 */
function pf_ensure_mailbox_folder($inbox, string $mailboxBase, string $folder): void
{
    $target = $mailboxBase . $folder;
    @imap_createmailbox($inbox, imap_utf7_encode($target));
}

/**
 * Mark a message SEEN by UID (best-effort).
 */
function pf_mark_seen_uid($inbox, int $uid): void
{
    @imap_setflag_full($inbox, (string)$uid, "\\Seen", ST_UID);
}

/**
 * Move a message by UID to a folder (best-effort).
 */
function pf_move_uid($inbox, int $uid, string $folder): void
{
    @imap_mail_move($inbox, (string)$uid, $folder, CP_UID);
}

/**
 * Delete a message by UID (mark now; expunge later).
 */
function pf_delete_uid($inbox, int $uid): void
{
    $uidStr = (string)$uid;
    if (!imap_delete($inbox, $uidStr, FT_UID)) {
        fwrite(STDERR, "  [ERROR] Failed to mark UID {$uidStr} for deletion.\n");
    } else {
        fwrite(STDOUT, "  [INFO] Marked UID {$uidStr} for deletion.\n");
    }
}

/**
 * Classify a hook HTTP response.
 */
function pf_hook_classify(int $httpCode): string
{
    if ($httpCode >= 200 && $httpCode < 300) {
        return 'ok';
    }

    if ($httpCode === 402 || $httpCode === 429) {
        return 'deferred';
    }

    if (in_array($httpCode, [500, 502, 503, 504], true)) {
        return 'retryable';
    }

    return 'fail';
}

/**
 * POST to Plainfully hook.
 *
 * Returns: [bool $ok, int $httpCode, string $class, string $respSnippet]
 */
function pf_forward_to_hook(
    string $hookUrl,
    string $hookToken,
    string $from,
    string $to,
    string $subject,
    string $body
): array {
    $postFields = http_build_query([
        'from'    => $from,
        'to'      => $to,
        'subject' => $subject,
        'body'    => $body,
    ]);

    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'X-Plainfully-Token: ' . $hookToken,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($hookUrl);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => PF_HOOK_TIMEOUT_SECS,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $postFields,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [false, 0, 'fail', 'curl error: ' . $err];
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $snip = substr((string)$raw, 0, 300);
        $class = pf_hook_classify($httpCode);

        return [$class === 'ok', $httpCode, $class, $snip];
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'timeout' => PF_HOOK_TIMEOUT_SECS,
            'header'  => implode("\r\n", $headers),
            'content' => $postFields,
        ],
    ]);

    $raw = @file_get_contents($hookUrl, false, $context);
    $snip = substr((string)$raw, 0, 300);

    $httpCode = 0;
    global $http_response_header;
    if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
        $httpCode = (int)$m[1];
    }

    if ($raw === false) {
        $err = error_get_last();
        return [false, $httpCode, 'fail', 'file_get_contents error: ' . ($err['message'] ?? 'unknown')];
    }

    $class = pf_hook_classify($httpCode);
    return [$class === 'ok', $httpCode, $class, $snip];
}

function pf_fetch_body($inbox, int $msgNo): string
{
    $body = imap_body($inbox, $msgNo, FT_PEEK);
    return is_string($body) ? $body : '';
}

// ---------------------------------------------------------
// Process one mailbox
// ---------------------------------------------------------
function pf_process_mailbox(string $label, bool $dryRun, string $hookUrl, string $hookToken, string $runId): void
{
    $upper = strtoupper($label);

    $host       = getenv('EMAIL_BRIDGE_IMAP_HOST')       ?: '';
    $port       = (int)(getenv('EMAIL_BRIDGE_IMAP_PORT') ?: 0);
    $encryption = getenv('EMAIL_BRIDGE_IMAP_ENCRYPTION') ?: 'ssl';

    $user = getenv("MAIL_{$upper}_USER") ?: '';
    $pass = getenv("MAIL_{$upper}_PASS") ?: '';

    if ($host === '' || $port <= 0 || $user === '' || $pass === '') {
        fwrite(STDERR, "[WARN] Skipping mailbox '{$label}' – IMAP or MAIL_* env vars not fully set.\n");
        pf_trace($runId, 'email-bridge', 'mailbox_skip', 'warn', 'Mailbox env missing', [
            'label' => $label,
            'host_set' => $host !== '',
            'port' => $port,
            'user_set' => $user !== '',
        ]);
        return;
    }

    if (!function_exists('imap_open')) {
        fwrite(STDERR, "[FATAL] PHP IMAP extension not enabled.\n");
        pf_trace($runId, 'email-bridge', 'fatal', 'error', 'PHP IMAP extension missing');
        exit(1);
    }

    $mailbox = pf_build_imap_mailbox($host, $port, $encryption);

    $enc = strtolower($encryption);
    $flags = '/imap' . (($enc === 'ssl') ? '/ssl' : (($enc === 'tls') ? '/tls' : ''));
    $mailboxBase = sprintf('{%s:%d%s}', $host, $port, $flags);

    fwrite(STDOUT, "[INFO] Connecting to {$label} mailbox: {$mailbox}\n");
    pf_trace($runId, 'email-bridge', 'imap_connect', 'info', 'Connecting', [
        'label' => $label,
        'mailbox' => $mailbox,
        'user' => $user,
    ]);

    $inbox = @imap_open($mailbox, $user, $pass);
    if ($inbox === false) {
        $err = (string)imap_last_error();
        fwrite(STDERR, "[ERROR] imap_open failed for '{$label}': {$err}\n");
        pf_trace($runId, 'email-bridge', 'imap_connect_fail', 'error', 'imap_open failed', [
            'label' => $label,
            'error' => $err,
        ]);
        return;
    }

    pf_ensure_mailbox_folder($inbox, $mailboxBase, 'Deferred');
    pf_ensure_mailbox_folder($inbox, $mailboxBase, 'Failed');

    $uids = imap_search($inbox, 'UNSEEN', SE_UID);

    if ($uids === false || empty($uids)) {
        fwrite(STDOUT, "[INFO] No unseen messages in '{$label}' mailbox.\n");
        pf_trace($runId, 'email-bridge', 'imap_search', 'info', 'No unseen messages', ['label' => $label]);
        imap_close($inbox);
        return;
    }

    $uids = array_slice($uids, 0, PF_MAX_EMAILS_PER_RUN);
    fwrite(STDOUT, "[INFO] Found " . count($uids) . " unseen message(s) in '{$label}'.\n");
    pf_trace($runId, 'email-bridge', 'imap_search', 'info', 'Found unseen messages', [
        'label' => $label,
        'count' => count($uids),
    ]);

    foreach ($uids as $uid) {
        try {
            pf_trace($runId, 'email-bridge', 'msg_start', 'info', 'Processing message', [
                'label' => $label,
                'uid'   => $uid,
            ]);

            $msgNo = imap_msgno($inbox, (int)$uid);
            if ($msgNo <= 0) {
                throw new RuntimeException("imap_msgno returned {$msgNo} for UID {$uid}");
            }

            $header = imap_headerinfo($inbox, $msgNo);
            if (!is_object($header)) {
                throw new RuntimeException("imap_headerinfo returned non-object for UID {$uid}");
            }

            $overview = imap_fetch_overview($inbox, (string)$msgNo, 0);
            $sizeBytes = (int)($overview[0]->size ?? 0);

            if ($sizeBytes > PF_MAX_EMAIL_BYTES) {
                fwrite(STDOUT, "  [SKIP] UID {$uid} too large ({$sizeBytes} bytes). Moving to Failed.\n");
                pf_trace($runId, 'email-bridge', 'msg_skip_large', 'warn', 'Message too large', [
                    'label' => $label,
                    'uid'   => $uid,
                    'size_bytes' => $sizeBytes,
                ]);

                if (!$dryRun) {
                    pf_mark_seen_uid($inbox, (int)$uid);
                    pf_move_uid($inbox, (int)$uid, 'Failed');
                }
                continue;
            }

            $from = pf_imap_first_email($header->from ?? null);
            $to   = pf_imap_first_email($header->to ?? null);
            $subject = pf_decode_subject($header->subject ?? '');

            $fromLower = strtolower($from);
            $subjectLower = strtolower($subject);
            if ($fromLower === 'noreply@ionos.com' || str_contains($subjectLower, 'daily report mailbox')) {
                fwrite(STDOUT, "  [SKIP] System mailbox report email.\n");
                pf_trace($runId, 'email-bridge', 'msg_skip_system', 'info', 'Skipped system report email', [
                    'label' => $label,
                    'uid'   => $uid,
                    'from'  => $from,
                ]);

                if (!$dryRun) {
                    pf_delete_uid($inbox, (int)$uid);
                }
                continue;
            }

            $body = pf_fetch_body($inbox, $msgNo);

            fwrite(STDOUT, "\n[INFO] Processing UID {$uid} ({$label})\n");
            fwrite(STDOUT, "  From:    {$from}\n");
            fwrite(STDOUT, "  To:      {$to}\n");
            fwrite(STDOUT, "  Subject: {$subject}\n");

            pf_trace($runId, 'email-bridge', 'message_parsed', 'info', 'Parsed message', [
                'label' => $label,
                'uid' => $uid,
                'from' => $from,
                'to' => $to,
                'subject_len' => strlen($subject),
                'body_len' => strlen($body),
                'subject_hash' => pf_safe_hash($subject),
            ]);

            if ($dryRun) {
                fwrite(STDOUT, "  [DRY-RUN] Would forward to hook and NOT modify mailbox.\n");
                continue;
            }

            $effectiveTo = ($to !== '') ? $to : $user;

            pf_trace($runId, 'email-bridge', 'post_hook', 'info', 'Posting to hook', [
                'label' => $label,
                'uid' => $uid,
                'hook' => $hookUrl,
            ]);

            [$ok, $httpCode, $class, $snip] = pf_forward_to_hook(
                $hookUrl,
                $hookToken,
                $from,
                $effectiveTo,
                $subject,
                $body
            );

            pf_trace(
                $runId,
                'email-bridge',
                'post_hook_done',
                $ok ? 'info' : (($class === 'deferred' || $class === 'retryable') ? 'warn' : 'error'),
                'Hook response',
                [
                    'label' => $label,
                    'uid' => $uid,
                    'http_code' => $httpCode,
                    'class' => $class,
                    'resp_snip' => $snip,
                ]
            );

            if ($ok) {
                fwrite(STDOUT, "  [OK] Hook accepted (HTTP {$httpCode}). Deleting.\n");
                pf_delete_uid($inbox, (int)$uid);
                continue;
            }

            if ($class === 'deferred') {
                fwrite(STDOUT, "  [DEFER] Hook deferred (HTTP {$httpCode}). Marking SEEN + moving to Deferred.\n");
                pf_mark_seen_uid($inbox, (int)$uid);
                pf_move_uid($inbox, (int)$uid, 'Deferred');
                continue;
            }

            if ($class === 'retryable') {
                fwrite(STDOUT, "  [RETRY] Hook transient failure (HTTP {$httpCode}). Leaving UNSEEN for retry.\n");
                continue;
            }

            fwrite(STDOUT, "  [FAIL] Hook failed (HTTP {$httpCode}). Marking SEEN + moving to Failed.\n");
            pf_mark_seen_uid($inbox, (int)$uid);
            pf_move_uid($inbox, (int)$uid, 'Failed');

        } catch (Throwable $t) {
            fwrite(STDERR, "[ERROR] UID {$uid} failed: " . $t->getMessage() . "\n");
            pf_trace($runId, 'email-bridge', 'msg_exception', 'error', 'Message processing exception', [
                'label' => $label,
                'uid'   => $uid,
                'error' => $t->getMessage(),
                'file'  => $t->getFile(),
                'line'  => $t->getLine(),
            ]);

            if (!$dryRun) {
                pf_mark_seen_uid($inbox, (int)$uid);
                pf_move_uid($inbox, (int)$uid, 'Failed');
            }
            continue;
        }
    }

    if (!$dryRun) {
        @imap_expunge($inbox);
    }

    imap_close($inbox);
    fwrite(STDOUT, "[INFO] Finished mailbox '{$label}'.\n");
    pf_trace($runId, 'email-bridge', 'mailbox_done', 'info', 'Finished mailbox', ['label' => $label]);
}

// ---------------------------------------------------------
// Run both mailboxes
// ---------------------------------------------------------
fwrite(STDOUT, "[INFO] Plainfully Email Bridge starting (dry-run=" . ($dryRun ? 'yes' : 'no') . ")\n");

pf_process_mailbox('scamcheck', $dryRun, $hookUrl, $hookToken, $runId);
pf_process_mailbox('clarify',   $dryRun, $hookUrl, $hookToken, $runId);

fwrite(STDOUT, "[INFO] Plainfully Email Bridge completed.\n");
pf_trace($runId, 'email-bridge', 'done', 'info', 'Bridge run completed');
