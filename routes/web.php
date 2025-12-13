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
    // Clarifications – new
    // -------------------------------------------------

    case $path === '/clarifications/new' && $method === 'GET':
        clarifications_new_controller();
        break;

    case $path === '/clarifications/new' && $method === 'POST':
        clarifications_new_controller();
        break;

    // -------------------------------------------------
    // Clarifications – list + view
    // -------------------------------------------------

    case $path === '/clarifications' && $method === 'GET':
        clarifications_index_controller();
        break;

    case $path === '/clarifications/view' && $method === 'GET':
        clarifications_view_controller();
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
    // Debug – env sanity
    // -------------------------------------------------
    case $path === '/debug/env-check' && $method === 'GET':
        ensureDebugAccess();

        header('Content-Type: text/plain; charset=utf-8');
        echo "web.php reached\n";
        echo "APP_ENV=" . (getenv('APP_ENV') ?: 'null') . "\n";
        echo "PLAINFULLY_DEBUG_TOKEN=" . (getenv('PLAINFULLY_DEBUG_TOKEN') ?: 'null') . "\n";
        break;

    // -------------------------------------------------
    // Debug – checks
    // -------------------------------------------------
    case $path === '/debug/checks' && $method === 'GET':
        ensureDebugAccess();
        debug_list_checks();
        break;

    case $path === '/debug/checks/view' && $method === 'GET':
        ensureDebugAccess();
        debug_view_check();
        break;

    // -------------------------------------------------
    // Debug – consultations
    // -------------------------------------------------
    case $path === '/debug/consultations' && $method === 'GET':
        ensureDebugAccess();
        debug_list_consultations();
        break;

    case $path === '/debug/consultations/view' && $method === 'GET':
        ensureDebugAccess();
        debug_view_consultation();
        break;

    case $path === '/hooks/email/inbound-dev' && $method === 'POST':
        email_inbound_dev_controller();
        return;

    // -------------------------------------------------
    // 404 fallback – nicer page
    // -------------------------------------------------
    default:
    http_response_code(404);

    ob_start();
    require dirname(__DIR__) . '/app/views/errors/404.php';
    $inner = ob_get_clean();

    pf_render_shell('Not found', $inner);
    return;
}
