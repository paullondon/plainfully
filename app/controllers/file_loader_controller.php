<?php declare(strict_types=1);

if (!function_exists('pf_load_env_file')) {
    /**
     * ============================================================
     * pf_load_env_file
     * ============================================================
     * Purpose:
     *   Load key=value pairs from a .env file into getenv()/$_ENV.
     *
     * Key behaviour:
     *   - Uses the provided $path if readable
     *   - If not readable, falls back to httpdocs/.env when called from
     *     a cron context that moved folders (e.g. /app/cron -> ../../.env)
     *
     * Safety:
     *   - Skips invalid lines
     *   - Does not overwrite existing env vars
     *   - Fails closed with a clear log message if file read errors occur
     * ============================================================
     */
    function pf_load_env_file(string $path): void
    {
        try {
            // ----------------------------
            // Resolve the env file path
            // ----------------------------
            $envPath = $path;

            // If caller gave a non-readable path, attempt a safe fallback:
            // When this function lives in httpdocs/app/... (common), ../../.env = httpdocs/.env
            if (!is_file($envPath) || !is_readable($envPath)) {
                $httpdocsRoot = realpath(__DIR__ . '/../../');
                if ($httpdocsRoot !== false) {
                    $fallback = $httpdocsRoot . '/.env';
                    if (is_file($fallback) && is_readable($fallback)) {
                        $envPath = $fallback;
                    }
                }
            }

            // Still not readable? Quietly stop (do not kill the app here).
            if (!is_file($envPath) || !is_readable($envPath)) {
                return;
            }

            // ----------------------------
            // Read file
            // ----------------------------
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                throw new RuntimeException('Failed to read .env lines at: ' . $envPath);
            }

            // ----------------------------
            // Parse lines: KEY=VALUE
            // ----------------------------
            foreach ($lines as $line) {
                $line = trim($line);

                // Skip comments / blanks
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                // Must contain '='
                if (!str_contains($line, '=')) {
                    continue;
                }

                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);

                if ($k === '') {
                    continue;
                }

                // Strip optional wrapping quotes
                if (
                    (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                    (str_starts_with($v, "'") && str_ends_with($v, "'"))
                ) {
                    $v = substr($v, 1, -1);
                }

                // Do not overwrite existing env
                if (getenv($k) === false) {
                    putenv($k . '=' . $v);
                    $_ENV[$k] = $v;
                }
            }
        } catch (Throwable $e) {
            // Fail closed: env issues should be loud and obvious.
            error_log('[env] ' . $e->getMessage());

            // Avoid sending HTTP headers from CLI cron.
            if (PHP_SAPI !== 'cli') {
                http_response_code(500);
            }

            exit('Server configuration error.');
        }
    }
}

if (!function_exists('pf_env')) {
    /**
     * pf_env
     *
     * Read an environment variable safely.
     *
     * @param string $key     ENV key name
     * @param mixed  $default Optional default if not set
     */
    function pf_env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        // Normalise common values
        if ($value === 'true')  return true;
        if ($value === 'false') return false;
        if (is_numeric($value)) return $value + 0;

        return $value;
    }
}

if (!function_exists('pf_env_str')) {
    function pf_env_str(string $key, string $default = '', bool $trim = true): string
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        $value = (string)$value;

        return $trim ? trim($value) : $value;
    }
}

if (!function_exists('pf_env_int')) {
    function pf_env_int(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
    {
        $value = pf_env($key, $default);

        if (!is_numeric($value)) {
            return $default;
        }

        $value = (int)$value;

        if ($min !== null) {
            $value = max($min, $value);
        }
        if ($max !== null) {
            $value = min($max, $value);
        }

        return $value;
    }
}

if (!function_exists('pf_env_bool')) {
    function pf_env_bool(string $key, bool $default = false): bool
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        return in_array(
            strtolower(trim($value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }
}

