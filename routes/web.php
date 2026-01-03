<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: routes/web.php
 * Purpose:
 *   Main router for Plainfully web app.
 *
 * Change history:
 *   - 2025-12-28 16:44:40Z  Add guest route GET/POST /r/{token} (result link confirmation)
 *   - 2025-12-29 10:11:00Z  Removed require_guest() to /r/{token} route (temporarily disabled)
 * ============================================================
 *
 * Notes:
 *   - /r/{token} is a guest route:
 *       - asks the user to confirm the email address the result was sent to
 *       - then logs them in and redirects to /clarifications/view?id=...
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
    case $path === '/clarifications/new' && ($method === 'GET' || $method === 'POST'):
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
    // Result link confirmation (guest)
    //   GET  /r/{token}
    //   POST /r/{token}
    // -------------------------------------------------
    case str_starts_with($path, '/r/') && ($method === 'GET' || $method === 'POST'):
        //require_guest(); // Removed temporarily to allow access to consultation results while logged in.

        $token = substr($path, 3); // everything after "/r/"
        $token = trim($token);

        require_once __DIR__ . '/../app/controllers/result_access_controller.php';

        result_access_controller($token);
        break;
    
    case ($path === '/trace') && ($method === 'GET'):
        require_once __DIR__ . '/../app/controllers/trace_controller.php';
        trace_controller();
        break;

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

    case $path === '/debug/email-bridge' && $method === 'GET':
        ensureDebugAccess();
        admin_debug_email_bridge();
        break;

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
