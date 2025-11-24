<?php declare(strict_types=1);

/**
 * Handle GET + POST for /login
 */
function handle_login_form(array $config): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        // POST -> delegate to magic link handler
        handle_magic_request($config);
        return;
    }

    // GET -> render login view
    $siteKey    = $config['turnstile.site_key'] ?? '';
    $loginError = $_SESSION['magic_link_error'] ?? '';
    unset($_SESSION['magic_link_error']);

    $loginOk = $_SESSION['magic_link_ok'] ?? '';
    unset($_SESSION['magic_link_ok']);

    require __DIR__ . '/../views/auth_login.php';
}
