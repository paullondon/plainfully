<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Queue Worker (Cron-friendly, single file)
 * ============================================================
 * File: httpdocs/tools/queue_worker_cron.php
 *
 * Purpose:
 *  - Designed to be run by cron/scheduled task every minute
 *  - Processes as many inbound items as it safely can within a time budget
 *  - Moves: pf_inbound_queue(new) -> pf_outbound_queue(new), then marks inbound done
 *
 * Safety:
 *  - Uses DB transaction + SELECT ... FOR UPDATE to claim work
 *  - Prepared statements only
 *  - Never exposes secrets
 *
 * Env (optional):
 *  - PF_CRON_MAX_SECONDS=50    (time budget per run)
 *  - PF_CRON_MAX_ITEMS=50      (hard cap per run)
 *  - PF_CRON_SLEEP_MS=150      (short pause when queue is empty)
 */

require_once __DIR__ . '/../app/bootstrap.php';

final class PfCronWorker
{
    private int $maxSeconds;
    private int $maxItems;
    private int $sleepMs;

    public function __construct()
    {
        $this->maxSeconds = $this->clampInt((int)(pf_env('PF_CRON_MAX_SECONDS', '50') ?? '50'), 5, 55);
        $this->maxItems   = $this->clampInt((int)(pf_env('PF_CRON_MAX_ITEMS', '50') ?? '50'), 1, 500);
        $this->sleepMs    = $this->clampInt((int)(pf_env('PF_CRON_SLEEP_MS', '150') ?? '150'), 0, 2000);
    }

    public function run(): int
    {
        $start = microtime(true);
        $processed = 0;

        pf_log('info', 'Cron worker start', [
            'max_seconds' => $this->maxSeconds,
            'max_items'   => $this->maxItems,
        ]);

        while (true) {
            if ($processed >= $this->maxItems) {
                break;
            }

            $elapsed = microtime(true) - $start;
            if ($elapsed >= $this->maxSeconds) {
                break;
            }

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

        pf_log('info', 'Cron worker end', ['processed' => $processed]);
        echo "Cron worker complete. processed={$processed}\n";
        return $processed;
    }

/**
 * Process a single inbound queue item (FIFO).
 * Returns true if something was processed, false if no work was available.
 *
 * Output:
 *  - Writes exactly ONE outbound job to pf_outbound_queue
 *  - Outbound channel is chosen based on inbound channel/payload
 */
private function processOne(): bool
{
    $pdo = pf_pdo();

    // Claim one row safely
    $pdo->beginTransaction();

    try {
        // Lock one eligible row (oldest first)
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

        if (!is_array($row)) {
            $pdo->commit();
            return false;
        }

        $inId     = (int)$row['id'];
        $traceId  = (string)$row['trace_id'];
        $channel  = (string)$row['channel'];
        $payload  = (string)$row['payload_json'];
        $attempts = (int)$row['attempts'];

        // Mark as processing (still within the same lock/transaction)
        $upd = $pdo->prepare("
            UPDATE pf_inbound_queue
            SET status='processing', attempts = attempts + 1
            WHERE id = :id
        ");
        $upd->execute([':id' => $inId]);

        /**
         * ============================================================
         * Process inbound payload
         * ============================================================
         * For MVP this is a stub that pretends to do OCR + sanitise.
         * The real processor will be wired in next.
         * ===========================================================
         */

        // Decode inbound payload
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) { $decoded = []; }

        // Run processor (OCR stub + sanitise)
        $proc = new \App\Pipelines\Process\Processor();
        $procOut = $proc->run($decoded, $traceId, $channel);

        // Use processed preview + result
        $decoded = $procOut['updated_payload'];
        $text    = (string)($procOut['text_preview'] ?? '');
        $result  = is_array($procOut['result'] ?? null) ? $procOut['result'] : [
            'status' => 'processed-placeholder',
            'message' => 'Processed (fallback).',
            'processed_at' => gmdate('c'),
        ];


        /**
         * ============================================================
         * Decide delivery route (single outbound queue, multiple channels)
         * ============================================================
         * Rule (MVP):
         *  - If inbound is email-* then outbound channel is 'email'
         *  - Otherwise outbound channel is 'web'
         */
        $outChannel = 'web';
        $toRaw = null;

        if ($channel === 'email-clarify' || str_starts_with($channel, 'email-')) {
            $outChannel = 'email';

            // Stored by email ingest as decoded['email']['from'] (often "Name <addr@domain>")
            if (is_array($decoded) && isset($decoded['email']['from'])) {
                $toRaw = (string)$decoded['email']['from'];
            }
        }

        // Magic link (MVP): simple trace link. We'll harden to signed token + expiry next.
        $resultUrl = '/r?trace_id=' . rawurlencode($traceId);

        /**
         * ============================================================
         * Outbound payload (what deliverers consume)
         * ============================================================
         * deliver.to is intentionally "raw" for now; deliverer extracts email safely.
         */
        $outPayload = [
            'trace_id' => $traceId,

            'deliver' => [
                'channel'    => $outChannel,
                'to'         => $toRaw, // may be null for web jobs
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

        // Write outbound row (channel decides which deliverer picks it up)
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

        return true;

    } catch (Throwable $e) {
        $pdo->rollBack();

        $ref = bin2hex(random_bytes(6));
        pf_log('error', 'Cron worker failed', ['ref' => $ref, 'err' => $e->getMessage()]);

        // Best-effort: unstick any row we may have set to processing in this attempt
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

    private function clampInt(int $v, int $min, int $max): int
    {
        if ($v < $min) return $min;
        if ($v > $max) return $max;
        return $v;
    }
}

try {
    $worker = new PfCronWorker();
    $worker->run();
} catch (Throwable $e) {
    pf_log('error', 'Cron worker crashed', ['err' => $e->getMessage()]);
    fwrite(STDERR, "Cron worker crashed: " . $e->getMessage() . "\n");
    exit(1);
}
