<?php declare(strict_types=1);

/**
 * trace.php
 *
 * Global tracing helpers.
 *
 * NOTE:
 * - This file lives in the global namespace (no `namespace ...;`)
 * - Therefore `use PDO;` / `use Throwable;` is unnecessary and triggers warnings.
 * - We use fully-qualified \PDO and \Throwable instead.
 */

if (!function_exists('pf_trace_enabled')) {
    function pf_trace_enabled(): bool
    {
        $v = getenv('PLAINFULLY_TRACE');
        if ($v === false || $v === '') { return false; }
        return in_array(strtolower((string)$v), ['1','true','yes','on'], true);
    }
}

if (!function_exists('pf_trace_deep')) {
    function pf_trace_deep(): bool
    {
        $v = getenv('PLAINFULLY_TRACE_DEEP');
        if ($v === false || $v === '') { return false; }
        return in_array(strtolower((string)$v), ['1','true','yes','on'], true);
    }
}

if (!function_exists('pf_trace_new_id')) {
    function pf_trace_new_id(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}

if (!function_exists('pf_trace_safe_json')) {
    function pf_trace_safe_json($meta): string
    {
        // Normalise meta into an array
        if (is_string($meta)) {
            $meta = ['_meta' => $meta];
        } elseif (!is_array($meta)) {
            $meta = ['_meta' => (string)$meta];
        }

        try {
            $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json) && $json !== '' && json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return '{}';
    }
}

if (!function_exists('pf_trace')) {
    function pf_trace(
        ?\PDO $pdo,
        string $traceId,
        string $level,
        string $stage,
        $event,                 // allow bool/int/etc
        string $message,
        $meta = [],             // allow string/etc
        ?int $queueId = null,
        ?int $checkId = null
    ): void {
        if (!pf_trace_enabled()) { return; }
        if ($traceId === '' || !($pdo instanceof \PDO)) { return; }

        $level   = in_array($level, ['debug','info','warn','error'], true) ? $level : 'info';
        $stage   = substr((string)$stage, 0, 64);
        $event   = substr((string)$event, 0, 64);
        $message = substr((string)$message, 0, 255);

        // Normalise meta into an array early
        if (is_string($meta)) {
            $meta = ['_meta' => $meta];
        } elseif (!is_array($meta)) {
            $meta = ['_meta' => (string)$meta];
        }

        if (!pf_trace_deep()) {
            foreach (['raw_body','body','content','text','prompt','ai_result','extracted_text','normalized_text','truncated_text'] as $k) {
                if (array_key_exists($k, $meta)) { unset($meta[$k]); }
            }
        }

        $metaJson = pf_trace_safe_json($meta);

        try {
            $stmt = $pdo->prepare('
                INSERT INTO trace_events (trace_id, level, stage, event, message, meta_json, queue_id, check_id)
                VALUES (:trace_id, :level, :stage, :event, :message, :meta_json, :queue_id, :check_id)
            ');
            $stmt->execute([
                ':trace_id' => $traceId,
                ':level'    => $level,
                ':stage'    => $stage,
                ':event'    => $event,
                ':message'  => $message,
                ':meta_json'=> $metaJson,
                ':queue_id' => $queueId,
                ':check_id' => $checkId,
            ]);
        } catch (\Throwable $e) {
            error_log('pf_trace insert failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('pf_trace_allowed')) {
    function pf_trace_allowed(): bool
    {
        $key = (string)(getenv('TRACE_VIEW_KEY') ?: '');
        $provided = (string)($_GET['k'] ?? '');
        if ($key !== '' && $provided !== '' && hash_equals($key, $provided)) { return true; }

        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
    }
}
