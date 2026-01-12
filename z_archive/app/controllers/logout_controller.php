<?php declare(strict_types=1);
/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/controllers/logout_controller.php
 * Purpose:
 *   Logs a user out safely.
 *
 * Routes:
 *   POST /logout
 *
 * Behaviour:
 *   - CSRF protected
 *   - Clears session + cookie
 *   - Redirects to /login (or optional safe internal return)
 *
 * Change history:
 *   - 2025-12-28  Fix syntax + add safe internal return support
 * ============================================================
 */

function handle_logout(): void
{
    // CSRF protection for POST /logout
    pf_verify_csrf_or_abort();

    // Optional return path (POSTed) - must be a SAFE internal path.
    $safeReturn = '/login';
    $requested  = isset($_POST['return_to']) ? (string)$_POST['return_to'] : '';

    if ($requested !== '') {
        $requested = trim($requested);

        // Only allow internal relative paths like "/r/abc", "/dashboard", "/login"
        // Disallow protocol, "//", backslashes, and whitespace tricks.
        $looksSafe =
            str_starts_with($requested, '/') &&
            !str_starts_with($requested, '//') &&
            !str_contains($requested, '://') &&
            !str_contains($requested, '\\') &&
            !preg_match('/\s/', $requested);

        if ($looksSafe) {
            $safeReturn = $requested;
        }
    }

    // Log event (best-effort)
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId !== null && function_exists('pf_log_auth_event')) {
        pf_log_auth_event('logout', (int)$userId, null, 'User logged out');
    }

    // Clear session data
    $_SESSION = [];

    // Clear cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    // Destroy session
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    pf_redirect($safeReturn);
}