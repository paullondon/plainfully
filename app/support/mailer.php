<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/support/mailer.php
 * Purpose:
 *   PHPMailer wrapper for Plainfully.
 *
 * What this does:
 *   - Centralises outbound email sending (SMTP) for the app.
 *   - Provides a single function `pf_send_email()` used by the rest of the codebase.
 *   - Supports CID-embedded logo (so the logo can render even if remote images are blocked).
 *
 * Security:
 *   - Validates recipient email (fail-closed).
 *   - Uses only static headers (no user-controlled headers).
 *   - Catches and logs errors; callers get a safe success/fail result.
 *
 * Notes:
 *   - HTML rendering / dark-mode adaptation is handled in app/support/email_templates.php
 *   - PHPMailer is loaded without Composer from app/support/phpmailer/
 * ============================================================
 */

use PHPMailer\PHPMailer\PHPMailer;

// Local builders (HTML shell + inner builders)
require_once __DIR__ . '/email_templates.php';

// ---------------------------------------------------------
// Load PHPMailer classes without Composer
// ---------------------------------------------------------
if (!class_exists(PHPMailer::class)) {
    $base = __DIR__ . '/phpmailer';

    $files = [
        $base . '/Exception.php',
        $base . '/PHPMailer.php',
        $base . '/SMTP.php',
    ];

    foreach ($files as $file) {
        if (is_readable($file)) {
            require_once $file;
        }
    }
}

if (!class_exists(PHPMailer::class)) {
    throw new RuntimeException(
        'PHPMailer missing. Ensure Exception.php, PHPMailer.php and SMTP.php exist in app/support/phpmailer/'
    );
}

/**
 * Attempt to find the local logo PNG on disk for CID embedding.
 *
 * Expected location on your server:
 *   httpdocs/assets/img/plainfully-logo-light.256.png
 *
 * This file lives at:
 *   httpdocs/app/support/mailer.php
 *
 * So the logo path is:
 *   __DIR__ (app/support) -> ../../.. -> httpdocs -> assets/img/plainfully-logo-light.256.png
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
 * Send an email via PHPMailer, using global $config['smtp'] for host/port/etc.
 *
 * IMPORTANT:
 * - `$fromUser` and `$fromPass` select the mailbox identity (noreply/clarify/etc).
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
    global $config;

    $smtp = is_array($config['smtp'] ?? null) ? $config['smtp'] : [];

    // Fail-closed on invalid recipient.
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('pf_mail_send: invalid recipient email');
        return false;
    }

    // Fail-closed if SMTP basics are missing (prevents confusing partial sends).
    $host = (string)($smtp['host'] ?? '');
    if ($host === '') {
        error_log('pf_mail_send: SMTP host missing in config');
        return false;
    }

    try {
        $mail = new PHPMailer(true);

        // SMTP transport
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $fromUser;
        $mail->Password   = $fromPass;
        $mail->SMTPSecure = (string)($smtp['secure'] ?? 'tls'); // 'tls' or 'ssl'
        $mail->Port       = (int)($smtp['port'] ?? 587);

        // Message basics
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);

        // From / Sender (set BEFORE recipients)
        $mail->setFrom($fromUser, 'Plainfully');
        $mail->Sender = $fromUser;

        // Recipient
        $mail->addAddress($to);

        // Text fallback (if not provided)
        if ($text === null) {
            $text = trim(strip_tags($html));
        }

        // -----------------------------------------------------
        // Logo support (CID embedding)
        // -----------------------------------------------------
        // If a local logo exists, embed it as CID and rewrite the known URL to cid:plainfully-logo.
        // If not, keep the HTTPS URL so clients that allow remote images can still load it.
        $logoUrlMarker = 'https://plainfully.com/assets/img/plainfully-logo-light.256.png';
        $logoPath = pf_local_logo_path();

        if ($logoPath !== null) {
            $cid = 'plainfully-logo';
            $mail->addEmbeddedImage($logoPath, $cid, 'plainfully-logo-light.256.png', 'base64', 'image/png');
            $html = str_replace($logoUrlMarker, 'cid:' . $cid, $html);
        }

        // Subject / bodies
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;

        // Deliverability + hygiene (static headers only)
        $mail->addCustomHeader('X-Mailer', 'Plainfully');
        $mail->addCustomHeader('List-Unsubscribe', '<mailto:unsubscribe@plainfully.com>');

        return $mail->send();
    } catch (Throwable $e) {
        error_log('pf_mail_send: ' . $e->getMessage());
        return false;
    }
}

/**
 * Convenience mailboxes
 * (Keep these wrappers small so you can change mailbox strategy in one place.)
 */
function pf_mail_noreply(string $to, string $subject, string $html, ?string $text = null): bool
{
    global $config;
    $smtp = $config['smtp'] ?? [];

    return pf_mail_send(
        (string)($smtp['noreply_user'] ?? ''),
        (string)($smtp['noreply_pass'] ?? ''),
        $to,
        $subject,
        $html,
        $text
    );
}

function pf_mail_clarify(string $to, string $subject, string $html, ?string $text = null): bool
{
    global $config;
    $smtp = $config['smtp'] ?? [];

    return pf_mail_send(
        (string)($smtp['clarify_user'] ?? ''),
        (string)($smtp['clarify_pass'] ?? ''),
        $to,
        $subject,
        $html,
        $text
    );
}

if (!function_exists('pf_send_email')) {
    /**
     * Primary send wrapper used by the rest of Plainfully.
     *
     * $channel:
     *   - 'clarify'   → sends from clarify@...
     *   - anything else / default → sends from noreply@...
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
            $ok = false;

            switch ($channel) {
                case 'clarify':
                    $ok = pf_mail_clarify($to, $subject, $html, $text);
                    break;

                default:
                    $ok = pf_mail_noreply($to, $subject, $html, $text);
                    break;
            }

            if (!$ok) {
                return [false, 'pf_mail_* returned false'];
            }

            return [true, null];
        } catch (Throwable $e) {
            error_log('pf_send_email failed: ' . $e->getMessage());
            return [false, $e->getMessage()];
        }
    }
}

/**
 * Magic-link emails MUST return bool because the login flow expects it.
 */
if (!function_exists('pf_send_magic_link_email')) {
    function pf_send_magic_link_email(string $to, string $link): bool
    {
        $subject = 'Your Plainfully sign-in link';

        $inner = '<p>Hello,</p>'
            . '<p>Here\'s your one-time link to sign in to <strong>Plainfully</strong>:</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Sign in to Plainfully</a></p>'
            . '<p style="color:var(--pf-text-muted,#6b7280);font-size:13px;margin:16px 0 0;">'
            . 'This link expires shortly and can only be used once.'
            . '</p>';

        $html = pf_email_template($subject, $inner);

        $text = "Hello,\n\n"
              . "Here is your one-time link to sign in to Plainfully:\n\n"
              . $link . "\n\n"
              . "This link will expire shortly and can only be used once.\n"
              . "If you did not request this email, you can safely ignore it.\n";

        return pf_mail_noreply($to, $subject, $html, $text);
    }
}
