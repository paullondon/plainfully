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
    $env = strtolower(getenv('APP_ENV') ?: 'local');

    $token  = trim($token ?? '');
    $secret = getenv('TURNSTILE_SECRET_KEY') ?: '';

    // In non-live envs, don't block login if token/secret are missing.
    if ($env !== 'live' && $env !== 'production') {
        if ($token === '' || $secret === '') {
            return true;
        }
    }

    // In live/prod, token and secret must both be present.
    if ($token === '' || $secret === '') {
        return false;
    }

    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

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
            // If verify call itself fails, only hard-fail in live
            return ($env !== 'live' && $env !== 'production');
        }

        $data = json_decode($result, true, 16, JSON_THROW_ON_ERROR);
        $ok   = isset($data['success']) && $data['success'] === true;

        if (!$ok && $env !== 'live' && $env !== 'production') {
            // In dev, don't block on Turnstile failures
            return true;
        }

        return $ok;
    } catch (Throwable $e) {
        // Network/JSON errors: don't block in dev, do block in live
        return ($env !== 'live' && $env !== 'production');
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