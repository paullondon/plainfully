<?php declare(strict_types=1);

/**
 * Cloudflare Turnstile verification helper
 *
 * Returns: [bool $ok, string $message]
 */
function pf_turnstile_verify(?string $token): array
{
    $env    = strtolower(getenv('APP_ENV') ?: 'local');
    $secret = getenv('TURNSTILE_SECRET_KEY') ?: '';

    $isLive = ($env === 'live' || $env === 'production');

    // 1) No token posted
    if ($token === null || $token === '') {
        if (!$isLive) {
            // Non-live: don't block login, just warn.
            error_log('[Turnstile] Bypassed in non-live env: missing token');
            return [true, 'bypassed in non-live env (missing token)'];
        }

        return [false, 'missing token (cf-turnstile-response not posted)'];
    }

    // 2) Secret not configured
    if ($secret === '') {
        if (!$isLive) {
            error_log('[Turnstile] Bypassed in non-live env: TURNSTILE_SECRET_KEY not set');
            return [true, 'bypassed in non-live env (no secret configured)'];
        }

        return [false, 'TURNSTILE_SECRET_KEY not configured in .env'];
    }

    // 3) Normal verification path
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

        if (!$isLive) {
            error_log('[Turnstile] Bypassed in non-live env: curl error: ' . $err);
            return [true, 'bypassed in non-live env (curl error)'];
        }

        return [false, 'curl error contacting Turnstile: ' . $err];
    }

    curl_close($ch);

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        if (!$isLive) {
            error_log('[Turnstile] Bypassed in non-live env: invalid JSON: ' . substr($raw, 0, 200));
            return [true, 'bypassed in non-live env (invalid JSON from Turnstile)'];
        }

        return [false, 'invalid JSON from Turnstile: ' . substr($raw, 0, 200)];
    }

    if (empty($decoded['success'])) {
        $codes    = $decoded['error-codes'] ?? [];
        $codeList = is_array($codes) ? implode(', ', $codes) : (string)$codes;

        if (!$isLive) {
            error_log('[Turnstile] Bypassed in non-live env: reported failure: ' . $codeList);
            return [true, 'bypassed in non-live env (Turnstile failure: ' . $codeList . ')'];
        }

        return [false, 'Turnstile reported failure: ' . $codeList];
    }

    return [true, 'ok'];
}
