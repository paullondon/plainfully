<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — App Bootstrap (env-safe)
 * ============================================================
 * Guarantees:
 *  - PF_ROOT defined
 *  - .env loaded for BOTH web + cron
 *  - No fatal if env helper names change
 */

define('PF_ROOT', dirname(__DIR__));

/**
 * Safe require helper
 */
$pf_require = static function (string $path, bool $required = true): void {
    if (is_file($path)) {
        require_once $path;
        return;
    }

    error_log('[plainfully] bootstrap missing file: ' . $path);

    if ($required) {
        http_response_code(500);
        echo 'Server Error (bootstrap dependency missing).';
        exit;
    }
};

/**
 * Minimal .env loader (used if env.php doesn’t expose one)
 */
$pf_minimal_env_loader = static function (string $path): void {
    if (!is_file($path)) return;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;

        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);

        if ($k === '') continue;

        // Strip quotes
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
            (str_starts_with($v, "'") && str_ends_with($v, "'"))
        ) {
            $v = substr($v, 1, -1);
        }

        $_ENV[$k] = $v;
        putenv($k . '=' . $v);
    }
};

// ------------------------------------------------------------
// 1) Load env support
// ------------------------------------------------------------
$pf_require(PF_ROOT . '/app/support/env.php', true);

// Try known loader names, else fallback
if (function_exists('pf_load_env_file')) {
    pf_load_env_file(PF_ROOT . '/.env');
} elseif (function_exists('pf_env_load')) {
    pf_env_load(PF_ROOT . '/.env');
} else {
    // Guaranteed fallback
    $pf_minimal_env_loader(PF_ROOT . '/.env');
}

// ------------------------------------------------------------
// 2) Core support
// ------------------------------------------------------------
$pf_require(PF_ROOT . '/app/support/helpers.php', true);
$pf_require(PF_ROOT . '/app/support/security.php', true);
$pf_require(PF_ROOT . '/app/support/db.php', true);

// ------------------------------------------------------------
// 3) Pipeline pillars (storage)
// ------------------------------------------------------------
$pf_require(PF_ROOT . '/app/pipelines/pillars/storage/attachment_store.php', true);
$pf_require(PF_ROOT . '/app/pipelines/pillars/storage/local_attachment_store.php', true);
$pf_require(PF_ROOT . '/app/pipelines/pillars/storage/r2_attachment_store.php', true);

// ------------------------------------------------------------
// 4) Optional vendors
// ------------------------------------------------------------
$pf_require(PF_ROOT . '/app/vendor/phpmailer/PHPMailer.php', false);
$pf_require(PF_ROOT . '/app/vendor/phpmailer/SMTP.php', false);
$pf_require(PF_ROOT . '/app/vendor/phpmailer/Exception.php', false);
