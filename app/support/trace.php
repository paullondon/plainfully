<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/support/trace.php
 * Purpose:
 *   Database-backed tracing for a single clarification run.
 *
 * Key rules:
 *   - Tracing is OFF unless PLAINFULLY_TRACE is enabled.
 *   - Trace viewing is ADMIN ONLY (no token access).
 *   - Trace viewing is time-limited (default: last 1 hour).
 *   - Fail-open: tracing must never break the main pipeline.
 *
 * Env flags:
 *   - PLAINFULLY_TRACE=true|false
 *   - PLAINFULLY_TRACE_MAX_AGE_SECONDS=3600
 * ============================================================
 */

if (!function_exists('pf_trace_enabled')) {
    function pf_trace_enabled(): bool
    {
        $v = getenv('PLAINFULLY_TRACE');
        if ($v === false || $v === '') { return false; }
        return in_array(strtolower((string)$v), ['1','true','yes','on'], true);
    }
}

if (!function_exists('pf_trace_max_age_seconds')) {
    function pf_trace_max_age_seconds(): int
    {
        $raw = getenv('PLAINFULLY_TRACE_MAX_AGE_SECONDS');
        if ($raw === false || trim((string)$raw) === '') { return 3600; }
        $n = (int)$raw;
        return ($n > 0 && $n <= 86400) ? $n : 3600;
    }
}

if (!function_exists('pf_trace_new_id')) {
    function pf_trace_new_id(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);

        return substr($hex, 0, 8) . '-' .
               substr($hex, 8, 4) . '-' .
               substr($hex, 12, 4) . '-' .
               substr($hex, 16, 4) . '-' .
               substr($hex, 20, 12);
    }
}

if (!function_exists('pf_trace_redact_meta')) {
    function pf_trace_redact_meta($meta): array
    {
        if (is_string($meta)) {
            $meta = ['_meta' => $meta];
        } elseif (!is_array($meta)) {
            $meta = ['_meta' => (string)$meta];
        }

        $redactKey = static function (string $k): bool {
            return (bool)preg_match('/(pass(word)?|token|secret|authorization|cookie|api[_-]?key)/i', $k);
        };

        $walk = static function ($value) use (&$walk, $redactKey) {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    if (is_string($k) && $redactKey($k)) {
                        $out[$k] = '[redacted]';
                        continue;
                    }
                    $out[$k] = $walk($v);
                }
                return $out;
            }

            if (is_object($value)) {
                return method_exists($value, '__toString') ? (string)$value : '[object]';
            }

            return $value;
        };

        return (array)$walk($meta);
    }
}

if (!function_exists('pf_trace_safe_json')) {
    function pf_trace_safe_json($meta): string
    {
        $metaArr = pf_trace_redact_meta($meta);
        try {
            $json = json_encode($metaArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json) && $json !== '' && json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        } catch (Throwable $e) {}
        return '{}';
    }
}

if (!function_exists('pf_trace')) {
    function pf_trace(
        ?PDO $pdo,
        string $traceId,
        string $level,
        string $stage,
        $event,
        string $message,
        $meta = [],
        $queueId = null,
        $checkId = null
    ): void {
        if (!pf_trace_enabled()) { return; }
        if ($traceId === '' || !($pdo instanceof PDO)) { return; }

        $level   = in_array($level, ['debug','info','warn','error'], true) ? $level : 'info';
        $stage   = substr((string)$stage, 0, 64);
        $event   = substr((string)$event, 0, 64);
        $message = substr((string)$message, 0, 255);

        $metaJson = pf_trace_safe_json($meta);

        try {
            if (is_string($queueId) && ctype_digit($queueId)) { $queueId = (int)$queueId; }
            if (!is_int($queueId)) { $queueId = null; }

            if (is_string($checkId) && ctype_digit($checkId)) { $checkId = (int)$checkId; }
            if (!is_int($checkId)) { $checkId = null; }

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
        } catch (Throwable $e) {
            error_log('pf_trace insert failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('pf_trace_allowed')) {
    function pf_trace_allowed(): bool
    {
        return function_exists('pf_is_admin') && pf_is_admin();
    }
}

if (!function_exists('pf_trace_view_cutoff_datetime')) {
    function pf_trace_view_cutoff_datetime(): string
    {
        $seconds = pf_trace_max_age_seconds();
        return date('Y-m-d H:i:s', time() - $seconds);
    }
}
