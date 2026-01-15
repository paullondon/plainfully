<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Result View (/r)
 * ============================================================
 * Purpose:
 *  - Render a specific outbound result row (preferred via ?oid=)
 *  - If oid is present: mark THAT row as sent + viewed_at (debug correctness)
 *  - If oid missing: fall back to latest row for trace_id (no forced updates)
 *
 * Notes:
 *  - oid path is used by debug tooling to prove E2E behaviour.
 */

require_once PF_ROOT . '/app/bootstrap.php';

$traceId = trim((string)($_GET['trace_id'] ?? ''));
$oid     = (int)($_GET['oid'] ?? 0);

if ($traceId === '' || !preg_match('/^[a-f0-9\-]{16,64}$/i', $traceId)) {
    pf_http_error(404, 'Not Found');
}

$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

try {
    $pdo = pf_pdo();

    // 1) Select the exact outbound row if oid provided (debug uses this)
    if ($oid > 0) {
        $sel = $pdo->prepare("
            SELECT id, trace_id, channel, status, viewed_at, payload_json, created_at
            FROM pf_outbound_queue
            WHERE id = :id AND trace_id = :trace_id
            LIMIT 1
        ");
        $sel->execute([':id' => $oid, ':trace_id' => $traceId]);
        $row = $sel->fetch();

        if (!is_array($row)) {
            pf_http_error(404, 'Not Found');
        }

        // 2) Force “viewed” state for this specific outbound row (debug truth)
        $upd = $pdo->prepare("
            UPDATE pf_outbound_queue
            SET status='sent',
                viewed_at = COALESCE(viewed_at, NOW())
            WHERE id = :id
            LIMIT 1
        ");
        $upd->execute([':id' => $oid]);

        pf_log('info', 'Result view marked outbound as viewed', [
            'out_id'   => $oid,
            'trace_id' => $traceId,
            'rowcount' => $upd->rowCount(),
        ]);

        // 3) Re-read so the page reflects DB truth (no guessing)
        $sel2 = $pdo->prepare("
            SELECT id, trace_id, channel, status, viewed_at, payload_json, created_at
            FROM pf_outbound_queue
            WHERE id = :id
            LIMIT 1
        ");
        $sel2->execute([':id' => $oid]);
        $row = $sel2->fetch();

        if (!is_array($row)) {
            pf_http_error(404, 'Not Found');
        }

    } else {
        // Fallback: latest outbound for trace_id (public link behaviour)
        $sel = $pdo->prepare("
            SELECT id, trace_id, channel, status, viewed_at, payload_json, created_at
            FROM pf_outbound_queue
            WHERE trace_id = :trace_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $sel->execute([':trace_id' => $traceId]);
        $row = $sel->fetch();

        if (!is_array($row)) {
            pf_http_error(404, 'Not Found');
        }
    }

    $outId    = (int)$row['id'];
    $channel  = (string)$row['channel'];
    $status   = (string)$row['status'];
    $viewedAt = (string)($row['viewed_at'] ?? '');
    $payload  = json_decode((string)$row['payload_json'], true);
    if (!is_array($payload)) { $payload = []; }

} catch (Throwable $e) {
    pf_log('error', 'Result view failed', ['err' => $e->getMessage()]);
    pf_http_error(500, 'Server Error');
}

$resultMsg   = (string)($payload['result']['message'] ?? 'Result ready.');
$resultState = (string)($payload['result']['status'] ?? 'ok');
$processedAt = (string)($payload['result']['processed_at'] ?? '');

pf_render_basic_page('Plainfully Result', '
  <div class="card">
    <h1 class="card-title">Your result</h1>

    <p class="small">
      Trace ID:<br>
      <code>' . $esc($traceId) . '</code>
    </p>

    <p class="small">
      Outbound ID: <strong>' . $esc((string)$outId) . '</strong><br>
      Channel: <strong>' . $esc($channel) . '</strong><br>
      Status: <strong>' . $esc($status) . '</strong><br>
      Viewed at: <strong>' . $esc($viewedAt !== '' ? $viewedAt : '—') . '</strong>
    </p>

    <hr>

    <p><strong>' . $esc($resultState) . '</strong></p>
    <p>' . $esc($resultMsg) . '</p>

    <p class="small">Processed: ' . $esc($processedAt !== '' ? $processedAt : '—') . '</p>
  </div>
');
// End of file