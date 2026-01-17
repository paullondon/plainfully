<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Web Routes
 * ============================================================
 * Central route definitions.
 */

function pf_route_dispatch(string $path, string $method): void
{
    // Home (Coming Soon)
    if ($path === '/' && $method === 'GET') {
        ob_start();
        require_once PF_ROOT . '/public/coming_soon/coming_soon.php';
        $html = ob_get_clean();

        pf_render_basic_page('Plainfully', $html);
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
    // Health (kept here too, even though index.php hard-bypasses it)
    if ($path === '/health') {
        pf_json(['ok' => true, 'ts' => gmdate('c')]);
    }

    // Debug POST test page (gated by env + token inside the file)
    if ($path === '/debug/post' && ($method === 'GET' || $method === 'POST')) {
        require_once PF_ROOT . '/app/pipelines/debug/test_page.php';
        exit;
    }

    // Debug system snapshot (gated)
    if ($path === '/debug/snapshot' && ($method === 'GET' || $method === 'POST')) {
        require_once PF_ROOT . '/app/pipelines/debug/snapshot_page.php';
        exit;
    }

    // Debug inspect (gated)
    if ($path === '/debug/inspect' && $method === 'GET') {
        require_once PF_ROOT . '/app/pipelines/debug/inspect_page.php';
        exit;
    }

    // Debug logs viewer (gated)
    if ($path === '/debug/logs' && $method === 'GET') {
        require_once PF_ROOT . '/app/pipelines/debug/logs_page.php';
        exit;
    }
    
    // 404 for everything else
    pf_http_error(404, 'Not Found');
}
