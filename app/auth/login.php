<?php declare(strict_types=1);

// app/auth/login.php

// Load the magic link handler ONCE
require_once __DIR__ . '/magic_link.php';

/**
 * Handle GET/POST for /login.
 */
function handle_login_form(array $config): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // POST → process magic-link request
    if ($method === 'POST') {
        handle_magic_request($config);
        return;
    }

    // GET → show login page
    $siteKey    = $config['turnstile.site_key'] ?? '';
    $loginError = $_SESSION['magic_link_error'] ?? '';
    unset($_SESSION['magic_link_error']);

    $loginOk = $_SESSION['magic_link_ok'] ?? '';
    unset($_SESSION['magic_link_ok']);

    require __DIR__ . '/../views/auth_login.php';
}
