<?php declare(strict_types=1);

/**
 * Verify a Cloudflare Turnstile token.
 *
 * Returns [bool $ok, string $reason].
 * In live: you show a generic message.
 * In local: you can show the $reason for debugging.
 */
function pf_turnstile_verify(?string $token): array
{
    $env = strtolower(getenv('APP_ENV') ?: 'local');
    $secret = getenv('TURNSTILE_SECRET') ?: '';

    if ($token === null || $token === '') {
        return [false, 'missing token (cf-turnstile-response not posted)'];
    }

    if ($secret === '') {
        return [false, 'TURNSTILE_SECRET not configured in .env'];
    }

    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;

    $postData = [
        'secret'   => $secret,
        'response' => $token,
    ];
    if ($remoteIp) {
        $postData['remoteip'] = $remoteIp;
    }

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_POSTFIELDS     => http_build_query($postData),
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [false, 'curl error contacting Turnstile: ' . $err];
    }
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [false, 'invalid JSON from Turnstile: ' . substr($raw, 0, 200)];
    }

    $success = !empty($decoded['success']);
    $codes   = $decoded['error-codes'] ?? [];
    if (!$success) {
        $codeList = is_array($codes) ? implode(', ', $codes) : (string)$codes;
        return [false, 'Turnstile reported failure: ' . $codeList];
    }

    return [true, 'ok'];
}
