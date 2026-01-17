<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Front Controller (public/index.php)
 * ============================================================
 * Why:
 *  - .htaccess routes all non-file requests here.
 *  - This file MUST dispatch based on the URL path, not always show home.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once PF_ROOT . '/app/routes/web.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/**
 * Path detection:
 * - REQUEST_URI includes the path + query string, e.g. "/debug/snapshot?t=..."
 * - We must strip query string and decode safely.
 */
$uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

// Normalise
$path = rawurldecode($path);
$path = '/' . ltrim($path, '/');          // ensure leading slash
$path = rtrim($path, '/');               // remove trailing slash
if ($path === '') $path = '/';

// Dispatch
pf_route_dispatch($path, $method);
