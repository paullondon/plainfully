<?php declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/**
 * Plainfully Email Bridge (IMAP -> HTTP hook)
 *
 * - Pulls UNSEEN emails from IONOS (per mailbox)
 * - Forwards to /hooks/email/inbound-dev
 * - On success: deletes message
 * - On deferral (402/429): marks SEEN + moves to "Deferred"
 * - On failure: leaves UNSEEN (so it retries), but logs clearly
 *
 * Usage:
 *   php app/cli/email_bridge.php --dry-run
 *   php app/cli/email_bridge.php
 */

const PF_MAX_EMAILS_PER_RUN = 50;      // per mailbox per run
const PF_MAX_EMAIL_BYTES    = 200000;  // 200KB guard rail

require_once __DIR__ . '/../support/debug_trace.php';

$runId = pf_trace_run_id();
fwrite(STDOUT, "[INFO] Bridge run_id={$runId}
");
pf_trace($runId, 'email-bridge', 'start', 'info', 'Bridge run started', [
    'argv' => $argv,
]);

// ---------------------------------------------------------
// 0. Simple .env loader (root-level .env) - SAFE
// ---------------------------------------------------------
$envPath = dirname(__DIR__, 2) . '/.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        // Skip blanks + comments
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // Must contain '='
        $pos = strpos($line, '=');
        if ($pos === false) {
            // Ignore malformed lines instead of exploding the script
            continue;
        }

        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));

        // Strip optional surrounding quotes
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
    fwrite(STDOUT, "[INFO] Another bridge run is active. Exiting.
");
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
    fwrite(STDERR, "[FATAL] EMAIL_BRIDGE_HOOK_TOKEN or EMAIL_HOOK_TOKEN not set in .env
");
    pf_trace($runId, 'email-bridge', 'config', 'error', 'Missing hook token');
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);

pf_trace($runId, 'email-bridge', 'config', 'info', 'Loaded config', [
    'hook_url' => $hookUrl,
    'dry_run'  => $dryRun,
]);

// ---------------------------------------------------------
// 2) Helper: IMAP mailbox string
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

/**
 * Safely decode subject (some subjects are mime-encoded).
 */
function pf_decode_subject($raw): string
{
    $s = is_string($raw) ? $raw : '';
    if ($s === '') {
        return '';
    }

    $decoded = @imap_utf8($s);
    return is_string($decoded) && $decoded !== '' ? $decoded : $s;
}

/**
 * POST to Plainfully hook.
 *
 * Returns: [bool ok, int httpCode, string class, string snippet]
 * class = ok | deferred | fail
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

    $raw = '';
    $status = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($hookUrl);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $postFields,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [false, 0, 'fail', 'curl error: ' . $err];
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'timeout' => 15,
                'header'  => implode("
", $headers),
                'content' => $postFields,
            ],
        ]);

        $raw = @file_get_contents($hookUrl, false, $context);
        if ($raw === false) {
            $err = error_get_last();
            return [false, 0, 'fail', 'file_get_contents error: ' . ($err['message'] ?? 'unknown')];
        }

        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
    }

    $snip = substr((string)$raw, 0, 300);

    if ($status === 402 || $status === 429) {
        return [false, $status, 'deferred', $snip];
    }

    if ($status < 200 || $status >= 300) {
        return [false, $status, 'fail', $snip];
    }

    return [true, $status, 'ok', $snip];
}

/**
 * List folders once so we don't trigger ALREADYEXISTS on create.
 */
function pf_imap_list_folders($inbox, string $mailboxBase): array
{
    $list = @imap_list($inbox, $mailboxBase, '*');
    if (!is_array($list)) {
        return [];
    }

    $folders = [];
    foreach ($list as $full) {
        if (!is_string($full) || $full === '') {
            continue;
        }
        $decoded = imap_utf7_decode($full);
        if (str_starts_with($decoded, $mailboxBase)) {
            $decoded = substr($decoded, strlen($mailboxBase));
        }
        $decoded = trim($decoded);
        if ($decoded !== '') {
            $folders[strtolower($decoded)] = true;
        }
    }

    return $folders;
}

/**
 * Create folder only if missing.
 * HARD-suppresses warnings/exceptions because some environments convert warnings into ErrorException.
 */
function pf_ensure_mailbox_folder($inbox, string $mailboxBase, string $folder, array &$existingFolders): void
{
    $key = strtolower($folder);
    if (isset($existingFolders[$key])) {
        return;
    }

    $target = $mailboxBase . $folder;

    $prev = set_error_handler(static function () { return true; });
    try {
        @imap_createmailbox($inbox, imap_utf7_encode($target));
    } catch (Throwable $t) {
        error_log('pf_ensure_mailbox_folder error: ' . $t->getMessage());
    } finally {
        if ($prev !== null) {
            set_error_handler($prev);
        } else {
            restore_error_handler();
        }
    }

    $existingFolders = pf_imap_list_folders($inbox, $mailboxBase);
}

/**
 * Process one mailbox.
 */
function pf_process_mailbox(string $label, bool $dryRun, string $hookUrl, string $hookToken, string $runId): void
{
    $upper = strtoupper($label); // SCAMCHECK / CLARIFY

    $host       = getenv('EMAIL_BRIDGE_IMAP_HOST')       ?: '';
    $port       = (int)(getenv('EMAIL_BRIDGE_IMAP_PORT') ?: 0);
    $encryption = getenv('EMAIL_BRIDGE_IMAP_ENCRYPTION') ?: 'ssl';

    $user = getenv("MAIL_{$upper}_USER") ?: '';
    $pass = getenv("MAIL_{$upper}_PASS") ?: '';

    if ($host === '' || $port <= 0 || $user === '' || $pass === '') {
        fwrite(STDERR, "[WARN] Skipping mailbox '{$label}' – IMAP or MAIL_* env vars not fully set.
");
        pf_trace($runId, 'email-bridge', 'mailbox_skip', 'warn', 'Mailbox env missing', [
            'label' => $label,
            'host_set' => $host !== '',
            'port' => $port,
            'user_set' => $user !== '',
        ]);
        return;
    }

    if (!function_exists('imap_open')) {
        fwrite(STDERR, "[FATAL] PHP IMAP extension not enabled.
");
        pf_trace($runId, 'email-bridge', 'fatal', 'error', 'PHP IMAP extension missing');
        exit(1);
    }

    $mailbox = pf_build_imap_mailbox($host, $port, $encryption);

    $enc = strtolower($encryption);
    $flags = '/imap' . (($enc === 'ssl') ? '/ssl' : (($enc === 'tls') ? '/tls' : ''));
    $mailboxBase = sprintf('{%s:%d%s}', $host, $port, $flags);

    fwrite(STDOUT, "[INFO] Connecting to {$label} mailbox: {$mailbox}
");
    pf_trace($runId, 'email-bridge', 'imap_connect', 'info', 'Connecting', [
        'label' => $label,
        'mailbox' => $mailbox,
        'user' => $user,
    ]);

    $inbox = @imap_open($mailbox, $user, $pass);
    if ($inbox === false) {
        $err = (string)imap_last_error();
        fwrite(STDERR, "[ERROR] imap_open failed for '{$label}': {$err}
");
        pf_trace($runId, 'email-bridge', 'imap_connect_fail', 'error', 'imap_open failed', [
            'label' => $label,
            'error' => $err,
        ]);
        return;
    }

    // Ensure folders (best-effort, safe)
    $existingFolders = pf_imap_list_folders($inbox, $mailboxBase);
    pf_ensure_mailbox_folder($inbox, $mailboxBase, 'Deferred', $existingFolders);
    pf_ensure_mailbox_folder($inbox, $mailboxBase, 'Failed', $existingFolders);

    $emails = imap_search($inbox, 'UNSEEN', SE_UID);

    if ($emails === false || empty($emails)) {
        fwrite(STDOUT, "[INFO] No unseen messages in '{$label}' mailbox.
");
        pf_trace($runId, 'email-bridge', 'imap_search', 'info', 'No unseen messages', ['label' => $label]);
        imap_close($inbox);
        return;
    }

    $emails = array_slice($emails, 0, PF_MAX_EMAILS_PER_RUN);
    fwrite(STDOUT, "[INFO] Found " . count($emails) . " unseen message(s) in '{$label}'.
");
    pf_trace($runId, 'email-bridge', 'imap_search', 'info', 'Found unseen messages', [
        'label' => $label,
        'count' => count($emails),
    ]);

    $deleteUid = static function ($inbox, int $uid): void {
        $uidStr = (string)$uid;
        if (!imap_delete($inbox, $uidStr, FT_UID)) {
            fwrite(STDERR, "  [ERROR] Failed to delete UID {$uidStr}.
");
        } else {
            fwrite(STDOUT, "  [INFO] Marked UID {$uidStr} for deletion.
");
        }
    };

    foreach ($emails as $uid) {
        try {
            $msgNo  = imap_msgno($inbox, $uid);
            $header = imap_headerinfo($inbox, $msgNo);

            $overview = imap_fetch_overview($inbox, (string)$msgNo, 0);
            $sizeBytes = (int)($overview[0]->size ?? 0);

            if ($sizeBytes > PF_MAX_EMAIL_BYTES) {
                fwrite(STDOUT, "  [SKIP] UID {$uid} too large ({$sizeBytes} bytes). Leaving UNSEEN.
");
                pf_trace($runId, 'email-bridge', 'skip_large', 'warn', 'Message too large', [
                    'label' => $label,
                    'uid' => $uid,
                    'bytes' => $sizeBytes,
                ]);
                continue;
            }

            $from    = pf_imap_first_email($header->from ?? null);
            $to      = pf_imap_first_email($header->to ?? null);
            $subject = trim(pf_decode_subject($header->subject ?? ''));

            $fromLower    = strtolower($from);
            $subjectLower = strtolower($subject);

            if ($fromLower === 'noreply@ionos.com' || str_contains($subjectLower, 'daily report mailbox')) {
                fwrite(STDOUT, "  [SKIP] System mailbox report email.
");
                pf_trace($runId, 'email-bridge', 'skip_system_report', 'info', 'Skipped system mailbox report', [
                    'label' => $label,
                    'uid' => $uid,
                ]);
                if (!$dryRun) {
                    $deleteUid($inbox, $uid);
                }
                continue;
            }

            $body = imap_body($inbox, $msgNo, FT_PEEK);
            if (!is_string($body)) {
                $body = '';
            }

            fwrite(STDOUT, "
[INFO] Processing UID {$uid} ({$label})
");
            fwrite(STDOUT, "  From:    {$from}
");
            fwrite(STDOUT, "  To:      {$to}
");
            fwrite(STDOUT, "  Subject: {$subject}
");

            pf_trace($runId, 'email-bridge', 'message_parsed', 'info', 'Parsed message', [
                'label' => $label,
                'uid' => $uid,
                'from' => $from,
                'to' => $to,
                'subject_len' => strlen($subject),
                'body_len' => strlen($body),
            ]);

            if ($dryRun) {
                fwrite(STDOUT, "  [DRY-RUN] Would forward to hook and NOT modify mailbox.
");
                continue;
            }

            pf_trace($runId, 'email-bridge', 'post_hook', 'info', 'Posting to hook', [
                'label' => $label,
                'uid' => $uid,
                'hook' => $hookUrl,
            ]);

            [$ok, $httpCode, $class, $snip] = pf_forward_to_hook(
                $hookUrl,
                $hookToken,
                $from,
                ($to !== '' ? $to : $user),
                $subject,
                $body
            );

            pf_trace(
                $runId,
                'email-bridge',
                'post_hook_done',
                $ok ? 'info' : (($class === 'deferred') ? 'warn' : 'error'),
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
                fwrite(STDOUT, "  [OK] Hook accepted (HTTP {$httpCode}). Deleting.
");
                $deleteUid($inbox, $uid);
                continue;
            }

            if ($class === 'deferred') {
                fwrite(STDOUT, "  [DEFER] Hook deferred (HTTP {$httpCode}). Marking SEEN + moving to Deferred.
");
                @imap_setflag_full($inbox, (string)$uid, "\Seen", ST_UID);
                @imap_mail_move($inbox, (string)$uid, 'Deferred', CP_UID);
                continue;
            }

            fwrite(STDOUT, "  [FAIL] Hook failed (HTTP {$httpCode}). Leaving UNSEEN for retry.
");

        } catch (Throwable $t) {
            fwrite(STDERR, "[ERROR] UID {$uid} failed: " . $t->getMessage() . "
");
            pf_trace($runId, 'email-bridge', 'uid_exception', 'error', 'UID processing exception', [
                'label' => $label,
                'uid' => $uid,
                'error' => $t->getMessage(),
            ]);
            continue;
        }
    }

    if (!$dryRun) {
        @imap_expunge($inbox);
    }

    imap_close($inbox);
    fwrite(STDOUT, "[INFO] Finished mailbox '{$label}'.
");
    pf_trace($runId, 'email-bridge', 'mailbox_done', 'info', 'Finished mailbox', ['label' => $label]);
}

// ---------------------------------------------------------
// 6) Run both mailboxes
// ---------------------------------------------------------
fwrite(STDOUT, "[INFO] Plainfully Email Bridge starting (dry-run=" . ($dryRun ? 'yes' : 'no') . ")
");

pf_process_mailbox('scamcheck', $dryRun, $hookUrl, $hookToken, $runId);
pf_process_mailbox('clarify',   $dryRun, $hookUrl, $hookToken, $runId);

fwrite(STDOUT, "[INFO] Plainfully Email Bridge completed.
");
pf_trace($runId, 'email-bridge', 'done', 'info', 'Bridge run completed');
