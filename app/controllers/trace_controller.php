<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/controllers/trace_controller.php
 * Purpose:
 *   Admin-only Trace Viewer controller for debugging a single processing run.
 *
 * Key rules:
 *   - ADMIN ONLY (no token access)
 *   - Trace data is treated as short-lived operational telemetry
 *   - Only show traces from the last 1 hour (TTL-style guard)
 *
 * Routes:
 *   GET /trace               -> recent traces (last 1 hour)
 *   GET /trace?trace_id=...  -> a single trace (last 1 hour)
 *
 * Security:
 *   - pf_require_admin() enforced at entry
 *   - 404 if tracing is disabled (reduces attack surface)
 *   - Output is escaped in the view
 * ============================================================
 */

require_once dirname(__DIR__) . '/support/db.php';
require_once dirname(__DIR__) . '/support/trace.php';
require_once dirname(__DIR__) . '/auth/login.php'; // pf_require_admin(), pf_is_admin()

if (!function_exists('trace_controller')) {
    function trace_controller(): void
    {
        // ------------------------------------------------------------
        // 1) Hard admin gate (token access removed by design)
        // ------------------------------------------------------------
        pf_require_admin();

        // ------------------------------------------------------------
        // 2) Only expose UI when tracing is enabled (reduces surface)
        // ------------------------------------------------------------
        if (!pf_trace_enabled()) {
            http_response_code(404);
            echo "Not found.";
            return;
        }

        // ------------------------------------------------------------
        // 3) DB connection
        // ------------------------------------------------------------
        try {
            $pdo = pf_db();
        } catch (\Throwable $e) {
            http_response_code(500);
            echo "DB unavailable.";
            error_log('trace_controller: DB unavailable: ' . $e->getMessage());
            return;
        }

        if (!($pdo instanceof \PDO)) {
            http_response_code(500);
            echo "DB unavailable.";
            return;
        }

        // ------------------------------------------------------------
        // 4) Read request
        // ------------------------------------------------------------
        $traceId = trim((string)($_GET['trace_id'] ?? ''));

        // ------------------------------------------------------------
        // 5) Fetch + render
        // ------------------------------------------------------------
        try {
            if ($traceId !== '') {
                // Single trace (last 1 hour only)
                $stmt = $pdo->prepare('
                    SELECT created_at, level, stage, event AS event_name, message, meta_json, queue_id, check_id
                    FROM trace_events
                    WHERE trace_id = :t
                      AND created_at >= (NOW() - INTERVAL 1 HOUR)
                    ORDER BY id ASC
                    LIMIT 4000
                ');
                $stmt->execute([':t' => $traceId]);
                $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                // Optional inbound_queue row linked by trace_id (also last 1 hour)
                $q = $pdo->prepare('
                    SELECT id, status, mode, from_email, subject, trace_id, created_at, last_error
                    FROM inbound_queue
                    WHERE trace_id = :t
                      AND created_at >= (NOW() - INTERVAL 1 HOUR)
                    ORDER BY id DESC
                    LIMIT 1
                ');
                $q->execute([':t' => $traceId]);
                $queueRow = $q->fetch(\PDO::FETCH_ASSOC) ?: null;

                $vm = [
                    'mode'     => 'single',
                    'trace_id' => $traceId,
                    'queue'    => $queueRow,
                    'events'   => is_array($events) ? $events : [],
                ];
            } else {
                // Recent traces (last 1 hour only)
                $stmt = $pdo->query('
                    SELECT trace_id, MAX(created_at) AS last_at, COUNT(*) AS event_count
                    FROM trace_events
                    WHERE created_at >= (NOW() - INTERVAL 1 HOUR)
                    GROUP BY trace_id
                    ORDER BY last_at DESC
                    LIMIT 150
                ');
                $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

                $vm = [
                    'mode' => 'list',
                    'rows' => is_array($rows) ? $rows : [],
                ];
            }

            ob_start();
            require dirname(__DIR__) . '/views/trace/index.php';
            $inner = (string)ob_get_clean();

            if (function_exists('pf_render_shell')) {
                pf_render_shell('Trace', $inner);
            } else {
                echo $inner;
            }
        } catch (\Throwable $e) {
            http_response_code(500);
            echo "Trace error.";
            error_log('trace_controller: ' . $e->getMessage());
        }
    }
}
