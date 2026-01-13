<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Web Routes
 * ============================================================
 * Central route definitions.
 */

function pf_route_dispatch(string $path, string $method): void
{
    // Debug POST test page (gated by env + token inside the file)
    if ($path === '/debug/post' && ($method === 'GET' || $method === 'POST')) {
        require_once PF_ROOT . '/app/pipelines/ingest/web/test_page.php';
        exit;
    }

    // Health (kept here too, even though index.php hard-bypasses it)
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

    // Ingest (web)
    if ($path === '/ingest/web' && $method === 'POST') {
        require_once PF_ROOT . '/app/pipelines/ingest/web/endpoint.php';
        exit;
    }

    // Result view placeholder
    if ($path === '/r' && $method === 'GET') {
        require_once PF_ROOT . '/app/pipelines/deliver/web/result_view.php';
        exit;
    }

    pf_http_error(404, 'Not Found');
}
