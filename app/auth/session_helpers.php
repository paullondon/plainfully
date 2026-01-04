<?php declare(strict_types=1);

/**
 * session_helpers.php
 *
 * Purpose:
 * - One single place to start session safely
 * - One single place to set login session fields consistently
 *
 * IMPORTANT:
 * - Do NOT call session_set_cookie_params() here.
 *   That must happen BEFORE any session_start(), ideally in bootstrap/app.php once.
 */

if (!function_exists('pf_session_start')) {
    function pf_session_start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }
}

if (!function_exists('pf_session_login')) {
    function pf_session_login(int $userId, string $email): void
    {
        pf_session_start();

        $email = strtolower(trim($email));

        // Fail-closed: don’t create partial sessions
        if ($userId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log('pf_session_login: invalid userId/email');
            return;
        }

        // Regenerate to reduce session fixation risk
        @session_regenerate_id(true);

        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = $email;
        $_SESSION['logged_in_at'] = time();
    }
}
