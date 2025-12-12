<?php declare(strict_types=1);

require_once __DIR__ . '/magic_link.php';

/**
 * Handle GET + POST for /login
 */
function handle_login_form(array $config): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        // --- Turnstile verification first ---
        $env   = strtolower(getenv('APP_ENV') ?: 'local');
        $token = $_POST['cf-turnstile-response'] ?? null;

        [$ok, $reason] = pf_turnstile_verify($token);

        if (!$ok) {
            // Always log the internal reason
            error_log('Turnstile failed on /login: ' . $reason);

            if ($env === 'live' || $env === 'production') {
                $_SESSION['magic_link_error'] = 'There was an issue with your request. Please try again.';
            } else {
                // In local/dev show the exact reason so you can fix config/CSP/etc.
                $_SESSION['magic_link_error'] = 'Turnstile failed: ' . $reason;
            }

            pf_redirect('/login');
            return;
        }

        // Turnstile passed → delegate to magic link handler
        handle_magic_request($config);
        return;
    }

    // GET -> render login view
    $siteKey    = $config['turnstile_site_key'] ?? '';
    $loginError = $_SESSION['magic_link_error'] ?? '';
    unset($_SESSION['magic_link_error']);

    $loginOk = $_SESSION['magic_link_ok'] ?? '';
    unset($_SESSION['magic_link_ok']);

    require __DIR__ . '/../views/auth_login.php';
}
