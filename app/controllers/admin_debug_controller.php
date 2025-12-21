<?php declare(strict_types=1);

/**
 * Admin debug endpoints (token-protected).
 *
 * SECURITY:
 * - Requires ?token=... matching PLAINFULLY_DEBUG_TOKEN (or DEBUG_TOKEN fallback)
 * - Returns 404 on failure (doesn't leak that a debug endpoint exists)
 */

function pf_debug_require_token(): void
{
    $given = (string)($_GET['token'] ?? '');
    $expected = getenv('PLAINFULLY_DEBUG_TOKEN') ?: (getenv('DEBUG_TOKEN') ?: '');

    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(404);
        echo 'Not Found';
        exit;
    }
}

/**
 * Shows the last N lines of the email bridge trace log.
 * Set PLAINFULLY_DEBUG_TRACE_FILE in .env to wherever debug_trace.php writes.
 */
function admin_debug_email_bridge(): void
{
    //pf_debug_require_token();

    $file = getenv('PLAINFULLY_DEBUG_TRACE_FILE') ?: (sys_get_temp_dir() . '/plainfully_debug_trace.log');
    $limit = max(10, min(2000, (int)($_GET['limit'] ?? 400)));

    header('Content-Type: text/plain; charset=utf-8');

    if (!is_readable($file)) {
        echo "Trace file not found or not readable:\n{$file}\n\n";
        echo "Set PLAINFULLY_DEBUG_TRACE_FILE in .env to the correct path.\n";
        return;
    }

    // Efficient-ish tail (no shell)
    $fp = fopen($file, 'rb');
    if ($fp === false) {
        echo "Failed to open trace file.\n";
        return;
    }

    $lines = [];
    while (!feof($fp)) {
        $line = fgets($fp);
        if ($line !== false) {
            $lines[] = $line;
        }
    }
    fclose($fp);

    $slice = array_slice($lines, -$limit);
    echo "Plainfully Debug · Email Bridge\n";
    echo "File: {$file}\n";
    echo "Showing last {$limit} lines\n";
    echo str_repeat('-', 60) . "\n";
    echo implode('', $slice);
}

/**
 * Debug: show the email bridge trace log.
 */
function debug_email_bridge(): void
{
    header('Content-Type: text/plain; charset=utf-8');

    // This should match wherever debug_trace.php writes.
    $file  = getenv('PLAINFULLY_DEBUG_TRACE_FILE') ?: (sys_get_temp_dir() . '/plainfully_debug_trace.log');
    $limit = max(10, min(2000, (int)($_GET['limit'] ?? 400)));

    if (!is_readable($file)) {
        echo "Trace file not found/readable:\n{$file}\n\n";
        echo "Fix by setting in .env:\nPLAINFULLY_DEBUG_TRACE_FILE=/tmp/plainfully_debug_trace.log\n";
        return;
    }

    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        echo "Failed to read trace file.\n";
        return;
    }

    $slice = array_slice($lines, -$limit);

    echo "Plainfully Debug · Email Bridge\n";
    echo "File: {$file}\n";
    echo "Last {$limit} lines\n";
    echo str_repeat('-', 60) . "\n";
    echo implode("\n", $slice) . "\n";
}
