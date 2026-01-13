<?php declare(strict_types=1);

/**
 * env.php
 *
 * Tiny .env loader (no external deps).
 * - Supports KEY=VALUE
 * - Ignores blank lines and # comments
 * - Does not overwrite existing environment variables
 *
 * Security:
 * - Designed for server-side use only
 * - Do not expose env values in responses or logs
 */

function pf_env_load(string $envPath): void
{
    if (!is_file($envPath) || !is_readable($envPath)) {
        return;
    }

    $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // Split on first '=' only
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));

        if ($key === '') {
            continue;
        }

        // Strip surrounding quotes
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))
        ) {
            $val = substr($val, 1, -1);
        }

        // Do not override existing values
        if (getenv($key) !== false) {
            continue;
        }

        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
        $_SERVER[$key] = $val;
    }
}

/**
 * Fetch an env var with optional default.
 * Never throws (safe for bootstrap).
 */
function pf_env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    if ($v === false) {
        return $default;
    }
    return $v;
}
