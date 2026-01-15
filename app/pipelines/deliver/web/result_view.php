<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Result View (/r)
 * ============================================================
 * Purpose:
 *  - Render a specific outbound result row (preferred via ?oid=)
 *  - If oid is present: mark THAT row as sent + viewed_at (debug correctness)
 *  - Always re-read the DB after update to display the truth
 *
 * Debug (optional):
 *  - If PF_DEBUG_TOOLS=1 and ?t=PF_DEBUG_TOKEN is provided, show DB + rowcount.
 */

require_once PF_ROOT . '/app/bootstrap.php';

$traceId = trim((string)($_GET['trace_id'] ?? ''));
$oid     = (int)($_GET['oid'] ?? 0);

if ($traceId === '' || !preg_match('/^[a-f0-9\-]{16,64}$/i', $traceId)) {
    pf_http_error(404, 'Not Found');
}

$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$debugEnabled = (pf_env('PF_DEBUG_TOOLS', '0') === '1');
$debugToken   = (string)pf_env('PF_DEBUG_TOKEN', '');
$reqToken     = (string)($_GET['t'] ?? '');
$showDiag     = $debugEnabled && $debugToken !== '' && hash_equals($debugToken, $reqToken);

$dbName = '';
$updateRowCount = null;

try {
    $pdo = pf_pdo();

        // For diagnostics
        pf_log('info', 'RESULT VIEW DEBUG CHECK', [
            'PF_DEBUG_TOOLS' => pf_env('PF_DEBUG_TOOLS', 'not-set'),
            'PF_DEBUG_TOKEN' => pf_env('PF_DEBUG_TOKEN', 'not-set'),
            'token_ok'       => hash_equals((string)pf_env('PF_DEBUG_TOKEN', ''), (string)($_GET['t'] ?? '')),
        ]);

    if ($showDiag) {
        $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    }

    // Select the exact outbound row if oid provided
    if ($oid > 0) {
        $sel = $pdo->prepare("
            SELECT id, trace_id, channel, status, viewed_at, payload_json, created_at
            FROM pf_outbound_queue
            WHERE id = :id
            LIMIT 1
        ");
        $sel->execute([':id' => $oid]);
        $row = $sel->fetch();

        if (!is_array($row)) {
            pf_http_error(404, 'Not Found');
        }

        // Optional safety: ensure the oid matches the trace_id you're passing (helps catch wrong links)
        if ((string)$row['trace_id'] !== $traceId) {
            pf_http_error(404, 'Not Found');
        }

        // Force “viewed” state for this specific outbound row
        $upd = $pdo->prepare("
            UPDATE pf_outbound_queue
            SET status='sent',
                viewed_at = COALESCE(viewed_at, NOW())
            WHERE id = :id
            LIMIT 1
        ");
        $upd->execute([':id' => $oid]);

        $updateRowCount = $upd->rowCount();

        pf_log('info', 'Result view marked outbound as viewed', [
            'out_id'   => $oid,
            'trace_id' => $traceId,
            'rowcount' => $updateRowCount,
        ]);

        // Re-read the row (this is the source of truth we display)
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
        // Fallback: latest outbound for trace_id
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

$diagHtml = '';
if ($showDiag) {
    $diagHtml = '
      <div class="card" style="margin-top:12px;">
        <h2 class="card-title" style="margin:0;">Debug diagnostics</h2>
        <p class="small" style="margin-top:10px;">
          DB: <strong>' . $esc($dbName ?: '—') . '</strong><br>
          UPDATE rowcount: <strong>' . $esc($updateRowCount === null ? '—' : (string)$updateRowCount) . '</strong>
        </p>
      </div>
    ';
}

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

  ' . $diagHtml . '
');
