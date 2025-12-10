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
