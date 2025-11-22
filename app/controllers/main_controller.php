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

    if ($userId) {
        $inner = '
            <h1 class="pf-auth-title">Plainfully</h1>
            <p class="pf-auth-subtitle">
                You’re signed in. (User ID: ' . (int)$userId . ')
            </p>
            <form method="post" action="/logout">
                <button type="submit" class="pf-button">Sign out</button>
            </form>
        ';
    } else {
        $inner = '
            <h1 class="pf-auth-title">Welcome to Plainfully</h1>
            <p class="pf-auth-subtitle">
                You’re not logged in yet. Use a magic link to sign in.
            </p>
            <a class="pf-button" href="/login">Go to login</a>
        ';
    }

    pf_render_shell('Plainfully', $inner);
}

function handle_logout(): void
{
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
