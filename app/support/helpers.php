<?php declare(strict_types=1);

/**
 * Global helper functions for Plainfully
 *  - Redirects
 *  - Email normalisation
 *  - Token generation
 *  - Turnstile verification
 *  - Email sending
 */

function pf_redirect(string $path, int $status = 302): never
{
    header('Location: ' . $path, true, $status);
    exit;
}

/**
 * Normalise and validate an email address.
 */
function pf_normalise_email(string $email): ?string
{
    $email = trim(mb_strtolower($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return $email;
}

/**
 * Generate secure random magic-link token.
 */
function pf_generate_magic_token(): string
{
    return bin2hex(random_bytes(32)); // 64-char hex
}

/**
 * Verify Cloudflare Turnstile token server-side.
 */
function pf_verify_turnstile(string $token = null): bool
{
    $token = trim($token ?? '');
    if ($token === '') {
        return false;
    }

    $config = require dirname(__DIR__, 2) . '/config/app.php';
    $secret = $config['security']['turnstile_secret_key'] ?? '';
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

    if ($secret === '') {
        return false; // fail-safe
    }

    $payload = http_build_query([
        'secret'   => $secret,
        'response' => $token,
        'remoteip' => $remoteIp,
    ]);

    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 5,
        ],
    ];

    $context = stream_context_create($options);

    try {
        $result = file_get_contents(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            false,
            $context
        );

        if ($result === false) {
            return false;
        }

        $data = json_decode($result, true, 16, JSON_THROW_ON_ERROR);
        return isset($data['success']) && $data['success'] === true;

    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Send magic-link email via PHPMailer wrapper.
 */
function pf_send_magic_link_email(string $toEmail, string $link): bool
{
    $subject = 'Your Plainfully sign-in link';

    $body = "Hi,\n\n"
        . "Use the link below to sign in to Plainfully. "
        . "It will expire shortly and can only be used once.\n\n"
        . $link . "\n\n"
        . "If you did not request this, you can ignore this email.\n";

    return pf_send_email($toEmail, $subject, $body);
}