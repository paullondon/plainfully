<?php declare(strict_types=1);

/**
 * Plainfully Email Bridge
 *
 * CLI script to pull emails from IONOS via IMAP and forward them
 * into the Plainfully email hook (/hooks/email/inbound-dev).
 *
 * - Supports two mailboxes: ScamCheck + Clarify
 * - Only deletes messages if the HTTP hook returns success
 * - Has a --dry-run mode for safe testing
 *
 * Usage (from project root):
 *   php app/cli/email_bridge.php --dry-run
 *   php app/cli/email_bridge.php
 */

// ---------------------------------------------------------
// 0. Simple .env loader (root-level .env)
// ---------------------------------------------------------
$envPath = dirname(__DIR__, 2) . '/.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        putenv("$k=$v");
    }
}

// ---------------------------------------------------------
// 1. Core config from env
// ---------------------------------------------------------
$hookUrl   = getenv('EMAIL_BRIDGE_HOOK_URL')   ?: 'https://plainfully.com/hooks/email/inbound-dev';
$hookToken = getenv('EMAIL_BRIDGE_HOOK_TOKEN') ?: getenv('EMAIL_HOOK_TOKEN') ?: '';

if ($hookToken === '') {
    fwrite(STDERR, "[FATAL] EMAIL_BRIDGE_HOOK_TOKEN or EMAIL_HOOK_TOKEN not set in .env\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);

// ---------------------------------------------------------
// 2. Helper: IMAP mailbox string
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

// ---------------------------------------------------------
// 3. Helper: POST to Plainfully hook
// ---------------------------------------------------------
function pf_forward_to_hook(
    string $hookUrl,
    string $hookToken,
    string $from,
    string $to,
    string $subject,
    string $body
): array {
    $ch = curl_init($hookUrl);

    $postFields = [
        'from'    => $from,
        'to'      => $to,
        'subject' => $subject,
        'body'    => $body,
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'X-Plainfully-Token: ' . $hookToken,
        ],
        CURLOPT_POSTFIELDS     => $postFields,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [false, 'curl error: ' . $err];
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        return [false, 'HTTP ' . $status . ' from hook: ' . substr($raw, 0, 200)];
    }

    return [true, null];
}

// ---------------------------------------------------------
// 4. Helper: process one mailbox
// ---------------------------------------------------------
function pf_process_mailbox(string $label, bool $dryRun): void
{
    $upper = strtoupper($label); // "SCAMCHECK" / "CLARIFY"

    // Shared IMAP host/port/encryption
    $host       = getenv('EMAIL_BRIDGE_IMAP_HOST')       ?: '';
    $port       = (int)(getenv('EMAIL_BRIDGE_IMAP_PORT') ?: 0);
    $encryption = getenv('EMAIL_BRIDGE_IMAP_ENCRYPTION') ?: 'ssl';

    // Per-mailbox credentials – reuse MAIL_* from .env
    $user = getenv("MAIL_{$upper}_USER") ?: '';
    $pass = getenv("MAIL_{$upper}_PASS") ?: '';

    if ($host === '' || $port <= 0 || $user === '' || $pass === '') {
        fwrite(STDERR, "[WARN] Skipping mailbox '{$label}' – IMAP or MAIL_* env vars not fully set.\n");
        return;
    }

    global $hookUrl, $hookToken;

    $mailbox = pf_build_imap_mailbox($host, $port, $encryption);

    if (!function_exists('imap_open')) {
        fwrite(STDERR, "[FATAL] PHP IMAP extension not enabled. Cannot process mailbox '{$label}'.\n");
        exit(1);
    }

    fwrite(STDOUT, "[INFO] Connecting to {$label} mailbox: {$mailbox}\n");

    $inbox = @imap_open($mailbox, $user, $pass);
    if ($inbox === false) {
        $err = imap_last_error();
        fwrite(STDERR, "[ERROR] imap_open failed for '{$label}': {$err}\n");
        return;
    }

    // Fetch unseen messages only
    $emails = imap_search($inbox, 'UNSEEN', SE_UID);
    if ($emails === false || empty($emails)) {
        fwrite(STDOUT, "[INFO] No unseen messages in '{$label}' mailbox.\n");
        imap_close($inbox);
        return;
    }

    fwrite(STDOUT, "[INFO] Found " . count($emails) . " unseen message(s) in '{$label}'.\n");

    foreach ($emails as $uid) {
        $msgNo  = imap_msgno($inbox, $uid);
        $header = imap_headerinfo($inbox, $msgNo);

        $from    = '';
        $to      = '';
        $subject = '';

        if (!empty($header->from) && is_array($header->from)) {
            $f = $header->from[0];
            $from = ($f->mailbox ?? '') . '@' . ($f->host ?? '');
        }

        if (!empty($header->to) && is_array($header->to)) {
            $t = $header->to[0];
            $to = ($t->mailbox ?? '') . '@' . ($t->host ?? '');
        }

        $subject = imap_utf8($header->subject ?? '');

        $body = imap_body($inbox, $msgNo);
        if (!is_string($body)) {
            $body = '';
        }

        fwrite(STDOUT, "\n[INFO] Processing UID {$uid} ({$label})\n");
        fwrite(STDOUT, "  From:    {$from}\n");
        fwrite(STDOUT, "  To:      {$to}\n");
        fwrite(STDOUT, "  Subject: {$subject}\n");

        if ($dryRun) {
            fwrite(STDOUT, "  [DRY-RUN] Would forward this email to hook and NOT delete it.\n");
            continue;
        }

        [$ok, $error] = pf_forward_to_hook(
            $hookUrl,
            $hookToken,
            $from,
            ($to !== '' ? $to : $user),
            $subject,
            $body
        );

        if ($ok) {
            fwrite(STDOUT, "  [OK] Forwarded to hook, marking for deletion.\n");

            // Convert UID → string for IMAP
            $uidStr = (string)$uid;

            // Delete by UID (correct + safe)
            if (!imap_delete($inbox, $uidStr, FT_UID)) {
                fwrite(STDERR, "  [ERROR] Failed to delete UID {$uidStr}.\n");
            } else {
                fwrite(STDOUT, "  [INFO] Marked UID {$uidStr} for deletion.\n");
            }
        }

    }

    if (!$dryRun) {
        imap_expunge($inbox);
    }

    imap_close($inbox);
    fwrite(STDOUT, "[INFO] Finished mailbox '{$label}'.\n");
}

// ---------------------------------------------------------
// 5. Run both mailboxes
// ---------------------------------------------------------
fwrite(STDOUT, "[INFO] Plainfully Email Bridge starting (dry-run=" . ($dryRun ? 'yes' : 'no') . ")\n");

pf_process_mailbox('scamcheck', $dryRun);
pf_process_mailbox('clarify',   $dryRun);

fwrite(STDOUT, "[INFO] Plainfully Email Bridge completed.\n");
