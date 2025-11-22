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
    $env = getenv('APP_ENV') ?: 'local';
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
function pf_send_email(string $toEmail, string $subject, string $body): bool
{
    $config = require __DIR__ . '/../../config/app.php';

    $mailCfg = $config['mail'];
    $smtp    = $config['smtp'];

    $mailer = new PHPMailer(true);

    try {
        // Server settings
        $mailer->isSMTP();
        $mailer->Host       = $smtp['host'];
        $mailer->Port       = $smtp['port'];
        $mailer->SMTPAuth   = $smtp['auth'];
        $mailer->Username   = $smtp['username'];
        $mailer->Password   = $smtp['password'];

        if ($smtp['secure'] !== '') {
            $mailer->SMTPSecure = $smtp['secure']; // 'tls' or 'ssl'
        }

        $mailer->CharSet    = 'UTF-8';

        // From / To
        $mailer->setFrom(
            $mailCfg['from_email'],
            $mailCfg['from_name']
        );
        $mailer->addAddress($toEmail);

        // Content
        $mailer->Subject = $subject;
        $mailer->Body    = $body;
        $mailer->isHTML(false); // plain-text for now

        $mailer->send();
        return true;
    } catch (Exception $e) {
        // In production: log $e->getMessage() somewhere safe
        return false;
    }
}
