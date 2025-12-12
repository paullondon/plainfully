<?php declare(strict_types=1);

/*
 * TEMPORARY Turnstile bypass while debugging.
 */
function pf_turnstile_verify(?string $token): array
{
    $env = strtolower(getenv('APP_ENV') ?: 'local');

    if ($token === null || $token === '') {
        error_log('[Turnstile] No token posted (env=' . $env . ') – TEMPORARY BYPASS');
        return [true, 'temporary bypass – no token'];
    }

    error_log('[Turnstile] Token posted (env=' . $env . ') – TEMPORARY BYPASS');
    return [true, 'temporary bypass – token present'];
}
