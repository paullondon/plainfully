<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Public Front Door
 * ============================================================
 * File: public/index.php
 * Purpose:
 *   - Single entry point (router)
 *   - Loads app bootstrap
 *   - Dispatches to pipeline endpoints (ingest/process/deliver)
 *
 * Security:
 *   - Sets safe headers
 *   - Rejects unexpected methods where applicable
 */

require_once __DIR__ . '/../app/bootstrap.php';

pf_send_security_headers();

// Very small router for MVP (expand later)
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    // Health check
    if ($path === '/health') {
        pf_json(['ok' => true, 'ts' => gmdate('c')]);
    }

    // Help hub placeholder (future: /help)
    if ($path === '/' && $method === 'GET') {
        pf_render_basic_page('Plainfully (Reboot)', '
            <div class="card">
              <h1 class="card-title">Plainfully is rebooted</h1>
              <p class="small">Skeleton is live. Next: wire ingest → queue → process → deliver.</p>
              <p class="small">Try <code>/health</code></p>
            </div>
        ');
    }

    // Ingest endpoints (placeholders)
    if ($path === '/ingest/web' && $method === 'POST') {
        require_once __DIR__ . '/../app/pipelines/ingest/web/endpoint.php';
        exit;
    }

    // Result view (placeholder)
    if ($path === '/r' && $method === 'GET') {
        require_once __DIR__ . '/../app/pipelines/deliver/web/result_view.php';
        exit;
    }

    pf_http_error(404, 'Not Found');
} catch (Throwable $e) {
    // Fail-closed for public routes (return generic error)
    pf_log('error', 'Unhandled exception', ['err' => $e->getMessage()]);
    pf_http_error(500, 'Server Error');
}
