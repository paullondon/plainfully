<?php declare(strict_types=1);

/**
 * Authentication middleware for Plainfully
 *
 * Provides:
 *  - pf_is_logged_in()
 *  - require_login()
 *  - require_guest()
 */

if (!function_exists('pf_is_logged_in')) {
    function pf_is_logged_in(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            pf_session_start();
        }

        return isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']);
    }
}

if (!function_exists('require_login')) {
    /**
     * Require the user to be logged in.
     * Guests are redirected to /login.
     */
    function require_login(): void
    {
        if (!pf_is_logged_in()) {
            pf_redirect('/login');
        }
    }
}

if (!function_exists('require_guest')) {
    /**
     * Require the user to be a guest.
     * Logged-in users are redirected to the dashboard.
     */
    function require_guest(): void
    {
        if (pf_is_logged_in()) {
            pf_redirect('/dashboard');
        }
    }
}
