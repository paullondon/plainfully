<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Queue Worker (Cron-friendly, single file)
 * ============================================================
 * File: httpdocs/tools/queue_worker_cron.php
 *
 * Purpose:
 *  - Run by cron/scheduled task every minute
 *  - Processes inbound items within a time budget
 *  - Moves: pf_inbound_queue(new) -> pf_outbound_queue(new), then marks inbound done
 *
 * Safety:
 *  - DB transaction + SELECT ... FOR UPDATE to claim work
 *  - Prepared statements only
 *  - Never logs secrets (payload_json not logged)
 *
 * Env (optional):
 *  - PF_CRON_MAX_SECONDS=50    (time budget per run)
 *  - PF_CRON_MAX_ITEMS=50      (hard cap per run)
 *  - PF_CRON_SLEEP_MS=150      (short pause when queue is empty)
 *  - PF_WORKER_DEBUG=0|1       (extra debug logs for THIS worker only)
 */

require_once __DIR__ . '/../app/bootstrap.php';

final class PfCronWorker
{
    private int $maxSeconds;
    private int $maxItems;
    private int $sleepMs;
    private bool $debug;

    public function __construct()
    {
        $this->maxSeconds = $this->clampInt((int)(pf_env('PF_CRON_MAX_SECONDS', '50') ?? '50'), 5, 55);
        $this->maxItems   = $this->clampInt((int)(pf_env('PF_CRON_MAX_ITEMS', '50') ?? '50'), 1, 500);
        $this->sleepMs    = $this->clampInt((int)(pf_env('PF_CRON_SLEEP_MS', '150') ?? '150'), 0, 2000);

        // Dedicated debug toggle for this worker only
        $this->debug = ((string)pf_env('PF_WORKER_DEBUG', '0') === '1');
    }

    public function run(): int
    {
        $start = microtime(true);
        $processed = 0;

        pf_log('info', 'Cron worker start', [
            'max_seconds' => $this->maxSeconds,
            'max_items'   => $this->maxItems,
        ]);

        $this->debugSnapshot('start');

        while (true) {
            if ($processed >= $this->maxItems) { break; }
            if ((microtime(true) - $start) >= $this->maxSeconds) { break; }

            $did = $this->processOne();

            if ($did) {
                $processed++;
                continue;
            }

            // No work available: short nap then try again until time budget ends
            if ($this->sleepMs > 0) {
                usleep($this->sleepMs * 1000);
            } else {
                break;
            }
        }

        $this->debugSnapshot('end');

        pf_log('info', 'Cron worker end', ['processed' => $processed]);

        // Keep cron stdout minimal (Plesk scheduled task output)
        echo "Cron worker complete. processed={$processed}\n";

        return $processed;
    }

    /**
     * Process a single inbound queue item (FIFO).
     * Returns true if something was processed, false if no work was available.
     *
     * Output:
     *  - Writes exactly ONE outbound job to pf_outbound_queue
     *  - Outbound channel chosen based on inbound channel/payload
     */
    private function processOne(): bool
    {
        $pdo = pf_pdo();

        // Extra visibility: prove eligibility from *this* connection
        if ($this->debug) {
            $eligibleCount = (int)$pdo->query("
                SELECT COUNT(*)
                FROM pf_inbound_queue
                WHERE status='new' AND available_at <= NOW()
            ")->fetchColumn();

            $nowDb = (string)$pdo->query("SELECT NOW()")->fetchColumn();

            pf_log('debug', 'Worker eligibility check', [
                'eligible_count' => $eligibleCount,
                'now_db' => $nowDb,
            ]);
        }

        $pdo->beginTransaction();

        try {
            $sel = $pdo->prepare("
                SELECT id, trace_id, channel, payload_json, attempts
                FROM pf_inbound_queue
                WHERE status='new' AND available_at <= NOW()
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $sel->execute();
            $row = $sel->fetch();

            if ($this->debug) {
                pf_log('debug', 'Worker SELECT result', [
                    'row_is_array' => is_array($row),
                    'row_id'       => is_array($row) ? (int)$row['id'] : null,
                    'row_trace_id' => is_array($row) ? (string)$row['trace_id'] : null,
                    'row_channel'  => is_array($row) ? (string)$row['channel'] : null,
                ]);
            }

            if (!is_array($row)) {
                $pdo->commit();
                return false;
            }

            $inId     = (int)$row['id'];
            $traceId  = (string)$row['trace_id'];
            $channel  = (string)$row['channel'];
            $payload  = (string)$row['payload_json'];
            $attempts = (int)$row['attempts'];

            // Mark as processing while locked
            $upd = $pdo->prepare("
                UPDATE pf_inbound_queue
                SET status='processing', attempts = attempts + 1
                WHERE id = :id
            ");
            $upd->execute([':id' => $inId]);

            // Decode inbound payload
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) { $decoded = []; }

            // Run processor (OCR stub + sanitise)
            $proc = new \App\Pipelines\Process\Processor();
            $procOut = $proc->run($decoded, $traceId, $channel);

            $decoded = is_array($procOut['updated_payload'] ?? null) ? $procOut['updated_payload'] : $decoded;
            $text    = (string)($procOut['text_preview'] ?? '');

            $result  = is_array($procOut['result'] ?? null) ? $procOut['result'] : [
                'status' => 'processed-placeholder',
                'message' => 'Processed (fallback).',
                'processed_at' => gmdate('c'),
            ];

            // Decide delivery route
            $outChannel = 'web';
            $toRaw = null;

            if ($channel === 'email-clarify' || str_starts_with($channel, 'email-')) {
                $outChannel = 'email';
                if (isset($decoded['email']['from'])) {
                    $toRaw = (string)$decoded['email']['from'];
                }
            }

            // Result link
            $resultUrl = '/r?trace_id=' . rawurlencode($traceId);

            $outPayload = [
                'trace_id' => $traceId,

                'deliver' => [
                    'channel'    => $outChannel,
                    'to'         => $toRaw,
                    'subject'    => 'Your Plainfully result',
                    'result_url' => $resultUrl,
                ],

                'input' => [
                    'channel'      => $channel,
                    'text_preview' => mb_substr($text, 0, 280),
                ],

                'evidence' => [
                    'attachments'    => $decoded['attachments'] ?? [],
                    'ocr_text_parts' => $decoded['ocr_text_parts'] ?? [],
                ],

                'result' => $result,
            ];

            // Write outbound row
            $ins = $pdo->prepare("
                INSERT INTO pf_outbound_queue (trace_id, channel, payload_json, status, attempts, available_at, created_at)
                VALUES (:trace_id, :channel, :payload_json, 'new', 0, NOW(), NOW())
            ");
            $ins->execute([
                ':trace_id'     => $traceId,
                ':channel'      => $outChannel,
                ':payload_json' => json_encode($outPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            // Mark inbound done
            $done = $pdo->prepare("UPDATE pf_inbound_queue SET status='done' WHERE id=:id");
            $done->execute([':id' => $inId]);

            $pdo->commit();

            pf_log('info', 'Cron worker processed', [
                'in_id'       => $inId,
                'trace_id'    => $traceId,
                'in_channel'  => $channel,
                'out_channel' => $outChannel,
                'attempts'    => $attempts + 1,
            ]);

            if ($this->debug) {
                pf_log('debug', 'Worker processed details', [
                    'in_id' => $inId,
                    'trace_id' => $traceId,
                    'out_channel' => $outChannel,
                    'attachments_count' => is_array($decoded['attachments'] ?? null) ? count($decoded['attachments']) : 0,
                    'ocr_parts_count' => is_array($decoded['ocr_text_parts'] ?? null) ? count($decoded['ocr_text_parts']) : 0,
                ]);
            }

            return true;

        } catch (Throwable $e) {
            $pdo->rollBack();

            $ref = bin2hex(random_bytes(6));
            pf_log('error', 'Cron worker failed', [
                'ref' => $ref,
                'err' => $e->getMessage(),
            ]);

            if ($this->debug) {
                pf_log('debug', 'Cron worker exception detail', [
                    'ref' => $ref,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }

            // Best-effort: unstick a processing row (avoid leaving it stuck forever)
            try {
                $pdo2 = pf_pdo();
                $pdo2->prepare("
                    UPDATE pf_inbound_queue
                    SET status='new', available_at = DATE_ADD(NOW(), INTERVAL 10 SECOND)
                    WHERE status='processing'
                    ORDER BY id DESC
                    LIMIT 1
                ")->execute();
            } catch (Throwable $ignored) {
                // ignore
            }

            return false;
        }
    }

    /**
     * Safe DB snapshot for debugging "processed=0".
     * Logs only when PF_WORKER_DEBUG=1.
     */
    private function debugSnapshot(string $phase): void
    {
        if (!$this->debug) { return; }

        try {
            $pdo = pf_pdo();

            $counts = $pdo->query("
                SELECT status, COUNT(*) c
                FROM pf_inbound_queue
                GROUP BY status
                ORDER BY status
            ")->fetchAll();

            $nextAny = $pdo->query("
                SELECT id, trace_id, status, attempts, available_at, created_at
                FROM pf_inbound_queue
                ORDER BY available_at ASC, id ASC
                LIMIT 1
            ")->fetch();

            $nextEligible = $pdo->query("
                SELECT id, trace_id, status, attempts, available_at, created_at
                FROM pf_inbound_queue
                WHERE status='new' AND available_at <= NOW()
                ORDER BY id ASC
                LIMIT 1
            ")->fetch();

            $nowDb = (string)$pdo->query("SELECT NOW()")->fetchColumn();

            pf_log('debug', 'Worker snapshot', [
                'phase' => $phase,
                'now_db' => $nowDb,
                'inbound_counts' => $counts,
                'inbound_next_any' => $nextAny ?: null,
                'inbound_next_eligible' => $nextEligible ?: null,
            ]);
        } catch (Throwable $e) {
            pf_log('debug', 'Worker snapshot failed', [
                'phase' => $phase,
                'err' => $e->getMessage(),
            ]);
        }
    }

    private function clampInt(int $v, int $min, int $max): int
    {
        if ($v < $min) return $min;
        if ($v > $max) return $max;
        return $v;
    }
}

try {
    (new PfCronWorker())->run();
} catch (Throwable $e) {
    pf_log('error', 'Cron worker crashed', ['err' => $e->getMessage()]);
    fwrite(STDERR, "Cron worker crashed: " . $e->getMessage() . "\n");
    exit(1);
}
