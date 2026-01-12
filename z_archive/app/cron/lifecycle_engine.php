<?php declare(strict_types=1);

/**
 * lifecycle_engine.php
 *
 * Cron entrypoint for Plainfully lifecycle emails.
 *
 * Run every minute:
 *   * * * * * php /path/to/app/cron/lifecycle_engine.php >> /var/log/plainfully/lifecycle_engine.log 2>&1
 *
 * Expectations:
 * - Your bootstrap should provide:
 *   - a PDO instance: $pdo
 * - Composer autoload OR your own autoloader should be available.
 */

use App\Features\Lifecycle\LifecycleEngine;

require_once __DIR__ . '/../bootstrap.php';

try {
    $engine = new LifecycleEngine($pdo);

    // Process up to N users per run to avoid spikes.
    $engine->run(50);

    echo '[' . date('c') . '] lifecycle_engine ok' . PHP_EOL;
} catch (Throwable $e) {
    // Hard failure: log and let cron retry next minute.
    error_log('[lifecycle_engine] fatal: ' . $e->getMessage());
    echo '[' . date('c') . '] lifecycle_engine fatal (see error_log)' . PHP_EOL;
}

