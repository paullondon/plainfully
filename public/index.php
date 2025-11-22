<?php declare(strict_types=1);

/**
 * Plainfully – front controller
 *
 * Single entry point for all web requests:
 *  - /              → welcome
 *  - /login         → magic-link login form
 *  - /magic/request → POST, send magic link
 *  - /magic/verify  → GET, verify magic link
 *  - /logout        → POST, logout
 *  - /health        → health check (requires DEBUG_TOKEN)
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

// Basic error / exception handlers – in live, log only
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e): void {
    // TODO: log to file / monitoring here
    http_response_code(500);
    ob_start();
    require dirname(__DIR__) . '/app/views/errors/500.php';
    $inner = ob_get_clean();
    pf_render_shell('Error', $inner);

    // In non-live envs, show message for debugging
    $env = getenv('APP_ENV') ?: 'local';
    if (strtolower($env) !== 'live' && strtolower($env) !== 'production') {
        echo '<pre style="white-space:pre-wrap;">' . htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    echo '</body></html>';
});

// ---------------------------------------------------------
// 2. Security headers
// ---------------------------------------------------------
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');

// Content-Security-Policy kept simple for now; expand later
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com;");

// ---------------------------------------------------------
// 3. Sessions – secure cookie flags
// ---------------------------------------------------------
$cookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $cookieParams['path'],
    'domain'   => $cookieParams['domain'],
    'secure'   => true,      // HTTPS only
    'httponly' => true,      // not accessible to JS
    'samesite' => 'Lax',     // good default for auth
]);
session_start();

// ---------------------------------------------------------
// 4. Load config + helpers
// ---------------------------------------------------------
$config = require dirname(__DIR__) . '/config/app.php';

require dirname(__DIR__) . '/app/support/db.php';
require dirname(__DIR__) . '/app/support/mailer.php';
require dirname(__DIR__) . '/app/support/rate_limiter.php';
require dirname(__DIR__) . '/app/support/session_hardening.php';
require dirname(__DIR__) . '/app/support/auth_middleware.php';
require dirname(__DIR__) . '/app/auth/login.php';
require dirname(__DIR__) . '/app/auth/magic_link.php';
require dirname(__DIR__) . '/app/controllers/main_controller.php';
require dirname(__DIR__) . '/app/views/render.php';
require dirname(__DIR__) . '/routes/web.php';
require dirname(__DIR__) . '/app/controllers/health_controller.php';
require dirname(__DIR__) . '/app/support/helpers.php';

// ---------------------------------------------------------
// 7. Very small router
// ---------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 🔐 GLOBAL SESSION SECURITY (only affects logged-in users)
pf_verify_session_security();

// Normalise trailing slash (except root)
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

$routes = pf_register_routes();

$matched = false;

foreach ($routes as $route) {
    if ($route['method'] === $method && $route['path'] === $path) {
        $matched = true;
        ($route['action'])();     // call the closure
        exit;
    }
}

if (!$matched) {
    http_response_code(404);
    ob_start();
    require dirname(__DIR__) . '/app/views/errors/404.php';
    $inner = ob_get_clean();

    pf_render_shell('Not Found', $inner);
}

