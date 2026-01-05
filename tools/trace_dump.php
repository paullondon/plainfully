<?php declare(strict_types=1);
/**
 * tools/trace_dump.php
 *
 * CLI utility to dump a single trace in chronological order.
 *
 * Usage:
 *   php tools/trace_dump.php <trace_id>
 *
 * Notes:
 * - Admin / debug only
 * - Read-only
 * - Safe to run in production (no writes)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$traceId = trim((string)($argv[1] ?? ''));
if ($traceId === '') {
    fwrite(STDERR, "Usage: php tools/trace_dump.php <trace_id>\n");
    exit(2);
}

$ROOT = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
require_once $ROOT . '/app/support/db.php';

try {
    $pdo = pf_db();
} catch (Throwable $e) {
    fwrite(STDERR, "DB unavailable: {$e->getMessage()}\n");
    exit(3);
}

$stmt = $pdo->prepare('
    SELECT created_at, level, stage, event, message, meta_json
    FROM trace_events
    WHERE trace_id = :t
    ORDER BY id ASC
');
$stmt->execute([':t' => $traceId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "No trace events found for: {$traceId}\n";
    exit(0);
}

echo "Trace dump for {$traceId}\n";
echo str_repeat('=', 80) . "\n";

foreach ($rows as $r) {
    printf(
        "[%s] %-5s %-12s %-20s %s\n",
        $r['created_at'],
        strtoupper($r['level']),
        $r['stage'],
        $r['event'],
        $r['message']
    );

    if (!empty($r['meta_json'])) {
        echo "  meta: {$r['meta_json']}\n";
    }
}
