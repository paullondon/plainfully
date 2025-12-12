<?php declare(strict_types=1);

require_once __DIR__ . '/magic_link.php';
require_once __DIR__ . '/../support/turnstile.php';

function handle_login_form(array $config): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        // Turnstile first
        $token = $_POST['cf-turnstile-response'] ?? null;
        [$ok, $msg] = pf_turnstile_verify($token);

        if (!$ok) {
            $_SESSION['magic_link_error'] = 'Turnstile failed: ' . $msg;
            header('Location: /login', true, 302);
            exit;
        }

        // Then the normal magic-link flow
        handle_magic_request($config);
        return;
    }

    // GET – render form
    $siteKey    = $config['security']['turnstile_site_key'] ?? '';
    $loginError = $_SESSION['magic_link_error'] ?? '';
    unset($_SESSION['magic_link_error']);

    $loginOk = $_SESSION['magic_link_ok'] ?? '';
    unset($_SESSION['magic_link_ok']);

    require __DIR__ . '/../views/auth_login.php';
}
