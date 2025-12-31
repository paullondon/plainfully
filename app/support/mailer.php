<?php declare(strict_types=1);

/**
 * mailer.php
 *
 * PHPMailer wrapper for Plainfully.
 *
 * Purpose:
 *  - Centralizes email sending + HTML shell rendering.
 *  - Provides consistent deliverability headers.
 *  - Adds robust logo support:
 *      * Template uses a public HTTPS PNG URL
 *      * If the PNG exists locally, we embed it as a CID image so it renders
 *        even when remote images are blocked by the email client.
 *
 * Expects:
 *  - PHPMailer source files under app/support/phpmailer/
 *    (PHPMailer.php, SMTP.php, Exception.php)
 *  - SMTP config from global $config['smtp'] (loaded via config/app.php)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Local builders (inner HTML/text builders)
require_once __DIR__ . '/email_templates.php';

/**
 * Global email shell.
 *
 * IMPORTANT:
 * - Keep this as “dumb HTML” (no external CSS).
 * - Logo uses PNG URL because SVG is unreliable in email clients.
 * - CID embedding (if available) is handled later inside pf_mail_send()
 *   by rewriting the logo URL to cid:plainfully-logo.
 */
function pf_email_template(string $subject, string $innerHtml): string
{
    $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');

    // PNG for email reliability
    $logoUrl = 'https://plainfully.com/assets/img/plainfully-logo-light.256.png';

    return '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>' . $safeSubject . '</title>
</head>
<body style="
  margin:0;
  padding:0;
  background:#0b0f14;
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
  color:#e5e7eb;
">
  <div style="max-width:640px;margin:0 auto;padding:28px 20px;">

    <!-- Header -->
    <div style="
      display:flex;
      align-items:center;
      gap:12px;
      margin-bottom:18px;
    ">
      <img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '"
           width="36" height="36"
           alt="Plainfully"
           style="display:block;border:0;">
      <div>
        <div style="font-weight:700;font-size:16px;line-height:1;color:#ffffff;">
          Plainfully
        </div>
        <div style="font-size:13px;color:#9ca3af;">
          Clear answers. Fewer worries.
        </div>
      </div>
    </div>

    <!-- Card -->
    <div style="
      background:#111827;
      border:1px solid #1f2937;
      border-radius:16px;
      padding:22px;
      color:#e5e7eb;
    ">
      ' . $innerHtml . '
    </div>

    <!-- Footer -->
    <div style="
      color:#9ca3af;
      font-size:12px;
      margin-top:16px;
    ">
      You’re receiving this because you used Plainfully via email.<br>
      Operated by Hissing Goat Studios.
    </div>

  </div>
</body>
</html>';
}


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
    $msg = 'PHPMailer is not available. Ensure PHPMailer.php, SMTP.php and Exception.php exist in app/support/phpmailer.';
    throw new RuntimeException($msg);
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
 * Send an email via PHPMailer, using global $config['smtp'].
 *
 * Security:
 * - Validates recipient email (fail-closed).
 * - No dynamic headers from user input.
 * - Best-effort CID embedding using a local PNG (prevents “blocked images” issue).
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
    $smtp = $config['smtp'] ?? [];

    // Basic input hardening (fail-closed)
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('pf_mail_send error: invalid recipient');
        return false;
    }

    try {
        $mail = new PHPMailer(true);

        // SMTP
        $mail->isSMTP();
        $mail->Host       = (string)($smtp['host'] ?? '');
        $mail->SMTPAuth   = true;
        $mail->Username   = $fromUser;
        $mail->Password   = $fromPass;
        $mail->SMTPSecure = (string)($smtp['secure'] ?? 'tls'); // 'tls' or 'ssl'
        $mail->Port       = (int)($smtp['port'] ?? 587);

        // Message
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);

        // From / Sender (set BEFORE recipients)
        $mail->setFrom($fromUser, 'Plainfully');
        $mail->Sender = $fromUser;

        // Recipient
        $mail->addAddress($to);

        // Content
        if ($text === null) {
            $text = trim(strip_tags($html));
        }

        // -----------------------------------------------------
        // Logo support (CID embedding)
        // -----------------------------------------------------
        // Many email clients block remote images by default.
        // If we can embed the logo as a CID, it renders immediately.
        //
        // We do this safely by:
        //  1) Checking for a local file on disk (controlled by you)
        //  2) addEmbeddedImage(...)
        //  3) Rewriting the known HTTPS logo URL to cid:plainfully-logo
        //
        // If the local file is missing, we simply leave the HTTPS URL in place.
        $logoUrlMarker = 'https://plainfully.com/assets/img/plainfully-logo-light.256.png';
        $logoPath = pf_local_logo_path();
        if ($logoPath !== null) {
            $cid = 'plainfully-logo';
            $mail->addEmbeddedImage($logoPath, $cid, 'plainfully-logo-light.256.png', 'base64', 'image/png');

            // Replace ONLY the known marker URL to avoid unintended replacements.
            $html = str_replace($logoUrlMarker, 'cid:' . $cid, $html);
        }

        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;

        // Deliverability hints (safe static headers)
        $mail->addCustomHeader('X-Mailer', 'Plainfully');
        $mail->addCustomHeader('List-Unsubscribe', '<mailto:unsubscribe@plainfully.com>');

        return $mail->send();

    } catch (Throwable $e) {
        error_log('pf_mail_send error: ' . $e->getMessage());
        return false;
    }
}

function pf_mail_noreply(string $to, string $subject, string $html, ?string $text = null): bool
{
    global $config;
    $smtp = $config['smtp'];

    return pf_mail_send(
        (string)$smtp['noreply_user'],
        (string)$smtp['noreply_pass'],
        $to,
        $subject,
        $html,
        $text
    );
}

function pf_mail_scamcheck(string $to, string $subject, string $html, ?string $text = null): bool
{
    global $config;
    $smtp = $config['smtp'];

    return pf_mail_send(
        (string)$smtp['scamcheck_user'],
        (string)$smtp['scamcheck_pass'],
        $to,
        $subject,
        $html,
        $text
    );
}

function pf_mail_clarify(string $to, string $subject, string $html, ?string $text = null): bool
{
    global $config;
    $smtp = $config['smtp'];

    return pf_mail_send(
        (string)$smtp['clarify_user'],
        (string)$smtp['clarify_pass'],
        $to,
        $subject,
        $html,
        $text
    );
}

if (!function_exists('pf_send_email')) {
    /**
     * Plainfully email wrapper used by newer features.
     *
     * $channel:
     *   - 'scamcheck' → sends from scamcheck@...
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
 * This routes to pf_mail_noreply() and returns ONLY true/false.
 */
if (!function_exists('pf_send_magic_link_email')) {
    function pf_send_magic_link_email(string $to, string $link): bool
    {
        $subject = 'Your Plainfully sign-in link';

        $inner = '<p>Hello,</p>'
            . '<p>Here\'s your one-time link to sign in to <strong>Plainfully</strong>:</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Sign in to Plainfully</a></p>'
            . '<p style="color:#6b7280;font-size:13px;margin:16px 0 0;">'
            . 'This link expires shortly and can only be used once.'
            . '</p>';

        $html = pf_email_template('Your Plainfully sign-in link', $inner);

        $text = "Hello,\n\n"
              . "Here is your one-time link to sign in to Plainfully:\n\n"
              . $link . "\n\n"
              . "This link will expire shortly and can only be used once.\n"
              . "If you did not request this email, you can safely ignore it.\n";

        return pf_mail_noreply($to, $subject, $html, $text);
    }
}
