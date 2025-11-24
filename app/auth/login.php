<?php declare(strict_types=1);

// app/auth/login.php

// TODO: make sure this require points to the file
// where handle_magic_request(array $config): void is defined.
// If your file is named differently, just change the filename below.
require_once __DIR__ . '/magic_link.php'; // e.g. 'magic_request.php' or 'magic_link.php'

/**
 * Handle the /login route.
 *
 * - GET  => render login form
 * - POST => delegate to magic link handler
 */
function handle_login_form(array $config): void
{
    // If POST, hand off to the existing magic-link handler
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        handle_magic_request($config);
        return;
    }

    // Otherwise, we're on GET -> show the login page

    // Turnstile site key
    $siteKey = $config['turnstile.site_key'] ?? '';

    // Read and clear any flash messages
    $loginError = $_SESSION['magic_link_error'] ?? '';
    unset($_SESSION['magic_link_error']);

    $loginOk = $_SESSION['magic_link_ok'] ?? '';
    unset($_SESSION['magic_link_ok']);

    // Render the view
    require __DIR__ . '/../views/auth_login.php';
}
