<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/controllers/admin_debug_controller.php
 * Purpose:
 *   Admin-only debug endpoints.
 *
 * Current endpoints:
 *   - GET /debug/email-bridge
 *       Shows the last N lines of the filesystem debug trace log.
 *
 * Security:
 *   - MUST be protected by pf_require_admin() at the router level.
 *   - We intentionally DO NOT use query-string tokens for access here
 *     because you want to remove token access to trace/debug views.
 * ============================================================
 */

/**
 * Shows the last N lines of the email bridge trace log.
 * Set PLAINFULLY_DEBUG_TRACE_FILE in .env to wherever debug_trace.php writes.
 */
function admin_debug_email_bridge(): void
{
    $file  = getenv('PLAINFULLY_DEBUG_TRACE_FILE') ?: (sys_get_temp_dir() . '/plainfully_debug_trace.log');
    $limit = max(10, min(2000, (int)($_GET['limit'] ?? 400)));

    header('Content-Type: text/plain; charset=utf-8');

    if (!is_readable($file)) {
        echo "Trace file not found or not readable:\n{$file}\n\n";
        echo "Set PLAINFULLY_DEBUG_TRACE_FILE in .env to the correct path.\n";
        return;
    }

    // Efficient-ish tail without shell_exec (portable)
    $fp = fopen($file, 'rb');
    if ($fp === false) {
        echo "Failed to open trace file.\n";
        return;
    }

    $lines = [];
    while (!feof($fp)) {
        $line = fgets($fp);
        if ($line !== false) { $lines[] = $line; }
    }
    fclose($fp);

    $slice = array_slice($lines, -$limit);

    echo "Plainfully Admin Debug · Email Bridge Trace\n";
    echo "File: {$file}\n";
    echo "Showing last {$limit} lines\n";
    echo str_repeat('-', 60) . "\n";
    echo implode('', $slice);
}
