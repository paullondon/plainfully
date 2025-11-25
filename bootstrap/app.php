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

    $env = getenv('APP_ENV') ?: 'local';

    if (strtolower($env) !== 'live' && strtolower($env) !== 'production') {
        // debug output
        pf_render_shell(
            'Error',
            '<pre style="white-space:pre-wrap;">' . htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') . '</pre>'
        );
    } else {
        // user friendly
        ob_start();
        require dirname(__DIR__) . '/app/views/errors/500.php';
        $inner = ob_get_clean();
        pf_render_shell('Error', $inner);
    }

    exit;
});

// ---------------------------------------------------------
// 2. Security headers
// ---------------------------------------------------------
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com;");

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

session_start();


// ---------------------------------------------------------
// 4. Load config + helpers + modules
// ---------------------------------------------------------
$config = require dirname(__DIR__) . '/config/app.php';

// Debug toggle from config
if (strtolower($env) !== 'live' {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

// views
require_once dirname(__DIR__) . '/app/views/render.php';
require_once dirname(__DIR__) . '/app/dashboard.php';

// support
require_once dirname(__DIR__) . '/app/support/helpers.php';
require_once dirname(__DIR__) . '/app/support/db.php';
require_once dirname(__DIR__) . '/app/support/mailer.php';
require_once dirname(__DIR__) . '/app/support/rate_limiter.php';
require_once dirname(__DIR__) . '/app/support/session_hardening.php';
require_once dirname(__DIR__) . '/app/support/auth_middleware.php';
require_once dirname(__DIR__) . '/app/support/request.php';
require_once dirname(__DIR__) . '/app/support/csrf.php';
require_once dirname(__DIR__) . '/app/support/auth_log.php';

// controllers
require_once dirname(__DIR__) . '/app/controllers/welcome_controller.php';
require_once dirname(__DIR__) . '/app/controllers/health_controller.php';
require_once dirname(__DIR__) . '/app/controllers/dashboard_controller.php';
require_once dirname(__DIR__) . '/app/controllers/logout_controller.php';
require_once dirname(__DIR__) . '/app/controllers/clarifications_controller.php';

// auth
require_once dirname(__DIR__) . '/app/auth/login.php';

// router
require dirname(__DIR__) . '/routes/web.php';