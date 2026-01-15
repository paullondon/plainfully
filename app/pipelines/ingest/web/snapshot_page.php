<?php declare(strict_types=1);

/**
 * ============================================================
 * Debug Tool: System Snapshot
 * ============================================================
 * Purpose:
 *  - One-page view of queue health + recent items
 *  - Clickable trace_id -> /debug/inspect (single place to verify flow)
 *
 * Safety:
 *  - Enabled ONLY when PF_DEBUG_TOOLS=1
 *  - Requires PF_DEBUG_TOKEN via ?t=...
 */

require_once PF_ROOT . '/app/bootstrap.php';

$enabled  = (pf_env('PF_DEBUG_TOOLS', '0') === '1');
$token    = (string)pf_env('PF_DEBUG_TOKEN', '');
$reqToken = (string)($_GET['t'] ?? '');

if (!$enabled) { pf_http_error(404, 'Not Found'); }
if ($token === '' || !hash_equals($token, $reqToken)) { pf_http_error(404, 'Not Found'); }

$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

try {
    $pdo = pf_pdo();

    // Counts
    $inCounts = $pdo->query("
        SELECT status, COUNT(*) c
        FROM pf_inbound_queue
        GROUP BY status
        ORDER BY status
    ")->fetchAll();

    $outCounts = $pdo->query("
        SELECT status, COUNT(*) c
        FROM pf_outbound_queue
        GROUP BY status
        ORDER BY status
    ")->fetchAll();

    // Recent rows
    $inRecent = $pdo->query("
        SELECT id, trace_id, channel, status, attempts, created_at
        FROM pf_inbound_queue
        ORDER BY id DESC
        LIMIT 5
    ")->fetchAll();

    $outRecent = $pdo->query("
        SELECT id, trace_id, channel, status, viewed_at, attempts, created_at
        FROM pf_outbound_queue
        ORDER BY id DESC
        LIMIT 5
    ")->fetchAll();

    // Latest trace ids (for quick inspect buttons)
    $latestInTrace = null;
    if (!empty($inRecent) && isset($inRecent[0]['trace_id'])) {
        $latestInTrace = (string)$inRecent[0]['trace_id'];
    }

    $latestOutTrace = null;
    if (!empty($outRecent) && isset($outRecent[0]['trace_id'])) {
        $latestOutTrace = (string)$outRecent[0]['trace_id'];
    }

} catch (Throwable $e) {
    pf_log('error', 'Snapshot failed', ['err' => $e->getMessage()]);
    pf_http_error(500, 'Server Error');
}

/**
 * Render a table and make trace_id clickable if present.
 */
$renderTable = static function(string $title, array $rows) use ($esc): string {
    $html = '<div class="card">';
    $html .= '<h2 class="card-title" style="margin:0;">' . $esc($title) . '</h2>';

    if (empty($rows)) {
        $html .= '<p class="small" style="margin-top:10px;">No rows.</p></div>';
        return $html;
    }

    $html .= '<div style="overflow:auto;margin-top:12px;">';
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:0.95rem;">';

    // headers
    $headers = array_keys($rows[0]);

    // Inject View column if trace_id exists
    $hasTrace = in_array('trace_id', $headers, true);
    if ($hasTrace) {
        $headers[] = 'view';
    }

    $html .= '<thead><tr>';
    foreach ($headers as $h) {
        $html .= '<th style="text-align:left;padding:10px;border-bottom:1px solid var(--pf-border);" class="small">'
              . $esc((string)$h)
              . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $r) {
        $html .= '<tr>';

        foreach ($headers as $h) {
            if ($h === 'view' && $hasTrace) {
                $traceId = (string)($r['trace_id'] ?? '');
                if ($traceId !== '') {
                    $url = '/r?trace_id=' . rawurlencode($traceId);
                    $html .= '<td style="padding:10px;border-bottom:1px solid var(--pf-border);">'
                          . '<a class="btn" href="' . $esc($url) . '" target="_blank" rel="noopener">View</a>'
                          . '</td>';
                } else {
                    $html .= '<td style="padding:10px;border-bottom:1px solid var(--pf-border);">—</td>';
                }
                continue;
            }

            $v = (string)($r[$h] ?? '');
            $html .= '<td style="padding:10px;border-bottom:1px solid var(--pf-border);vertical-align:top;">'
                  . $esc($v)
                  . '</td>';
        }

        $html .= '</tr>';
    }

    $html .= '</tbody></table></div></div>';
    return $html;
};


// Quick inspect buttons
$inspectBtns = '';
if ($latestInTrace !== null) {
    $inspectBtns .= '<a class="btn" href="/debug/inspect?t=' . $esc($reqToken) . '&trace_id=' . $esc($latestInTrace) . '">Inspect latest inbound</a>';
}
if ($latestOutTrace !== null) {
    $inspectBtns .= '<a class="btn" href="/debug/inspect?t=' . $esc($reqToken) . '&trace_id=' . $esc($latestOutTrace) . '">Inspect latest outbound</a>';
}

// Layout
$countsCard = '<div class="card">
  <h1 class="card-title">Debug: System Snapshot</h1>
  <p class="small">Queues + recent items. Token-gated. Disable via <code>PF_DEBUG_TOOLS=0</code>.</p>

  <div class="card-row" style="margin-top:12px;">
    <div style="flex:1;min-width:240px;">
      <div class="small"><strong>Inbound counts</strong></div>
      <pre style="white-space:pre-wrap;word-break:break-word;margin:8px 0 0 0;padding:12px;border-radius:12px;border:1px solid var(--pf-border);background:var(--pf-bg);">'
      . $esc(json_encode($inCounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) .
      '</pre>
    </div>

    <div style="flex:1;min-width:240px;">
      <div class="small"><strong>Outbound counts</strong></div>
      <pre style="white-space:pre-wrap;word-break:break-word;margin:8px 0 0 0;padding:12px;border-radius:12px;border:1px solid var(--pf-border);background:var(--pf-bg);">'
      . $esc(json_encode($outCounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) .
      '</pre>
    </div>
  </div>

  <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
    <a class="btn" href="/debug/post?t=' . $esc($reqToken) . '">POST tester</a>
    <a class="btn" href="/debug/snapshot?t=' . $esc($reqToken) . '">Refresh snapshot</a>
    ' . $inspectBtns . '
  </div>
</div>';

$layout = '
  <div style="display:grid;gap:16px;">
    ' . $countsCard . '
    <div style="display:grid;gap:16px;" class="pf-grid">
      ' . $renderTable('Recent inbound (last 5)', $inRecent) . '
      ' . $renderTable('Recent outbound (last 5)', $outRecent) . '
    </div>
  </div>

  <style>
    @media (min-width: 980px){
      .pf-grid{ grid-template-columns: 1fr 1fr !important; }
    }
  </style>
';

pf_render_basic_page('Debug Snapshot', $layout);
