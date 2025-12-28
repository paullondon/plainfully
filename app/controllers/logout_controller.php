<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/controllers/logout_controller.php
 * Purpose:
 *   Logs the user out (POST /logout) with CSRF protection.
 *
 * What this version adds:
 *   - Optional return redirect support:
 *       POST /logout with return=/r/{token}
 *     redirects back to that path after logout (safe allow-list).
 *
 * Change history:
 *   - 2025-12-28  Add safe 'return' support for Flow B
 * ============================================================
 */

function handle_logout(): void
{
    // CSRF protection for POST /logout
    pf_verify_csrf_or_abort();

    $return = isset($_POST['return']) ? (string)$_POST['return'] : '';
    $return = trim($return);7

    // Only allow returning to /r/{token} (Flow B) to avoid open redirects.
    $safeReturn = '/login';
    if ($return !== '' && str_starts_with($return, '/r/')) {
        // Basic token sanity (min length, URL-safe chars)
        $token = substr($return, 3); // after "/r/"
        if ($token !== '' && strlen($token) >= 16 && preg_match('/^[A-Za-z0-9\-_]+$/', $token) === 1) {
            $safeReturn = '/r/' . $token;
        }
    }

    $userId = $_SESSION['user_id'] ?? null;
    if ($userId !== null) {
        pf_log_auth_event('logout', (int)$userId, null, 'User logged out');
    }

    // Clear session data
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    pf_redirect($safeReturn);
}
