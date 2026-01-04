<?php declare(strict_types=1);

require_once dirname(__DIR__) . '/support/db.php';
require_once dirname(__DIR__) . '/support/trace.php';
require_once dirname(__DIR__) . '/auth/login.php'; // for pf_require_admin()

if (!function_exists('trace_controller')) {
    function trace_controller(): void
    {
        pf_require_admin();

        if (!pf_trace_allowed()) { http_response_code(404); echo "Not found."; return; }

        $pdo = pf_db();
        if (!($pdo instanceof \PDO)) { http_response_code(500); echo "DB unavailable."; return; }

        $traceId = trim((string)($_GET['trace_id'] ?? ''));
        $k = (string)($_GET['k'] ?? '');

        try {
            if ($traceId !== '') {
                $stmt = $pdo->prepare('
                    SELECT created_at, level, stage, event, message, meta_json, queue_id, check_id
                    FROM trace_events
                    WHERE trace_id = :t
                    ORDER BY id ASC
                    LIMIT 2000
                ');
                $stmt->execute([':t' => $traceId]);
                $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                $q = $pdo->prepare('
                    SELECT id, status, mode, from_email, subject, trace_id, created_at, last_error
                    FROM inbound_queue
                    WHERE trace_id = :t
                    ORDER BY id DESC
                    LIMIT 1
                ');
                $q->execute([':t' => $traceId]);
                $queueRow = $q->fetch(\PDO::FETCH_ASSOC) ?: null;

                $vm = [
                    'mode' => 'single',
                    'k' => $k,
                    'trace_id' => $traceId,
                    'queue' => $queueRow,
                    'events' => is_array($events) ? $events : [],
                ];
            } else {
                $stmt = $pdo->query('
                    SELECT trace_id, MAX(created_at) AS last_at, COUNT(*) AS event_count
                    FROM trace_events
                    GROUP BY trace_id
                    ORDER BY last_at DESC
                    LIMIT 100
                ');
                $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

                $vm = ['mode' => 'list', 'k' => $k, 'rows' => is_array($rows) ? $rows : []];
            }

            ob_start();
            require dirname(__DIR__) . '/views/trace/index.php';
            $inner = ob_get_clean();

            if (function_exists('pf_render_shell')) { pf_render_shell('Trace', $inner); }
            else { echo $inner; }

        } catch (\Throwable $e) {
            http_response_code(500);
            echo "Trace error.";
            error_log('trace_controller: ' . $e->getMessage());
        }
    }
}
