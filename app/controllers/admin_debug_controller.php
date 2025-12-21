<?php declare(strict_types=1);

function admin_debug_email_bridge_controller(): void
{
    if (!(getenv('PLAINFULLY_DEBUG') === 'true' || getenv('PLAINFULLY_DEBUG') === '1')) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $token = (string)($_GET['token'] ?? '');
    $expected = (string)(getenv('DEBUG_TOKEN') ?: '');

    if ($expected === '' || !hash_equals($expected, $token)) {
        http_response_code(401);
        echo 'Unauthorised';
        return;
    }

    $pdo = pf_db();

    $channel = (string)($_GET['channel'] ?? 'email-bridge');
    $runId   = (string)($_GET['run'] ?? '');
    $limit   = 200;

    if ($runId !== '') {
        $stmt = $pdo->prepare('
            SELECT *
            FROM debug_traces
            WHERE run_id = :run_id
            ORDER BY id ASC
        ');
        $stmt->execute([':run_id' => $runId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $stmt = $pdo->prepare('
            SELECT *
            FROM debug_traces
            WHERE channel = :channel
            ORDER BY id DESC
            LIMIT :lim
        ');
        $stmt->bindValue(':channel', $channel);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    header('Content-Type: text/html; charset=utf-8');

    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Plainfully Debug · Email Bridge</title>';
    echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px;font-size:13px}th{background:#f3f4f6;text-align:left}code{white-space:pre-wrap}</style>';
    echo '</head><body>';

    echo '<h2>Debug traces</h2>';
    echo '<p><strong>Channel:</strong> ' . htmlspecialchars($channel) . '</p>';

    echo '<form method="get" style="margin:12px 0">';
    echo '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">';
    echo '<label>Channel <input name="channel" value="' . htmlspecialchars($channel) . '"></label> ';
    echo '<label>Run ID <input name="run" value="' . htmlspecialchars($runId) . '" placeholder="optional"></label> ';
    echo '<button type="submit">View</button>';
    echo '</form>';

    echo '<table><thead><tr>';
    echo '<th>Time</th><th>Run</th><th>Channel</th><th>Step</th><th>Level</th><th>Message</th><th>Meta</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $r) {
        $meta = $r['meta_json'] ?? '';
        echo '<tr>';
        echo '<td>' . htmlspecialchars((string)$r['created_at']) . '</td>';
        echo '<td><a href="?token=' . urlencode($token) . '&channel=' . urlencode($channel) . '&run=' . urlencode((string)$r['run_id']) . '">' . htmlspecialchars((string)$r['run_id']) . '</a></td>';
        echo '<td>' . htmlspecialchars((string)$r['channel']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['step']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['level']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['message']) . '</td>';
        echo '<td><code>' . htmlspecialchars((string)$meta) . '</code></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</body></html>';
}
