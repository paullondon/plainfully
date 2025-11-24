<?php declare(strict_types=1);

function handle_logout(): void
{
    // CSRF protection for POST /logout
    pf_verify_csrf_or_abort();

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

    pf_redirect('/login');
}
