<?php declare(strict_types=1);

/**
 * ============================================================
 * Web Result View
 * ============================================================
 * - Displays the latest outbound result for a trace_id
 * - Finalises web deliveries (status -> sent) on view
 */

require_once PF_ROOT . '/app/bootstrap.php';

$traceId = trim((string)($_GET['trace_id'] ?? ''));

if ($traceId === '' || !preg_match('/^[a-f0-9\-]{16,64}$/i', $traceId)) {
    pf_http_error(404, 'Not Found');
}

try {
    $pdo = pf_pdo();

    $oid = (int)($_GET['oid'] ?? 0);

    if ($oid > 0) {
        // Exact outbound row (best for debug + correctness)
        $sel = $pdo->prepare("
            SELECT id, trace_id, channel, status, payload_json
            FROM pf_outbound_queue
            WHERE id = :id AND trace_id = :trace_id
            LIMIT 1
        ");
        $sel->execute([':id' => $oid, ':trace_id' => $traceId]);
        $row = $sel->fetch();
    } else {
        // Prefer latest WEB outbound row for this trace (so viewing marks web as viewed)
        $sel = $pdo->prepare("
            SELECT id, trace_id, channel, status, payload_json
            FROM pf_outbound_queue
            WHERE trace_id = :trace_id AND channel='web'
            ORDER BY id DESC
            LIMIT 1
        ");
        $sel->execute([':trace_id' => $traceId]);
        $row = $sel->fetch();

        // Fallback: latest any channel if no web row exists
        if (!is_array($row)) {
            $sel2 = $pdo->prepare("
                SELECT id, trace_id, channel, status, payload_json
                FROM pf_outbound_queue
                WHERE trace_id = :trace_id
                ORDER BY id DESC
                LIMIT 1
            ");
            $sel2->execute([':trace_id' => $traceId]);
            $row = $sel2->fetch();
        }
    }

    if (!is_array($row)) {
        pf_http_error(404, 'Not Found');
    }

    $outId   = (int)$row['id'];
    $channel = (string)$row['channel'];
    $status  = (string)$row['status'];

    $payload = json_decode((string)$row['payload_json'], true);
    if (!is_array($payload)) { $payload = []; }

    // Mark viewed only for web rows
    if ($channel === 'web') {
        $upd = $pdo->prepare("
            UPDATE pf_outbound_queue
            SET status='sent',
                viewed_at = COALESCE(viewed_at, NOW())
            WHERE id=:id
            LIMIT 1
        ");
        $upd->execute([':id' => $outId]);
        $status = 'sent';
    }


} catch (Throwable $e) {
    pf_log('error', 'Result view error', ['err' => $e->getMessage()]);
    pf_http_error(500, 'Server Error');
}

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

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
            Channel: <strong>' . $esc($channel) . '</strong><br>
            Status: <strong>' . $esc($status) . '</strong>
        </p>

        <hr>

        <p><strong>' . $esc($resultState) . '</strong></p>
        <p>' . $esc($resultMsg) . '</p>

        <p class="small">
            Processed: ' . $esc($processedAt ?: '—') . '
        </p>
    </div>
');
