<?php declare(strict_types=1);

/**
 * Main controller for Plainfully
 *
 * Handles:
 *  - Home page (logged-in welcome)
 *  - Logout
 */

function handle_welcome(): void
{
    $userId = $_SESSION['user_id'] ?? null;

    // If not logged in, this should never be called because require_login() blocks it.
    if (!$userId) {
        pf_redirect('/login');
    }

    // make user available to the view
    $userId = (int)$userId;

    ob_start();
    require __DIR__ . '/../views/home.php';
    $inner = ob_get_clean();

    pf_render_shell('Plainfully', $inner);
}

function handle_logout(): void
{
    pf_verify_csrf_or_abort();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pf_redirect('/');
    }

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
