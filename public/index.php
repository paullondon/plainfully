<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Public Front Door
 * ============================================================
 * Purpose:
 *   - Boot app
 *   - Normalize path
 *   - Dispatch to routes
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/routes/web.php';

pf_send_security_headers();

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Normalize:
// - treat /index.php as /
// - trim trailing slashes (so /health/ works)
// - never allow empty path
$path = ($rawPath === '' || $rawPath === '/index.php') ? '/' : $rawPath;
if ($path !== '/') {
    $path = rtrim($path, '/');
    if ($path === '') { $path = '/'; }
}

try {
    pf_route_dispatch($path, $method);
} catch (Throwable $e) {
    pf_log('error', 'Unhandled exception', ['err' => $e->getMessage()]);
    pf_http_error(500, 'Server Error');
}