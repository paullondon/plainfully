<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully Mailer
 * ============================================================
 * File: app/core/mailer.php
 * Purpose:
 *   Central outbound email handling using PHPMailer.
 *
 * Design principles:
 * - Soft-fail to callers (bool / [ok, error])
 * - Always log failures server-side
 * - No user-controlled headers
 * - Multiple sender identities supported
 * ============================================================
 */

// PHPMailer is optional in this repo export.
// In production, prefer PHPMailer (SMTP) but fall back to PHP's mail()
// if PHPMailer is not present.

// ---------------------------------------------------------
// Local builders (email HTML/text templates)
// ---------------------------------------------------------
require_once __DIR__ . '/email_templates.php';

// ---------------------------------------------------------
// Load PHPMailer classes (no Composer)
// ---------------------------------------------------------
if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    // Try core/phpmailer first, then legacy support/phpmailer
    $base = __DIR__ . '/phpmailer';
    if (!is_dir($base)) {
        $base = dirname(__DIR__) . '/support/phpmailer';
    }

    foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $file) {
        $path = $base . '/' . $file;
        if (is_readable($path)) {
            require_once $path;
        }
    }
}

define('PF_HAS_PHPMAILER', class_exists('PHPMailer\\PHPMailer\\PHPMailer'));

/**
 * Safely fetch first non-empty env var from list.
 */
function pf_env_first(array $keys): string
{
    foreach ($keys as $k) {
        $v = getenv((string)$k);
        if ($v !== false) {
            $v = trim((string)$v);
            if ($v !== '') {
                return $v;
            }
        }
    }
    return '';
}

/**
 * Fetch integer env var from first non-empty key.
 */
function pf_env_first_int(array $keys, int $default): int
{
    $v = pf_env_first($keys);
    $i = (int)$v;
    return $i > 0 ? $i : $default;
}

/**
 * Locate local logo PNG for CID embedding.
 */
function pf_local_logo_path(): ?string
{
    $path = realpath(dirname(__DIR__, 2) . '/assets/img/plainfully-logo-light.256.png');
    return ($path && is_readable($path)) ? $path : null;
}

/**
 * Build normalised SMTP config.
 */
function pf_smtp_config(string $fromUser = '', string $fromPass = ''): array
{
    $cfg = is_array($GLOBALS['config']['smtp'] ?? null) ? $GLOBALS['config']['smtp'] : [];

    $host = trim((string)($cfg['host'] ?? ''));
    if ($host === '') {
        $host = pf_env_first(['MAIL_HOST', 'SMTP_HOST']);
    }

    $port = (int)($cfg['port'] ?? 0);
    if ($port <= 0) {
        $port = pf_env_first_int(['MAIL_PORT', 'SMTP_PORT'], 587);
    }

    $secure = trim((string)($cfg['secure'] ?? ''));
    if ($secure === '') {
        $secure = pf_env_first(['MAIL_ENCRYPTION', 'SMTP_SECURE']) ?: 'tls';
    }

    return [
        'host'   => $host,
        'port'   => $port,
        'secure' => $secure,
        'user'   => trim($fromUser),
        'pass'   => (string)$fromPass,
    ];
}

/**
 * Low-level send function.
 */
function pf_mail_send(
    string $fromUser,
    string $fromPass,
    string $to,
    string $subject,
    string $html,
    ?string $text = null
): bool {
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('pf_mail_send: invalid recipient');
        return false;
    }

    $smtp = pf_smtp_config($fromUser, $fromPass);

    // --------------------------------------------------------
    // Fallback path: PHPMailer not available OR no SMTP host.
    // This keeps the app functional on minimal hosting exports.
    // --------------------------------------------------------
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer') || ($smtp['host'] ?? '') === '') {
        $boundary = 'pf_' . bin2hex(random_bytes(12));

        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'From: Plainfully <' . $fromUser . '>';
        $headers[] = 'Reply-To: ' . $fromUser;
        $headers[] = 'X-Mailer: Plainfully';
        $headers[] = 'List-Unsubscribe: <mailto:unsubscribe@plainfully.com>';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $safeText = $text ?? trim(strip_tags($html));

        $body = "--{$boundary}\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n\r\n" .
            $safeText . "\r\n\r\n" .
            "--{$boundary}\r\n" .
            "Content-Type: text/html; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n\r\n" .
            $html . "\r\n\r\n" .
            "--{$boundary}--\r\n";

        try {
            return (bool)mail($to, $subject, $body, implode("\r\n", $headers));
        } catch (\Throwable $e) {
            error_log('pf_mail_send mail() fallback failed: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------
    // PHPMailer path (preferred)
    // --------------------------------------------------------
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $smtp['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp['user'];
        $mail->Password   = $smtp['pass'];
        $mail->SMTPSecure = $smtp['secure'];
        $mail->Port       = $smtp['port'];

        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);

        $mail->setFrom($fromUser, 'Plainfully');
        $mail->Sender = $fromUser;
        $mail->addAddress($to);

        if ($text === null) {
            $text = trim(strip_tags($html));
        }

        // CID logo embedding (optional)
        $logoUrl = 'https://plainfully.com/assets/img/plainfully-logo-light.256.png';
        $logoPath = pf_local_logo_path();
        if ($logoPath) {
            $cid = 'plainfully-logo';
            $mail->addEmbeddedImage($logoPath, $cid, 'plainfully-logo.png', 'base64', 'image/png');
            $html = str_replace($logoUrl, 'cid:' . $cid, $html);
        }

        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;

        $mail->addCustomHeader('X-Mailer', 'Plainfully');
        $mail->addCustomHeader('List-Unsubscribe', '<mailto:unsubscribe@plainfully.com>');

        return (bool)$mail->send();
    } catch (\Throwable $e) {
        error_log('pf_mail_send error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Channel helpers
 */
function pf_mail_noreply(string $to, string $subject, string $html, ?string $text = null): bool
{
    $smtp = is_array($GLOBALS['config']['smtp'] ?? null) ? $GLOBALS['config']['smtp'] : [];
    $user = (string)($smtp['noreply_user'] ?? pf_env_first(['MAIL_NOREPLY_USER', 'SMTP_NOREPLY_USER']));
    $pass = (string)($smtp['noreply_pass'] ?? pf_env_first(['MAIL_NOREPLY_PASS', 'SMTP_NOREPLY_PASS']));
    return pf_mail_send($user, $pass, $to, $subject, $html, $text);
}

function pf_mail_scamcheck(string $to, string $subject, string $html, ?string $text = null): bool
{
    $smtp = is_array($GLOBALS['config']['smtp'] ?? null) ? $GLOBALS['config']['smtp'] : [];
    $user = (string)($smtp['scamcheck_user'] ?? pf_env_first(['MAIL_SCAMCHECK_USER', 'SMTP_SCAMCHECK_USER']));
    $pass = (string)($smtp['scamcheck_pass'] ?? pf_env_first(['MAIL_SCAMCHECK_PASS', 'SMTP_SCAMCHECK_PASS']));
    return pf_mail_send($user, $pass, $to, $subject, $html, $text);
}

function pf_mail_clarify(string $to, string $subject, string $html, ?string $text = null): bool
{
    $smtp = is_array($GLOBALS['config']['smtp'] ?? null) ? $GLOBALS['config']['smtp'] : [];
    $user = (string)($smtp['clarify_user'] ?? pf_env_first(['MAIL_CLARIFY_USER', 'SMTP_CLARIFY_USER']));
    $pass = (string)($smtp['clarify_pass'] ?? pf_env_first(['MAIL_CLARIFY_PASS', 'SMTP_CLARIFY_PASS']));
    return pf_mail_send($user, $pass, $to, $subject, $html, $text);
}

/**
 * High-level wrapper used by controllers/features.
 */
function pf_send_email(
    string $to,
    string $subject,
    string $html,
    string $channel = 'noreply',
    ?string $text = null
): array {
    try {
        $ok = match ($channel) {
            'scamcheck' => pf_mail_scamcheck($to, $subject, $html, $text),
            'clarify'   => pf_mail_clarify($to, $subject, $html, $text),
            default     => pf_mail_noreply($to, $subject, $html, $text),
        };

        return $ok ? [true, null] : [false, 'mail send failed'];
    } catch (\Throwable $e) {
        error_log('pf_send_email failed: ' . $e->getMessage());
        return [false, $e->getMessage()];
    }
}
