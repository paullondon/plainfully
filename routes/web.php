<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: routes/web.php
 * Purpose:
 *   Main router for Plainfully web app.
 *
 * Notes:
 *   - /r/{token} is a guest route for result-link confirmation.
 *   - /trace is ADMIN-ONLY (no token fallbacks).
 *   - Debug endpoints should be ADMIN-ONLY or env-token protected.
 * ============================================================
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

    // -------------------------------------------------
    // Result link confirmation (guest)
    //   GET  /r/{token}
    //   POST /r/{token}
    // -------------------------------------------------
    case str_starts_with($path, '/r/') && ($method === 'GET' || $method === 'POST'):
        $token = trim(substr($path, 3)); // everything after "/r/"
        require_once __DIR__ . '/../app/controllers/result_access_controller.php';
        result_access_controller($token);
        break;

    // -------------------------------------------------
    // Trace (admin only)
    // -------------------------------------------------
    case ($path === '/trace') && ($method === 'GET'):
        pf_require_admin();
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
    // DEV hooks (token protected)
    // -------------------------------------------------
    case $path === '/hooks/email/inbound-dev' && $method === 'POST':
        email_inbound_dev_controller();
        return;

    case $path === '/hooks/sms/inbound-dev' && $method === 'POST':
        sms_inbound_dev_controller();
        return;

    // -------------------------------------------------
    // Admin Debug – email bridge trace (admin only)
    // -------------------------------------------------
    case $path === '/debug/email-bridge' && $method === 'GET':
        pf_require_admin();
        require_once __DIR__ . '/../app/controllers/admin_debug_controller.php';
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
