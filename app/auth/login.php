<?php declare(strict_types=1);

require_once dirname(__DIR__) . '/core/db.php';
require_once __DIR__ . '/magic_link.php';
require_once __DIR__ . '/session_helpers.php';

/**
 * ============================================================
 * Plainfully Auth - Login Form Handler
 * ============================================================
 */
function handle_login_form(array $config): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        // TEMP: no Turnstile on POST – rely on Cloudflare + magic link.
        handle_magic_request($config);
        return;
    }

    // GET → render login view
    $siteKey    = $config['security']['turnstile_site_key'] ?? '';
    $loginError = (string)($_SESSION['magic_link_error'] ?? '');
    unset($_SESSION['magic_link_error']);

    $loginOk = (string)($_SESSION['magic_link_ok'] ?? '');
    unset($_SESSION['magic_link_ok']);

    require __DIR__ . '/../views/auth_login.php';
}

/**
 * ============================================================
 * Admin
 * ============================================================
 * pf_is_admin():
 * - Primary: users.is_admin flag
 * - Optional: ADMIN_EMAILS allowlist (env)
 */
if (!function_exists('pf_is_admin')) {
    function pf_is_admin(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

        $email = strtolower(trim((string)($_SESSION['user_email'] ?? '')));
        if ($email === '') { return false; }

        // Optional allowlist (env)
        $allow = strtolower(trim((string)(getenv('ADMIN_EMAILS') ?: '')));
        if ($allow !== '') {
            $list = array_filter(array_map('trim', explode(',', $allow)));
            if (in_array($email, $list, true)) { return true; }
        }

        // Primary: DB flag
        if (!function_exists('pf_db')) { return false; }

        try {
            $pdo = pf_db();

            $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE email = :e LIMIT 1');
            $stmt->execute([':e' => $email]);
            $v = $stmt->fetchColumn();

            return ((int)($v ?? 0)) === 1;
        } catch (\Throwable $e) {
            error_log('pf_is_admin failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('pf_require_admin')) {
    function pf_require_admin(): void
    {
        if (pf_is_admin()) { return; }

        http_response_code(403);

        // View model for a generic error panel (used by views/errors/403.php if present)
        $vm = [
            'emoji'    => '⛔',
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
            $errorView = dirname(__DIR__) . '/views/errors/403.php';
            if (!is_file($errorView)) {
                // Fallback to 404 view only if 403 doesn't exist yet (temporary during tidy)
                $errorView = dirname(__DIR__) . '/views/errors/404.php';
            }

            ob_start();
            require $errorView;
            $inner = (string)ob_get_clean();

            if (function_exists('pf_render_shell')) {
                pf_render_shell('Not authorised', $inner);
            } else {
                echo $inner;
            }
        } catch (\Throwable $e) {
            error_log('pf_require_admin render failed: ' . $e->getMessage());
            echo 'You are not authorised to view this page.';
        }

        exit;
    }
}

if (!function_exists('pf_user_email')) {
    fu
