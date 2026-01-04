<?php declare(strict_types=1);

/**
 * Plainfully – CLI cleanup script
 *
 * Deletes clarification records older than 28 days.
 * Intended to be run by cron / scheduled task (e.g. hourly).
 *
 * Usage (from project root):
 *   php tools/cleanup_clarifications.php
 */

// ---------------------------------------------------------
// 1. Ensure we're running from CLI, not web
// ---------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    // Never allow this to be hit via web
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

// Allow this script to be run from any working directory.
// We resolve paths relative to the script location.
$rootPath = dirname(__DIR__);

// ---------------------------------------------------------
// 2. Minimal .env loader (same pattern as web bootstrap)
// ---------------------------------------------------------
$envPath = $rootPath . '/.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        putenv("$k=$v");
    }
}

// ---------------------------------------------------------
// 3. Load DB helper only (no router, no HTTP stuff)
// ---------------------------------------------------------
require_once $rootPath . '/config/app.php';            // if needed by pf_db
require_once $rootPath . '/app/support/db.php';

/**
 * Simple logger for this script.
 * Writes to STDOUT (cron will capture it) and to PHP's error_log.
 */
function pf_cleanup_log(string $message): void
{
    $timestamped = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    echo $timestamped . PHP_EOL;
    error_log('Plainfully cleanup: ' . $message);
}

try {
    $pdo = pf_db();

    // -----------------------------------------------------
    // 4. Perform the delete – 28 days rolling window
    // -----------------------------------------------------
    $sql = "
        DELETE FROM clarifications
        WHERE created_at < (NOW() - INTERVAL 28 DAY)
    ";

    $stmt   = $pdo->prepare($sql);
    $stmt->execute();
    $deleted = $stmt->rowCount();

    pf_cleanup_log("Deleted {$deleted} clarifications older than 28 days.");

    exit(0);
} catch (Throwable $e) {
    pf_cleanup_log('Cleanup failed: ' . $e->getMessage());
    exit(1);
}
