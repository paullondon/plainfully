<?php declare(strict_types=1);

if (!function_exists('pf_safe_hash')) {
    /**
     * Safe, short hash for IDs/logging (no secrets exposed).
     */
    function pf_safe_hash(string $value): string
    {
        try {
            return substr(hash('sha256', $value), 0, 12);
        } catch (Throwable $e) {
            return 'hash_error';
        }
    }
}

function pf_trace_run_id(): string
{
    return date('Ymd-His') . '-' . bin2hex(random_bytes(4));
}

function pf_trace(
    string $runId,
    string $component,
    string $step,
    string $level,
    string $message,
    array $context = []
): void {
    $file = getenv('PLAINFULLY_DEBUG_TRACE_FILE') ?: sys_get_temp_dir() . '/plainfully_debug_trace.log';

    $line = json_encode([
        'ts'        => date('c'),
        'run_id'    => $runId,
        'component' => $component,
        'step'      => $step,
        'level'     => $level,
        'message'   => $message,
        'context'   => $context,
    ], JSON_UNESCAPED_SLASHES);

    // Ensure directory exists
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    // Append atomically, fail-open
    @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}
