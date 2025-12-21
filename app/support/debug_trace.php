<?php declare(strict_types=1);

/**
 * Debug trace logger (DB-backed).
 * Stores NO full email bodies. Only safe meta.
 */

if (!function_exists('pf_trace_run_id')) {
    function pf_trace_run_id(): string
    {
        // UUID v4-ish without extensions
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

if (!function_exists('pf_trace_enabled')) {
    function pf_trace_enabled(): bool
    {
        return (getenv('PLAINFULLY_DEBUG') === 'true' || getenv('PLAINFULLY_DEBUG') === '1');
    }
}

if (!function_exists('pf_trace')) {
    /**
     * @param array<string,mixed> $meta
     */
    function pf_trace(string $runId, string $channel, string $step, string $level, string $message, array $meta = []): void
    {
        if (!pf_trace_enabled()) {
            return;
        }

        // Fail-open: never break production flow
        try {
            $pdo = pf_db();

            // Ensure JSON is safe and not huge
            $metaJson = null;
            if (!empty($meta)) {
                $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($metaJson) && strlen($metaJson) > 8000) {
                    $metaJson = json_encode(['meta_truncated' => true], JSON_UNESCAPED_UNICODE);
                }
            }

            $stmt = $pdo->prepare('
                INSERT INTO debug_traces (run_id, channel, step, level, message, meta_json)
                VALUES (:run_id, :channel, :step, :level, :message, :meta_json)
            ');

            $stmt->execute([
                ':run_id'   => $runId,
                ':channel'  => $channel,
                ':step'     => $step,
                ':level'    => $level,
                ':message'  => mb_substr($message, 0, 255),
                ':meta_json'=> $metaJson,
            ]);
        } catch (Throwable $e) {
            error_log('pf_trace failed (ignored): ' . $e->getMessage());
        }
    }
}

if (!function_exists('pf_safe_hash')) {
    function pf_safe_hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
