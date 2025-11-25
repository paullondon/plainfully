<?php declare(strict_types=1);

/**
 * Authentication Middleware for Plainfully
 *
 * Provides:
 *  - require_login(): blocks guests
 *  - require_guest(): blocks logged-in users from guest pages
 */

function pf_is_logged_in(): bool
{
    return isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']);
}

/**
 * Require the user to be logged in.
 * If not logged in, redirect to /login.
 */
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        // TEMP DEBUG
        header('Content-Type: text/plain; charset=utf-8');
        echo "DEBUG: require_login() thinks you are NOT logged in.\n\n";
        echo "SESSION DUMP:\n";
        var_export($_SESSION);
        exit;
    }
}

/**
 * Require the user to be a guest.
 * If logged in already, redirect to the home page (/).
 */
function require_guest(): void
{
    if (pf_is_logged_in()) {
        header('Location: /', true, 302);
        exit;
    }
}
