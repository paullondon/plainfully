<?php declare(strict_types=1);
/**
 * tools/trace_dump.php
 * Usage: php tools/trace_dump.php <trace_id>
 */
if (PHP_SAPI !== 'cli') { echo "CLI only\n"; exit(1); }

$traceId = trim((string)($argv[1] ?? ''));
if ($traceId === '') { echo "Usage: php tools/trace_dump.php <trace_id>\n"; exit(2); }

$ROOT = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
require_once $ROOT . '/app/support/db.php';

$pdo = pf_db();
if (!($pdo instanceof PDO)) { echo "DB unavailable\n"; exit(3); }

$stmt = $pdo->prepare('
  SELECT created_at, stage, level, alert, action, summary, data_json
  FROM trace_events
  WHERE trace_id = :t
  ORDER BY id ASC
');
$stmt->execute([':t' => $traceId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!is_array($rows) || count($rows) === 0) { echo "No trace events found for: {$traceId}\n"; exit(0); }

foreach ($rows as $r) {
  $line = sprintf(
    "[%s] %-11s %-5s %-6s %-16s %s",
    (string)$r['created_at'],
    (string)$r['stage'],
    (string)$r['level'],
    ((int)$r['alert'] === 1 ? 'ALERT' : ''),
    (string)$r['action'],
    (string)$r['summary']
  );
  echo $line . "\n";
  $dj = (string)($r['data_json'] ?? '');
  if ($dj !== '') { echo "  data: " . $dj . "\n"; }
}
