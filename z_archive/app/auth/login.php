<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully Auth - Login + Admin Helpers
 * ============================================================
 * File: app/auth/login.php
 * Purpose:
 *   - handle_login_form(): GET renders login page, POST requests magic link
 *   - pf_is_admin(): DB-backed admin check (fail-closed)
 *   - pf_require_admin(): safe 403 render (soft failure)
 *   - pf_user_email(): convenience accessor
 *   - pf_auth_hydrate_session_email(): fills missing session email from DB
 *
 * Notes:
 *   - Session cookie params must be set BEFORE session_start();
 *     that happens in app/core/bootstrap.php.
 * ============================================================
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/magic_link.php';
require_once __DIR__ . '/session_helpers.php';

/**
 * Render login view or handle magic-link requests.
 */
function handle_login_form(array $config): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        // Turnstile intentionally disabled for now – magic link + rate limiting + Cloudflare.
        handle_magic_request($config);
        return;
    }

    // GET → render login view
    $siteKey    = (string)($config['security']['turnstile_site_key'] ?? '');
    $loginError = (string)($_SESSION['magic_link_error'] ?? '');
    unset($_SESSION['magic_link_error']);

    $loginOk = (string)($_SESSION['magic_link_ok'] ?? '');
    unset($_SESSION['magic_link_ok']);

    $debugUrl = (string)($_SESSION['magic_link_debug_url'] ?? '');
    unset($_SESSION['magic_link_debug_url']);

    require __DIR__ . '/../views/auth_login.php';
}

/**
 * Admin check.
 * - Primary: users.is_admin flag
 * - Optional allowlist: ADMIN_EMAILS (comma-separated)
 */
if (!function_exists('pf_is_admin')) {
    function pf_is_admin(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $email = strtolower(trim((string)($_SESSION['user_email'] ?? '')));
        if ($email === '') {
            return false;
        }

        // Optional allowlist (useful for early bring-up)
        $allow = strtolower(trim((string)(getenv('ADMIN_EMAILS') ?: '')));
        if ($allow !== '') {
            $list = array_filter(array_map('trim', explode(',', $allow)));
            if (in_array($email, $list, true)) {
                return true;
            }
        }

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

/**
 * Require admin access (soft failure + friendly render).
 */
if (!function_exists('pf_require_admin')) {
    function pf_require_admin(): void
    {
        if (pf_is_admin()) {
            return;
        }

        http_response_code(403);

        // View model for the shared error view.
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
            ob_start();
            require dirname(__DIR__) . '/views/errors/403.php';
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
    function pf_user_email(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        return (string)($_SESSION['user_email'] ?? '');
    }
}

/**
 * If user_id exists but user_email is missing, hydrate it from DB.
 * Safe no-op if anything is missing.
 */
if (!function_exists('pf_auth_hydrate_session_email')) {
    function pf_auth_hydrate_session_email(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return;
        }

        $existing = strtolower(trim((string)($_SESSION['user_email'] ?? '')));
        if ($existing !== '') {
            return;
        }

        try {
            $pdo = pf_db();

            $stmt = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $uid]);
            $email = strtolower(trim((string)($stmt->fetchColumn() ?: '')));

            if ($email !== '') {
                $_SESSION['user_email'] = $email;
            }
        } catch (\Throwable $e) {
            error_log('pf_auth_hydrate_session_email failed: ' . $e->getMessage());
        }
    }
}
