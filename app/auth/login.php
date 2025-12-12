<?php declare(strict_types=1);

require_once __DIR__ . '/magic_link.php';

function handle_login_form(array $config): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        // TEMP: no Turnstile on POST – rely on Cloudflare + magic link.
        handle_magic_request($config);
        return;
    }

    // GET → render login view
    $siteKey    = $config['security']['turnstile_site_key'] ?? '';
    $loginError = $_SESSION['magic_link_error'] ?? '';
    unset($_SESSION['magic_link_error']);

    $loginOk = $_SESSION['magic_link_ok'] ?? '';
    unset($_SESSION['magic_link_ok']);

    require __DIR__ . '/../views/auth_login.php';
}
