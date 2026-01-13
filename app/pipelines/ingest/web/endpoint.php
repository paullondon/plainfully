<?php declare(strict_types=1);

/**
 * ============================================================
 * Pipeline: Ingest (Web)
 * ============================================================
 * Purpose:
 *  - Accepts a web POST payload
 *  - Validates (Turnstile later)
 *  - Enqueues into pf_inbound_queue
 *
 * NOTE: Placeholder file. We'll implement after schema is in.
 */

require_once PF_ROOT . '/app/bootstrap.php';

pf_require_post();

pf_json([
  'ok' => false,
  'todo' => 'Implement web ingest -> pf_inbound_queue',
]);
