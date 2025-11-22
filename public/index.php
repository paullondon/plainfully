<?php declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

// 7. Very small router
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 🔐 GLOBAL SESSION SECURITY
pf_verify_session_security();

// Normalise trailing slash
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

// Run router
$routes = pf_register_routes();
$matched = false;

foreach ($routes as $route) {
    if ($route['method'] === $method && $route['path'] === $path) {
        $matched = true;
        ($route['action'])();
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
