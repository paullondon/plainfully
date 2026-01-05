<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/support/mailer.php
 * Purpose:
 *   PHPMailer wrapper for Plainfully.
 *
 * Why this file exists:
 *   - Centralises outbound SMTP config + sending
 *   - Supports BOTH env naming schemes:
 *       MAIL_* (preferred)
 *       SMTP_* (fallback)
 *
 * Security:
 *   - Validates recipient email (fail-closed)
 *   - No dynamic headers from user input
 *   - Optional CID embedding for a locally hosted PNG logo
 * ============================================================
 */

use PHPMailer\PHPMailer\PHPMailer;

// Local builders (inner HTML/text builders)
require_once __DIR__ . '/email_templates.php';

// ---------------------------------------------------------
// Load PHPMailer classes without Composer
// ---------------------------------------------------------
if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    $base = __DIR__ . '/phpmailer';

    $files = [
        $base . '/Exception.php',
        $base . '/PHPMailer.php',
        $base . '/SMTP.php',
    ];

    foreach ($files as $file) {
        if (is_readable($file)) { require_once $file; }
    }
}

if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    throw new RuntimeException(
        'PHPMailer is not available. Ensure PHPMailer.php, SMTP.php and Exception.php exist in app/support/phpmailer.'
    );
}

/**
 * Safely fetch an env var (supports multiple keys).
 * Returns '' if not found / empty.
 */
function pf_env_first(array $keys): string
{
    foreach ($keys as $k) {
        $v = getenv((string)$k);
        if ($v !== false) {
            $v = trim((string)$v);
            if ($v !== '') { return $v; }
        }
    }
    return '';
}

/**
 * Fetch int env var from first non-empty key (with default).
 */
function pf_env_first_int(array $keys, int $default): int
{
    $v = pf_env_first($keys);
    if ($v === '') { return $default; }
    $i = (int)$v;
    return $i > 0 ? $i : $default;
}

/**
 * Attempt to find the local logo PNG on disk for CID embedding.
 *
 * Expected location:
 *   httpdocs/assets/img/plainfully-logo-light.256.png
 */
function pf_local_logo_path(): ?string
{
    $candidate = realpath(__DIR__ . '/../../..' . '/assets/img/plainfully-logo-light.256.png');
    if ($candidate !== false && is_file($candidate) && is_readable($candidate)) {
        return $candidate;
    }
    return null;
}

/**
 * Build a normalised SMTP config array.
 *
 * Priority:
 * 1) $config['smtp'] if present (config/app.php)
 * 2) Env vars (MAIL_* first, then SMTP_* fallbacks)
 */
function pf_smtp_config(string $fromUser = '', string $fromPass = ''): array
{
    $cfg = [];
    if (isset($GLOBALS['config']) && is_array($GLOBALS['config'])) {
        $cfg = $GLOBALS['config']['smtp'] ?? [];
        if (!is_array($cfg)) { $cfg = []; }
    }

    $host = trim((string)($cfg['host'] ?? ''));
    if ($host === '') { $host = pf_env_first(['MAIL_HOST', 'SMTP_HOST']); }

    $port = (int)($cfg['port'] ?? 0);
    if ($port <= 0) { $port = pf_env_first_int(['MAIL_PORT', 'SMTP_PORT'], 587); }

    $secure = trim((string)($cfg['secure'] ?? ''));
    if ($secure === '') { $secure = pf_env_first(['MAIL_ENCRYPTION', 'SMTP_SECURE']); }
    if ($secure === '') { $secure = 'tls'; }

    return [
        'host'   => $host,
        'port'   => $port,
        'secure' => $secure,
        'user'   => trim($fromUser),
        'pass'   => (string)$fromPass,
    ];
}

/**
 * Send an email via PHPMailer.
 *
 * @return bool True on success, false on failure.
 */
function pf_mail_send(
    string $fromUser,
    string $fromPass,
    string $to,
    string $subject,
    string $html,
    ?string $text = null
): bool {
    // Basic input hardening (fail-closed)
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('pf_mail_send: invalid recipient');
        return false;
    }

    $smtp = pf_smtp_config($fromUser, $fromPass);

    if (trim((string)$smtp['host']) === '') {
        error_log('pf_mail_send: SMTP host missing in config/env (MAIL_HOST or SMTP_HOST).');
        return false;
    }

    try {
        $mail = new PHPMailer(true);

        // SMTP
        $mail->isSMTP();
        $mail->Host       = (string)$smtp['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = (string)$smtp['user'];
        $mail->Password   = (string)$smtp['pass'];
        $mail->SMTPSecure = (string)$smtp['secure']; // 'tls' or 'ssl'
        $mail->Port       = (int)$smtp['port'];

        // Message
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);

        // From / Sender (set BEFORE recipients)
        $mail->setFrom($fromUser, 'Plainfully');
        $mail->Sender = $fromUser;

        // Recipient
        $mail->addAddress($to);

        // Content
        if ($text === null) { $text = trim(strip_tags($html)); }

        // Logo support (CID embedding)
        $logoUrlMarker = 'https://plainfully.com/assets/img/plainfully-logo-light.256.png';
        $logoPath = pf_local_logo_path();
        if ($logoPath !== null) {
            $cid = 'plainfully-logo';
            $mail->addEmbeddedImage($logoPath, $cid, 'plainfully-logo-light.256.png', 'base64', 'image/png');
            $html = str_replace($logoUrlMarker, 'cid:' . $cid, $html);
        }

        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = (string)$text;

        // Deliverability hints (static headers only)
        $mail->addCustomHeader('X-Mailer', 'Plainfully');
        $mail->addCustomHeader('List-Unsubscribe', '<mailto:unsubscribe@plainfully.com>');

        return (bool)$mail->send();
    } catch (\Throwable $e) {
        error_log('pf_mail_send error: ' . $e->getMessage());
        return false;
    }
}

function pf_mail_noreply(string $to, string $subject, string $html, ?string $text = null): bool
{
    $smtp = (isset($GLOBALS['config']['smtp']) && is_array($GLOBALS['config']['smtp']))
        ? $GLOBALS['config']['smtp']
        : [];

    $user = (string)($smtp['noreply_user'] ?? pf_env_first(['MAIL_NOREPLY_USER', 'SMTP_NOREPLY_USER']));
    $pass = (string)($smtp['noreply_pass'] ?? pf_env_first(['MAIL_NOREPLY_PASS', 'SMTP_NOREPLY_PASS']));

    return pf_mail_send($user, $pass, $to, $subject, $html, $text);
}

function pf_mail_scamcheck(string $to, string $subject, string $html, ?string $text = null): bool
{
    $smtp = (isset($GLOBALS['config']['smtp']) && is_array($GLOBALS['config']['smtp']))
        ? $GLOBALS['config']['smtp']
        : [];

    $user = (string)($smtp['scamcheck_user'] ?? pf_env_first(['MAIL_SCAMCHECK_USER', 'SMTP_SCAMCHECK_USER']));
    $pass = (string)($smtp['scamcheck_pass'] ?? pf_env_first(['MAIL_SCAMCHECK_PASS', 'SMTP_SCAMCHECK_PASS']));

    return pf_mail_send($user, $pass, $to, $subject, $html, $text);
}

function pf_mail_clarify(string $to, string $subject, string $html, ?string $text = null): bool
{
    $smtp = (isset($GLOBALS['config']['smtp']) && is_array($GLOBALS['config']['smtp']))
        ? $GLOBALS['config']['smtp']
        : [];

    $user = (string)($smtp['clarify_user'] ?? pf_env_first(['MAIL_CLARIFY_USER', 'SMTP_CLARIFY_USER']));
    $pass = (string)($smtp['clarify_pass'] ?? pf_env_first(['MAIL_CLARIFY_PASS', 'SMTP_CLARIFY_PASS']));

    return pf_mail_send($user, $pass, $to, $subject, $html, $text);
}

if (!function_exists('pf_send_email')) {
    /**
     * Plainfully email wrapper used by controllers/features.
     *
     * $channel:
     *   - 'scamcheck' → sends from scamcheck@...
     *   - 'clarify'   → sends from clarify@...
     *   - anything else → sends from noreply@...
     *
     * Returns: [bool $ok, ?string $error]
     */
    function pf_send_email(
        string $to,
        string $subject,
        string $html,
        string $channel = 'noreply',
        ?string $text = null
    ): array {
        try {
            switch ($channel) {
                case 'scamcheck':
                    $ok = pf_mail_scamcheck($to, $subject, $html, $text);
                    break;
                case 'clarify':
                    $ok = pf_mail_clarify($to, $subject, $html, $text);
                    break;
                default:
                    $ok = pf_mail_noreply($to, $subject, $html, $text);
                    break;
            }

            if (!$ok) { return [false, 'pf_mail_* returned false']; }
            return [true, null];
        } catch (\Throwable $e) {
            error_log('pf_send_email failed: ' . $e->getMessage());
            return [false, $e->getMessage()];
        }
    }
}
