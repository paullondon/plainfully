<?php declare(strict_types=1);

/**
 * Plainfully – main router
 *
 * Handles:
 *  - GET  /            → redirect to /dashboard (if logged in)
 *  - GET  /login       → magic-link login form
 *  - POST /login       → request magic link
 *  - GET  /magic/verify→ verify magic link
 *  - POST /logout      → logout
 *  - GET  /dashboard   → main app dashboard
 *  - GET  /health      → health check
 */

// HTTP method + path
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 🔐 Global session security (only affects logged-in users)
pf_verify_session_security();

// Normalise trailing slash (except root)
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

switch (true) {
// ======================
// !! LOGGED IN ROUTES !!
// ======================

    // -------------------------------------------------
    // Home → redirect to dashboard (must be logged in)
    // -------------------------------------------------
    case $path === '/' && $method === 'GET':
        require_login();
        pf_redirect('/dashboard');
        break;


    // -------------------------------------------------
    // Clarifications – list (logged-in only)
    // -------------------------------------------------

    case $path === '/clarifications/new' && $method === 'GET':
        require_login();
        require dirname(__DIR__) . '/app/views/clarifications/new.php';
        break;

    case $path === '/clarifications/new' && $method === 'POST':
        require_login();
        require APP_ROOT . '/app/support/clarifications.php';
        plainfully_handle_clarification_new_post_v2();
        break;

    case $path === '/clarifications/view' && $method === 'GET':
        require_login();
        require dirname(__DIR__) . '/app/views/clarifications/view.php';
        plainfully_handle_clarification_view();
        break;
    
    // Cancel (abandon) a very recent clarification
    case $path === '/clarifications/cancel' && $method === 'POST':
        require_login();
        require dirname(__DIR__) . '/app/views/clarifications/cancel.php';
        plainfully_handle_clarification_cancel();
        break;


    // -------------------------------------------------
    // Dashboard (logged-in only)
    // -------------------------------------------------
    case $path === '/dashboard' && $method === 'GET':
        require_login();
        handle_dashboard();
        break;
    
    // -------------------------------------------------
    // Logout
    // -------------------------------------------------
    case $path === '/logout' && $method === 'POST':
        require_login();
        handle_logout();
        break;


// ======================
// !! GUEST ROUTES     !!
// ======================

    // -------------------------------------------------
    // Login (GET + POST) – unified handler
    // -------------------------------------------------
    case $path === '/login':
        require_guest();
        handle_login_form($config);
        break;

    // -------------------------------------------------
    // Magic link verification
    // -------------------------------------------------
    case $path === '/magic/verify' && $method === 'GET':
        require_guest();
        handle_magic_verify();
        break;

    // -------------------------------------------------
    // Health check
    // -------------------------------------------------
    case $path === '/health' && $method === 'GET':
        require_guest();
        handle_health($config);
        break;

    // -------------------------------------------------
    // 404 fallback
    // -------------------------------------------------
    default:
        http_response_code(404);
        pf_render_shell(
            'Not found',
            '<h1 class="pf-auth-title">404</h1><p class="pf-auth-subtitle">Page not found.</p>'
        );
        break;
}
