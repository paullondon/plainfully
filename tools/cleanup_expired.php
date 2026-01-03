<?php declare(strict_types=1);

/**
 * Plainfully - Daily cleanup for expired non-financial data.
 *
 * This script:
 * 1. Loads config for which tables are purgeable.
 * 2. Deletes rows where expires_at <= NOW() in controlled batches.
 * 3. Logs what it did for monitoring.
 *
 * Run via cron:
 * 0 3 * * * /usr/bin/php /var/www/vhosts/plainfully.com/httpdocs/tools/cleanup_expired.php >> /var/log/plainfully/cleanup.log 2>&1
 */

require __DIR__ . '/env_bootstrap.php';   // central env + autoloader
$config = require __DIR__ . '/../app/config/data_retention.php';

// NOTE:
// This file is in the global namespace (no `namespace ...;`),
// so `use PDO;` / `use PDOException;` is unnecessary and can trigger warnings.
// We use fully-qualified \PDO and \PDOException instead.

// Non-financial tables we are allowed to purge safely
$purgeTables = $config['purge_tables'] ?? [];

// Safety limit: max rows per table per run to avoid heavy locks
$maxRowsPerTable = 10_000;

try {
    $pdo = new \PDO(
        (string)($_ENV['db_dsn'] ?? ''),
        (string)($_ENV['db_user'] ?? ''),
        (string)($_ENV['db_password'] ?? ''),
        [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (\PDOException $e) {
    fwrite(STDERR, '[ERROR] DB connection failed in cleanup_expired.php: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
fwrite(STDOUT, '[' . $now . "] Starting expired data cleanup\n");

foreach ($purgeTables as $table) {
    // Double safety: no weird table names, even though this is static config.
    if (!is_string($table) || !preg_match('/^[a-z0-9_]+$/', $table)) {
        fwrite(STDERR, "[WARN] Skipping suspicious table name: " . (string)$table . "\n");
        continue;
    }

    try {
        $totalDeleted = 0;

        do {
            // Safe because $table is allowlisted by regex + static config
            $stmt = $pdo->prepare("
                DELETE FROM {$table}
                WHERE expires_at IS NOT NULL
                  AND expires_at <= NOW(6)
                LIMIT :limit
            ");

            $stmt->bindValue(':limit', $maxRowsPerTable, \PDO::PARAM_INT);
            $stmt->execute();

            $deletedThisRound = $stmt->rowCount();
            $totalDeleted += $deletedThisRound;
        } while ($deletedThisRound === $maxRowsPerTable);

        if ($totalDeleted > 0) {
            fwrite(STDOUT, "[INFO] Purged {$totalDeleted} row(s) from {$table}\n");
        }
    } catch (\PDOException $e) {
        // Log error but continue with other tables.
        fwrite(STDERR, "[ERROR] Failed purging table {$table}: " . $e->getMessage() . PHP_EOL);
    }
}

$end = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
fwrite(STDOUT, '[' . $end . "] Cleanup complete\n");
