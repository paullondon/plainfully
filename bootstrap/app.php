<?php declare(strict_types=1);

/**
 * Plainfully Bootstrapper
 * 
 * Loads environment, config, handlers, middleware,
 * support libraries and all required controllers.
 */

// ---------------------------------------------------------
// 0. Simple .env loader (root-level .env)
// ---------------------------------------------------------
$envPath = dirname(__DIR__) . '/.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        putenv("$k=$v");
    }
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

    http_response_code(500);
    $env   = getenv('APP_ENV') ?: 'local';
    $uri   = $_SERVER['REQUEST_URI'] ?? '';
    $isCli = (PHP_SAPI === 'cli');
    $isHook = is_string($uri) && str_starts_with($uri, '/hooks/');

    // CLI and /hooks/* → JSON, no pf_render_shell usage
    if ($isCli || $isHook) {
        header('Content-Type: application/json; charset=utf-8');

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
            header('Content-Type: text/plain; charset=utf-8');
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
            header('Content-Type: text/plain; charset=utf-8');
            echo "Something went wrong.\n";
        }
    }

    exit;
});



// ---------------------------------------------------------
// 2. Security headers
// ---------------------------------------------------------
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

// ---------------------------------------------------------
// 3. Sessions – secure cookie flags
// ---------------------------------------------------------
$cookieParams   = session_get_cookie_params();
$sessionDays   = (int)(getenv('SESSION_LIFETIME_DAYS') ?: 7);
$sessionHours  = (int)(getenv('SESSION_IDLE_HOURS') ?: 12);

$cookieLifetime = 60 * 60 * 24 * $sessionDays;

session_set_cookie_params([
    'lifetime' => $cookieLifetime,
    'path'     => $cookieParams['path'],
    'domain'   => $cookieParams['domain'],
    'secure'   => true,      // HTTPS only
    'httponly' => true,      // not accessible to JS
    'samesite' => 'Lax',     // good default for auth
]);

// ---------------------------------------------------------
// 4. Load config + helpers + modules
// ---------------------------------------------------------
$config = require dirname(__DIR__) . '/config/app.php';

session_start();
// auth
require dirname(__DIR__) . '/app/auth/login.php';

// views
require dirname(__DIR__) . '/app/views/render.php';

// support
require dirname(__DIR__) . '/app/support/helpers.php';
require dirname(__DIR__) . '/app/support/db.php';
require dirname(__DIR__) . '/app/support/mailer.php';
require dirname(__DIR__) . '/app/support/rate_limiter.php';
require dirname(__DIR__) . '/app/support/session_hardening.php';
pf_enforce_idle_timeout();
require dirname(__DIR__) . '/app/support/auth_middleware.php';
require dirname(__DIR__) . '/app/support/request.php';
require dirname(__DIR__) . '/app/support/csrf.php';
require dirname(__DIR__) . '/app/support/auth_log.php';
require dirname(__DIR__) . '/app/support/debug_guard.php';
require dirname(__DIR__) . '/app/support/debug_consultations.php';
require dirname(__DIR__) . '/app/support/debug_shell.php';
//require dirname(__DIR__) . '/app/support/turnstile.php';

// controllers
require dirname(__DIR__) . '/app/controllers/welcome_controller.php';
require dirname(__DIR__) . '/app/controllers/health_controller.php';
require dirname(__DIR__) . '/app/controllers/logout_controller.php';
require dirname(__DIR__) . '/app/controllers/clarifications_controller.php';
require dirname(__DIR__) . '/app/controllers/dashboard.php';
require dirname(__DIR__) . '/app/controllers/email_hooks_controller.php';
require dirname(__DIR__) . '/app/controllers/checks_debug_controller.php';

// router
if (!defined('PLAINFULLY_SKIP_ROUTER')) {
    require dirname(__DIR__) . '/routes/web.php';
}
