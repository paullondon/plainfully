<?php declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

pf_send_security_headers();

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Normalize trailing slashes
$path = ($rawPath === '' || $rawPath === '/index.php') ? '/' : $rawPath;
if ($path !== '/') {
    $path = rtrim($path, '/');
    if ($path === '') { $path = '/'; }
}

/**
 * ============================================================
 * HARD HEALTH BYPASS
 * ============================================================
 * This avoids any router/include weirdness and guarantees an
 * always-on health endpoint for uptime checks.
 */
if ($path === '/health') {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo "OK\n";
    exit;
}

require_once PF_ROOT . '/app/routes/web.php';

try {
    pf_route_dispatch($path, $method);
} catch (Throwable $e) {
    // Log with a trace id so we can pinpoint failures without exposing internals.
    $trace = bin2hex(random_bytes(8));
    pf_log('error', 'Unhandled exception', [
        'trace' => $trace,
        'err'   => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);

    pf_http_error(500, 'Server Error (ref ' . $trace . ')');
}
