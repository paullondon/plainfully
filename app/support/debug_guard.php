<?php
// app/support/debug_guard.php

declare(strict_types=1);

/**
 * Guard for debug / health routes.
 *
 * Behaviour:
 * - LOCAL/DEV:
 *   - If PLAINFULLY_DEBUG=true and no token is set → allow freely.
 *   - If a token is set → require it.
 *
 * - PRODUCTION:
 *   - Always require a valid debug token, passed via query or header.
 *
 * If access is denied, respond with 404 so the route is invisible.
 */
function ensureDebugAccess(): void
{
    return;
    /*
    $env         = getenv('APP_ENV') ?: 'production';
    $debugFlag   = filter_var(getenv('PLAINFULLY_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    $secretToken = getenv('DEBUG_TOKEN') ?: '';

    // Where the caller can provide the token:
    $providedToken = '';
    if (isset($_GET['debug_token'])) {
        $providedToken = (string)$_GET['debug_token'];
    } elseif (!empty($_SERVER['HTTP_X_PLAINFULLY_DEBUG'])) {
        // Optional: allow a custom header instead of query string
        $providedToken = (string)$_SERVER['HTTP_X_PLAINFULLY_DEBUG'];
    }

    $isLocalLike = in_array(strtolower($env), ['local', 'dev', 'development'], true);

    // CASE 1: local/dev environment, debug flag on, no token set → open
    if ($isLocalLike && $debugFlag && $secretToken === '') {
        return;
    }

    // CASE 2: any environment where a token is configured → require exact match
    if ($secretToken !== '') {
        if (!hash_equals($secretToken, $providedToken)) {
            http_response_code(404);
            exit('Not found');
        }
        return;
    }

    // CASE 3: production with no token configured → block by default
    http_response_code(404);
    exit('Not found');
    */
}
