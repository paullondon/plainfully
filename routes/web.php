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

    // -------------------------------------------------
    // Dev email inbound hook (no auth – token-based)
    // -------------------------------------------------
    case $path === '/hooks/email/inbound-dev' && $method === 'POST':
        email_inbound_dev_controller();
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

    case $path === '/debug/env-check' && $method === 'GET':
            // Debug env + sanity check
            ensureDebugAccess();

            header('Content-Type: text/plain; charset=utf-8');
            echo "web.php reached\n";
            echo "APP_ENV=" . (getenv('APP_ENV') ?: 'null') . "\n";
            echo "PLAINFULLY_DEBUG=" . (getenv('PLAINFULLY_DEBUG') ?: 'null') . "\n";
            echo "PLAINFULLY_DEBUG_TOKEN=" . (getenv('PLAINFULLY_DEBUG_TOKEN') ?: 'null') . "\n";
            break;

    case $path === '/debug/consultations' && $method === 'GET':
            // List recent consultations (debug only)
            ensureDebugAccess();
            debug_list_consultations();
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
    };