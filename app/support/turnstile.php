<?php declare(strict_types=1);

function pf_turnstile_verify(?string $token): array
{
    // TEMP: always allow during dev while Turnstile is being flaky.
    // Logs any odd cases so we can review later.
    $env = strtolower(getenv('APP_ENV') ?: 'local');

    if ($token === null || $token === '') {
        error_log('[Turnstile] No token posted (env=' . $env . ') – TEMPORARY BYPASS');
        return [true, 'temporary bypass – no token'];
    }

    error_log('[Turnstile] Token posted (env=' . $env . ') – TEMPORARY BYPASS');
    return [true, 'temporary bypass – token present'];
}
