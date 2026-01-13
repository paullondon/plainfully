<?php declare(strict_types=1);

/**
 * ============================================================
 * Tool: worker_loop.php (CLI)
 * ============================================================
 * Purpose:
 *  - Pull from pf_inbound_queue
 *  - Process (AI later)
 *  - Write to pf_outbound_queue
 *
 * Usage:
 *   php tools/worker_loop.php
 *
 * Notes:
 *  - Placeholder. We'll implement after schema is in.
 */

require_once __DIR__ . '/../app/bootstrap.php';

try {
    pf_log('info', 'Worker loop starting (TODO)');
    echo "Worker loop placeholder. Next step: implement queue processing.\n";
} catch (Throwable $e) {
    pf_log('error', 'Worker loop crashed', ['err' => $e->getMessage()]);
    fwrite(STDERR, "Worker loop crashed: " . $e->getMessage() . "\n");
    exit(1);
}
