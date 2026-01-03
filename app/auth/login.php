<?php declare(strict_types=1);
use Throwable;

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


/**
 * ============================================================
 * Plainfully Auth Helper
 * ============================================================
 * Function: pf_require_admin()
 * Purpose:
 *   Enforce admin-only access and render the existing adaptive 404 view
 *   with a "not authorised" message (instead of echo/exit).
 * ============================================================
 */
if (!function_exists('pf_require_admin')) {
    function pf_require_admin(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
        if ($isAdmin) {
            return;
        }

        // ---- Render via your single adaptive error view ----
        http_response_code(403);

        $vm = [
            'emoji'    => '🔒',
            'title'    => 'You are not authorised to view this page',
            'subtitle' => 'This area is restricted.',
            'list'     => [
                'If you think this is a mistake, log in with the correct account.',
                'Or return to your dashboard.',
            ],
            'actions'  => [
                ['href' => '/dashboard', 'label' => 'Go to dashboard', 'class' => 'pf-btn pf-btn-primary'],
                ['href' => '/login',     'label' => 'Log in',          'class' => 'pf-btn pf-btn-secondary'],
            ],
        ];

        try {
            ob_start();
            require dirname(__DIR__) . '/views/errors/404.php';
            $inner = (string)ob_get_clean();

            if (function_exists('pf_render_shell')) {
                pf_render_shell('Not authorised', $inner);
            } else {
                echo $inner;
            }
        } catch (Throwable $e) {
            // Absolute fail-safe
            error_log('pf_require_admin render failed: ' . $e->getMessage());
            echo 'You are not authorised to view this page.';
        }

        exit;
    }
}

