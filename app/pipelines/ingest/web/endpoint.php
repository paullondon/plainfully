<?php declare(strict_types=1);

/**
 * ============================================================
 * Pipeline: Ingest (Web)
 * ============================================================
 * Accepts JSON:
 *   { "text": "...", "channel": "web-clarify" }
 *
 * Writes to:
 *   pf_inbound_queue (status=new)
 *
 * Notes:
 * - Turnstile will be added after the smoke test is working.
 * - Uses prepared statements only.
 */

require_once PF_ROOT . '/app/bootstrap.php';

pf_require_post();

try {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        pf_http_error(400, 'Empty body');
    }

    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    $text = trim((string)($data['text'] ?? ''));
    if ($text === '') {
        pf_http_error(400, 'Missing text');
    }

    $channel = trim((string)($data['channel'] ?? 'web-clarify'));
    if ($channel === '') {
        $channel = 'web-clarify';
    }

    // Basic safety cap (prevents abuse / accidental huge payloads)
    if (mb_strlen($text) > 20000) {
        pf_http_error(413, 'Text too large');
    }

    // Trace id: UUID-ish without extra deps
    $traceId = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );

    // Normalized payload package
    $payload = [
        'text' => $text,
        'meta' => [
            'received_at' => gmdate('c'),
            'ip'          => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'ua'          => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ],
    ];

    $pdo = pf_pdo();
    $stmt = $pdo->prepare("
        INSERT INTO pf_inbound_queue (trace_id, channel, payload_json, status, attempts, available_at, created_at)
        VALUES (:trace_id, :channel, :payload_json, 'new', 0, NOW(), NOW())
    ");
    $stmt->execute([
        ':trace_id'     => $traceId,
        ':channel'      => $channel,
        ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    pf_json([
        'ok'       => true,
        'trace_id' => $traceId,
        'queued'   => true,
    ]);
} catch (Throwable $e) {
    $ref = bin2hex(random_bytes(6));
    pf_log('error', 'Web ingest failed', ['ref' => $ref, 'err' => $e->getMessage()]);
    pf_http_error(500, 'Server Error (ref ' . $ref . ')');
}
