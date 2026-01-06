<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully Bootstrapper
 * ============================================================
 * File: bootstrap/app.php
 * Purpose:
 *   Single entrypoint bootstrap for web + cron + hooks.
 *
 * Loads (in order):
 *   1) Env loader (pf_load_env_file) + loads httpdocs/.env
 *   2) Error handling
 *   3) Central includes list (helpers/support/controllers)
 *   4) Security headers (web-only)
 *   5) Session settings (web-only) + start session + idle timeout
 *   6) Router (unless PLAINFULLY_SKIP_ROUTER)
 * ============================================================
 */

// ---------------------------------------------------------
// 0. Env loader + load .env ONCE (root-level httpdocs/.env)
// ---------------------------------------------------------
require_once __DIR__ . '/../app/controllers/file_loader_controller.php';
pf_load_env_file(__DIR__ . '/../.env');

// Marker for debugging: can verify bootstrap was loaded
if (!defined('PF_BOOTSTRAPPED')) {
    define('PF_BOOTSTRAPPED', true);
}

// ---------------------------------------------------------
// 1. Error reporting based on environment
// ---------------------------------------------------------
$appEnv = getenv('APP_ENV') ?: 'local';

if (strtolower($appEnv) === 'live' || strtolower($appEnv) === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

set_exception_handler(function (Throwable $e): void {
    // Always log
    error_log('[Plainfully] Uncaught exception: ' . $e->getMessage() . ' in ' .
        $e->getFile() . ':' . $e->getLine());

    $env    = getenv('APP_ENV') ?: 'local';
    $uri    = $_SERVER['REQUEST_URI'] ?? '';
    $isCli  = (PHP_SAPI === 'cli');
    $isHook = is_string($uri) && str_starts_with($uri, '/hooks/');

    // CLI and /hooks/* → JSON, no pf_render_shell usage
    if ($isCli || $isHook) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        if (strtolower($env) !== 'live' && strtolower($env) !== 'production') {
            echo json_encode([
                'ok'    => false,
                'error' => 'Internal error',
                'debug' => (string)$e,
            ]);
        } else {
            echo json_encode([
                'ok'    => false,
                'error' => 'Internal server error',
            ]);
        }
        exit;
    }

    // Normal web requests
    http_response_code(500);

    if (strtolower($env) !== 'live' && strtolower($env) !== 'production') {
        // Debug page
        if (function_exists('pf_render_shell')) {
            pf_render_shell(
                'Error',
                '<pre style="white-space:pre-wrap;">' .
                    htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') .
                '</pre>'
            );
        } else {
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo (string)$e;
        }
    } else {
        // Friendly 500 page
        if (function_exists('pf_render_shell')) {
            ob_start();
            require dirname(__DIR__) . '/app/views/errors/500.php';
            $inner = ob_get_clean();
            pf_render_shell('Error', $inner);
        } else {
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo "Something went wrong.
";
        }
    }

    exit;
});

// ---------------------------------------------------------
// 2. Central includes list (config + helpers + modules)
// ---------------------------------------------------------
require_once __DIR__ . '/includes.php';

// ---------------------------------------------------------
// 3. Security headers (web-only)
// ---------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "style-src 'self' 'unsafe-inline'; " .
        "script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com; " .
        "frame-src https://challenges.cloudflare.com;"
    );
}

// ---------------------------------------------------------
// 4. Sessions – secure cookie flags (web-only)
// ---------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    $cookieParams = session_get_cookie_params();

    // Use standardised env helpers if present, else fallback
    $sessionDays  = function_exists('pf_env_int') ? pf_env_int('SESSION_LIFETIME_DAYS', 7, 1, 365) : (int)(getenv('SESSION_LIFETIME_DAYS') ?: 7);
    $sessionHours = function_exists('pf_env_int') ? pf_env_int('SESSION_IDLE_HOURS', 12, 1, 168) : (int)(getenv('SESSION_IDLE_HOURS') ?: 12);

    $cookieLifetime = 60 * 60 * 24 * $sessionDays;

    session_set_cookie_params([
        'lifetime' => $cookieLifetime,
        'path'     => $cookieParams['path'],
        'domain'   => $cookieParams['domain'],
        'secure'   => true,      // HTTPS only
        'httponly' => true,      // not accessible to JS
        'samesite' => 'Lax',     // good default for auth
    ]);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (function_exists('pf_enforce_idle_timeout')) {
        pf_enforce_idle_timeout();
    }
}

// ---------------------------------------------------------
// 5. Router (web-only) – unless explicitly skipped
// ---------------------------------------------------------
if (!defined('PLAINFULLY_SKIP_ROUTER')) {
    require dirname(__DIR__) . '/routes/web.php';
}
