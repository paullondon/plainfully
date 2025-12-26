<?php declare(strict_types=1);

/**
 * mailer.php
 *
 * PHPMailer wrapper for Plainfully.
 *
 * Expects:
 *  - PHPMailer source files to exist under app/support/phpmailer/
 *    (PHPMailer.php, SMTP.php, Exception.php)
 *  - SMTP config is read from global $config['smtp'] (loaded via config/app.php)
 *
 * Notes:
 *  - `use` statements MUST appear before executable code in PHP files.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Local templates (kept as you had it)
require_once __DIR__ . '/email_templates.php';

function pf_email_template(string $title, string $bodyHtml): string
{
    return '
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . htmlspecialchars($title, ENT_QUOTES, "UTF-8") . '</title>
</head>
<body style="
  margin:0;
  padding:0;
  background:#f5f7fa;
  font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
  color:#111827;
">

  <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
    <tr>
      <td align="center" style="padding:24px;">
        <table width="100%" cellpadding="0" cellspacing="0" style="
          max-width:560px;
          background:#ffffff;
          border-radius:12px;
          box-shadow:0 10px 25px rgba(0,0,0,0.06);
        ">

          <!-- Header -->
          <tr>
            <td style="padding:24px 28px 12px;">
              <h1 style="
                margin:0;
                font-size:20px;
                font-weight:700;
                letter-spacing:-0.02em;
              ">
                Plainfully
              </h1>
              <p style="
                margin:4px 0 0;
                font-size:14px;
                color:#6b7280;
              ">
                Clear answers. Fewer worries.
              </p>
            </td>
          </tr>

          <!-- Divider -->
          <tr>
            <td style="padding:0 28px;">
              <hr style="border:none;border-top:1px solid #e5e7eb;">
            </td>
          </tr>

          <!-- Content -->
          <tr>
            <td style="padding:24px 28px;font-size:15px;line-height:1.6;">
              ' . $bodyHtml . '
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding:20px 28px 24px;font-size:13px;color:#6b7280;">
              <p style="margin:0;">
                Sent by <strong>Plainfully</strong><br>
                Operated by Hissing Goat Studios
              </p>
              <p style="margin:8px 0 0;">
                If you didn’t request this, you can safely ignore this email.
              </p>
            </td>
          </tr>

        </table>

        <!-- Legal -->
        <p style="
          margin:16px 0 0;
          font-size:12px;
          color:#9ca3af;
        ">
          © ' . date('Y') . ' Hissing Goat Studios · plainfully.com
        </p>
      </td>
    </tr>
  </table>

</body>
</html>';
}

// ---------------------------------------------------------
// Load PHPMailer classes without Composer
// ---------------------------------------------------------

if (!class_exists(PHPMailer::class)) {
    // Your structure: app/support/phpmailer/{PHPMailer,SMTP,Exception}.php
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
    $msg = 'PHPMailer is not available. '
         . 'Ensure PHPMailer.php, SMTP.php and Exception.php '
         . 'exist in app/support/phpmailer on the server.';
    throw new RuntimeException($msg);
}

/**
 * Send an email via PHPMailer, using global $config['smtp'].
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

        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;

        // Deliverability hints
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
d