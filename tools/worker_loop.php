<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Worker Loop (CLI)
 * ============================================================
 * Purpose:
 *  - Claims ONE item from pf_inbound_queue safely
 *  - Creates a placeholder “processed” result
 *  - Inserts into pf_outbound_queue
 *  - Marks inbound as done
 *
 * Run:
 *   php tools/worker_loop.php
 *
 * Env:
 *   PF_WORKER_SLEEP=2         (seconds between loops)
 *   PF_WORKER_BATCH=1         (items per cycle)
 */

require_once __DIR__ . '/../app/bootstrap.php';

$sleepSec = (int)(pf_env('PF_WORKER_SLEEP', '2') ?? '2');
if ($sleepSec < 1) { $sleepSec = 1; }

$batch = (int)(pf_env('PF_WORKER_BATCH', '1') ?? '1');
if ($batch < 1) { $batch = 1; }
if ($batch > 10) { $batch = 10; } // safety cap

echo "Plainfully worker starting… sleep={$sleepSec}s batch={$batch}\n";
pf_log('info', 'Worker started', ['sleep' => $sleepSec, 'batch' => $batch]);

while (true) {
    $worked = 0;

    for ($i = 0; $i < $batch; $i++) {
        $didOne = pf_worker_process_one();
        if (!$didOne) {
            break;
        }
        $worked++;
    }

    if ($worked === 0) {
        echo ".";
    } else {
        echo " processed={$worked}\n";
    }

    sleep($sleepSec);
}

/**
 * ============================================================
 * Worker: process one inbound item
 * ============================================================
 * Returns:
 *  - true  if an item was processed
 *  - false if no work was available
 */
function pf_worker_process_one(): bool
{
    $pdo = pf_pdo();

    // --- Claim one item safely ---
    // We use an atomic UPDATE to flip one row to 'processing'.
    // Then we SELECT that same row to process it.
    $pdo->beginTransaction();

    try {
        $claim = $pdo->prepare("
            UPDATE pf_inbound_queue
            SET status='processing', attempts = attempts + 1
            WHERE id = (
                SELECT id FROM (
                    SELECT id
                    FROM pf_inbound_queue
                    WHERE status='new' AND available_at <= NOW()
                    ORDER BY id ASC
                    LIMIT 1
                ) x
            )
        ");
        $claim->execute();

        if ($claim->rowCount() !== 1) {
            $pdo->commit();
            return false; // no work
        }

        $rowStmt = $pdo->query("
            SELECT id, trace_id, channel, payload_json, attempts
            FROM pf_inbound_queue
            WHERE status='processing'
            ORDER BY id DESC
            LIMIT 1
        ");
        $row = $rowStmt->fetch();

        if (!is_array($row)) {
            // Extremely unlikely; fail safe
            $pdo->rollBack();
            pf_log('error', 'Worker claim mismatch', []);
            return false;
        }

        $inId    = (int)$row['id'];
        $traceId = (string)$row['trace_id'];
        $channel = (string)$row['channel'];
        $payload = (string)$row['payload_json'];
        $attempts= (int)$row['attempts'];

        // --- Build placeholder processing result ---
        $decoded = json_decode($payload, true);
        $text = is_array($decoded) ? (string)($decoded['text'] ?? '') : '';

        $out = [
            'trace_id' => $traceId,
            'input' => [
                'channel' => $channel,
                'text_preview' => mb_substr($text, 0, 280),
            ],
            'result' => [
                'status' => 'processed-placeholder',
                'message' => 'Worker processed inbound item (AI not wired yet).',
                'processed_at' => gmdate('c'),
            ],
        ];

        // Write outbound (single output pipeline later)
        $outStmt = $pdo->prepare("
            INSERT INTO pf_outbound_queue (trace_id, channel, payload_json, status, attempts, available_at, created_at)
            VALUES (:trace_id, :channel, :payload_json, 'new', 0, NOW(), NOW())
        ");
        $outStmt->execute([
            ':trace_id'     => $traceId,
            ':channel'      => 'web', // placeholder; later: email/web/etc
            ':payload_json' => json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        // Mark inbound done
        $doneStmt = $pdo->prepare("UPDATE pf_inbound_queue SET status='done' WHERE id=:id");
        $doneStmt->execute([':id' => $inId]);

        $pdo->commit();

        pf_log('info', 'Worker processed', [
            'in_id' => $inId,
            'trace_id' => $traceId,
            'attempts' => $attempts,
        ]);

        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();

        // Backoff + dead-letter behaviour (simple MVP)
        $ref = bin2hex(random_bytes(6));
        pf_log('error', 'Worker failed', ['ref' => $ref, 'err' => $e->getMessage()]);

        // Attempt to mark the newest processing row as 'new' again with a delay
        // (Fail-safe: if this update fails, the row stays processing and can be manually reset)
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
            // Intentionally ignored; we already logged the primary failure
        }

        return false;
    }
}
