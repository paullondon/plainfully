<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Web Routes
 * ============================================================
 * Purpose:
 *   - Central route definitions (keeps public/index.php tiny)
 */

function pf_route_dispatch(string $path, string $method): void
{
    // Health check
    if ($path === '/health') {
        pf_json(['ok' => true, 'ts' => gmdate('c')]);
    }

    // Home
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
        require_once PF_ROOT . '/app/pipelines/ingest/web/endpoint.php';
        exit;
    }

    // Result view (placeholder)
    if ($path === '/r' && $method === 'GET') {
        require_once PF_ROOT . '/app/pipelines/deliver/web/result_view.php';
        exit;
    }

    pf_http_error(404, 'Not Found');
}
