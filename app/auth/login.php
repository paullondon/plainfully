<?php declare(strict_types=1);

function handle_login_form(array $config): void
{
    $siteKey = $config['security']['turnstile_site_key'] ?? '';

    $loginError = $_SESSION['magic_link_error']  ?? '';
    $loginOk    = $_SESSION['magic_link_ok']     ?? '';

    unset($_SESSION['magic_link_error'], $_SESSION['magic_link_ok']);

    // Make variables available to the view
    ob_start();
    $viewSiteKey   = $siteKey;      // optional alias if you prefer
    $viewLoginOk   = $loginOk;
    $viewLoginError= $loginError;

    // keep names matching the view
    $siteKey    = $siteKey;
    $loginOk    = $loginOk;
    $loginError = $loginError;

    require __DIR__ . '/../views/auth_login.php';
    $inner = ob_get_clean();

    pf_render_shell('Login | Plainfully', $inner);
}
