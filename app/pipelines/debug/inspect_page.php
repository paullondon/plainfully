<?php declare(strict_types=1);

/**
 * Debug Tool: Inspect a trace_id
 * URL:
 *   /debug/inspect?t=TOKEN&trace_id=...
 *
 * Shows:
 * - Inbound row (latest) + decoded payload
 * - Outbound row (latest) + decoded payload
 */

require_once PF_ROOT . '/app/bootstrap.php';

$enabled  = (pf_env('PF_DEBUG_TOOLS', '0') === '1');
$token    = (string)pf_env('PF_DEBUG_TOKEN', '');
$reqToken = (string)($_GET['t'] ?? '');
$traceId  = trim((string)($_GET['trace_id'] ?? ''));

if (!$enabled) { pf_http_error(404, 'Not Found'); }
if ($token === '' || !hash_equals($token, $reqToken)) { pf_http_error(404, 'Not Found'); }
if ($traceId === '') { pf_http_error(400, 'Missing trace_id'); }

$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

try {
    $pdo = pf_pdo();

    $inStmt = $pdo->prepare("
        SELECT id, trace_id, channel, status, attempts, created_at, payload_json
        FROM pf_inbound_queue
        WHERE trace_id = :trace_id
        ORDER BY id DESC
        LIMIT 1
    ");
    $inStmt->execute([':trace_id' => $traceId]);
    $in = $inStmt->fetch();

    $outStmt = $pdo->prepare("
        SELECT id, trace_id, channel, status, attempts, created_at, payload_json
        FROM pf_outbound_queue
        WHERE trace_id = :trace_id
        ORDER BY id DESC
        LIMIT 1
    ");
    $outStmt->execute([':trace_id' => $traceId]);
    $out = $outStmt->fetch();

    $inDecoded  = is_array($in)  ? (json_decode((string)$in['payload_json'], true) ?: null) : null;
    $outDecoded = is_array($out) ? (json_decode((string)$out['payload_json'], true) ?: null) : null;

} catch (Throwable $e) {
    pf_log('error', 'Inspect failed', ['err' => $e->getMessage()]);
    pf_http_error(500, 'Server Error');
}

$card = static function(string $title, ?array $row, $decoded, callable $esc): string {
    $html  = '<div class="card">';
    $html .= '<h2 class="card-title" style="margin:0;">' . $esc($title) . '</h2>';

    if (!is_array($row)) {
        $html .= '<p class="small" style="margin-top:10px;">No row found for this trace_id.</p></div>';
        return $html;
    }

    $summary = [
        'id'         => (string)$row['id'],
        'trace_id'   => (string)$row['trace_id'],
        'channel'    => (string)$row['channel'],
        'status'     => (string)$row['status'],
        'attempts'   => (string)$row['attempts'],
        'created_at' => (string)$row['created_at'],
    ];

    $html .= '<div class="small" style="margin-top:10px;"><strong>Row summary</strong></div>';
    $html .= '<pre style="white-space:pre-wrap;word-break:break-word;margin:8px 0 0 0;padding:12px;border-radius:12px;border:1px solid var(--pf-border);background:var(--pf-bg);">'
          . $esc(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
          . '</pre>';

    $html .= '<div class="small" style="margin-top:12px;"><strong>Decoded payload_json</strong></div>';
    $html .= '<pre style="white-space:pre-wrap;word-break:break-word;margin:8px 0 0 0;padding:12px;border-radius:12px;border:1px solid var(--pf-border);background:var(--pf-bg);">'
          . $esc(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
          . '</pre>';

    $html .= '</div>';
    return $html;
};

$top = '
  <div class="card">
    <h1 class="card-title">Debug: Inspect trace</h1>
    <p class="small"><strong>trace_id:</strong> <code>' . $esc($traceId) . '</code></p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
      <a class="btn" href="/debug/snapshot?t=' . $esc($reqToken) . '">Back to snapshot</a>
      <a class="btn" href="/debug/post?t=' . $esc($reqToken) . '">POST tester</a>
    </div>
  </div>
';

$layout = '
  <div style="display:grid;gap:16px;">
    ' . $top . '
    <div style="display:grid;gap:16px;" class="pf-grid">
      ' . $card('Inbound (pf_inbound_queue)', is_array($in) ? $in : null, $inDecoded, $esc) . '
      ' . $card('Outbound (pf_outbound_queue)', is_array($out) ? $out : null, $outDecoded, $esc) . '
    </div>
  </div>

  <style>
    @media (min-width: 980px){
      .pf-grid{ grid-template-columns: 1fr 1fr !important; }
    }
  </style>
';

pf_render_basic_page('Debug Inspect', $layout);
