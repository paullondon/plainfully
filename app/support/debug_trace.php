<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/support/debug_trace.php
 * Purpose:
 *   Ultra-light filesystem trace logger for DEV/ops debugging.
 *
 * When to use:
 *   - When you need trace-style breadcrumbs WITHOUT requiring DB writes.
 *   - E.g. debugging the email bridge on a server before DB is stable.
 *
 * Controls:
 *   - Enabled only when PLAINFULLY_DEBUG=true (fail-closed)
 *   - Writes to PLAINFULLY_DEBUG_TRACE_FILE (default: /tmp/plainfully_debug_trace.log)
 *
 * Security:
 *   - Avoid writing raw email content into context
 *   - This file is intended for admins only (use admin_debug_controller to view)
 *   - Fail-open: logging must never break the app flow
 * ============================================================
 */

if (!function_exists('pf_debug_enabled')) {
    function pf_debug_enabled(): bool
    {
        $v = getenv('PLAINFULLY_DEBUG');
        if ($v === false || $v === '') { return false; }
        return in_array(strtolower((string)$v), ['1','true','yes','on'], true);
    }
}

if (!function_exists('pf_safe_hash')) {
    /**
     * Safe short hash for IDs/logging (no secrets exposed).
     */
    function pf_safe_hash(string $value): string
    {
        try {
            return substr(hash('sha256', $value), 0, 12);
        } catch (\Throwable $e) {
            return 'hash_error';
        }
    }
}

if (!function_exists('pf_trace_run_id')) {
    /**
     * Generate a per-run id for grouping log lines.
     */
    function pf_trace_run_id(): string
    {
        return date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }
}

if (!function_exists('pf_debug_trace')) {
    /**
     * Append a single JSON line to the debug trace file.
     *
     * IMPORTANT:
     * - Do NOT include raw body/content unless you explicitly accept the risk.
     * - Prefer hashes and lengths.
     */
    function pf_debug_trace(
        string $runId,
        string $component,
        string $step,
        string $level,
        string $message,
        array $context = []
    ): void {
        if (!pf_debug_enabled()) { return; }

        $file = getenv('PLAINFULLY_DEBUG_TRACE_FILE') ?: (sys_get_temp_dir() . '/plainfully_debug_trace.log');

        // Ensure we don't blow up logs with huge arrays
        foreach ($context as $k => $v) {
            if (is_string($v) && strlen($v) > 5000) {
                $context[$k] = substr($v, 0, 5000) . '…';
            }
        }

        $line = json_encode([
            'ts'        => date('c'),
            'run_id'    => $runId,
            'component' => $component,
            'step'      => $step,
            'level'     => $level,
            'message'   => $message,
            'context'   => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($line) || $line === '') { return; }

        // Ensure directory exists
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        // Append atomically, fail-open
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
