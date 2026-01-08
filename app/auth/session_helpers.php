<?php declare(strict_types=1);

/**
 * session_helpers.php
 *
 * Purpose:
 * - Safe session start helper
 * - Consistent login session hydration
 *
 * IMPORTANT:
 * - Do NOT call session_set_cookie_params() here.
 *   That happens in app/core/bootstrap.php before session_start().
 */

if (!function_exists('pf_session_start')) {
    function pf_session_start(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        }
    }
}

if (!function_exists('pf_session_login')) {
    function pf_session_login(int $userId, string $email): void
    {
        pf_session_start();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            error_log('pf_session_login: session not active');
            return;
        }

        $email = strtolower(trim($email));

        // Fail-closed: don’t create partial sessions
        if ($userId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log('pf_session_login: invalid userId/email');
            return;
        }

        // Reduce session fixation risk
        session_regenerate_id(true);

        $_SESSION['user_id']      = $userId;
        $_SESSION['user_email']   = $email;
        $_SESSION['logged_in_at'] = time();
    }
}
