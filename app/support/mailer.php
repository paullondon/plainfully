<?php declare(strict_types=1);

/**
 * mailer.php
 *
 * PHPMailer wrapper for Plainfully.
 *
 * Expects:
 *  - PHPMailer source files to exist under app/support/phpmailer/
 *    (PHPMailer.php, SMTP.php, Exception.php)
 *  - MAIL_* env vars as per your .env (host, port, user, pass, etc.)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
 * Send an email via PHPMailer, using config/app.php + .env.
 *
 * @param string $toEmail Recipient email
 * @param string $subject Subject line
 * @param string $body Plain-text body
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
    $smtp = $config['smtp'];

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $smtp['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $fromUser;
        $mail->Password   = $fromPass;
        $mail->SMTPSecure = $smtp['secure'];
        $mail->Port       = $smtp['port'];

        $mail->setFrom($fromUser, 'Plainfully');
        $mail->addAddress($to);

        if ($text === null) {
            $text = strip_tags($html);
        }

        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;

        return $mail->send();
    } catch (Throwable $e) {
        error_log("pf_mail_send error: " . $e->getMessage());
        return false;
    }
}

function pf_mail_noreply(string $to, string $subject, string $html, ?string $text = null): bool
{
    global $config;
    $smtp = $config['smtp'];

    return pf_mail_send(
        $smtp['noreply_user'],
        $smtp['noreply_pass'],
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
        $smtp['scamcheck_user'],
        $smtp['scamcheck_pass'],
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
        $smtp['clarify_user'],
        $smtp['clarify_pass'],
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
 * This simply routes to pf_mail_noreply() and returns ONLY true/false.
 */
if (!function_exists('pf_send_magic_link_email')) {
    /**
     * Send a Plainfully magic sign-in link.
     *
     * @param string $to   Recipient email
     * @param string $link Fully-formed magic link URL
     *
     * @return bool True on success, false on failure
     */
    function pf_send_magic_link_email(string $to, string $link): bool
    {
        $subject = 'Your Plainfully sign-in link';

        // Simple HTML body (you can prettify later)
        $html = '<p>Hello,</p>'
              . '<p>Here\'s your one-time link to sign in to Plainfully:</p>'
              . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">'
              . htmlspecialchars($link, ENT_QUOTES, 'UTF-8')
              . '</a></p>'
              . '<p>This link will expire shortly and can only be used once.</p>'
              . '<p>If you did not request this email, you can safely ignore it.</p>';

        $text = "Hello,\n\n"
              . "Here is your one-time link to sign in to Plainfully:\n\n"
              . $link . "\n\n"
              . "This link will expire shortly and can only be used once.\n"
              . "If you did not request this email, you can safely ignore it.\n";

        // Uses noreply@… SMTP as you already configured
        return pf_mail_noreply($to, $subject, $html, $text);
    }
}
