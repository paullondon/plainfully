<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — App Bootstrap (safe loader)
 * ============================================================
 * Why:
 *  - Prevent whole-site 500s due to missing moved files.
 *  - Load .env early so cron + web behave the same.
 */

define('PF_ROOT', dirname(__DIR__));

/**
 * Safe require helper (no lone functions elsewhere; bootstrap is allowed).
 * - $required=true  => hard stop with readable message if missing
 * - $required=false => skip if missing (used for optional vendors)
 */
$pf_require = static function (string $path, bool $required = true): void {
    if (is_file($path)) {
        require_once $path;
        return;
    }

    error_log('[plainfully] bootstrap missing file: ' . $path);

    if ($required) {
        http_response_code(500);
        // Keep it minimal; your 404/500 theming comes later in routing.
        echo "Server Error (bootstrap missing dependency).";
        exit;
    }
};

// ------------------------------------------------------------
// 1) ENV support first (so everything else can read env)
// ------------------------------------------------------------
$pf_require(PF_ROOT . '/app/support/env.php', true);
pf_load_env_file(PF_ROOT . '/.env');

// ------------------------------------------------------------
// 2) Core support
// ------------------------------------------------------------
$pf_require(PF_ROOT . '/app/support/helpers.php', true);
$pf_require(PF_ROOT . '/app/support/security.php', true);
$pf_require(PF_ROOT . '/app/support/db.php', true);

// ------------------------------------------------------------
// 3) Pipeline pillars (shared infrastructure)
// NOTE: You moved pillars under pipelines. These must exist.
// ------------------------------------------------------------
$pf_require(PF_ROOT . '/app/pipelines/pillars/storage/attachment_store.php', true);
$pf_require(PF_ROOT . '/app/pipelines/pillars/storage/local_attachment_store.php', true);
$pf_require(PF_ROOT . '/app/pipelines/pillars/storage/r2_attachment_store.php', true);

// ------------------------------------------------------------
// 4) Optional vendors (do NOT take site down if missing)
// ------------------------------------------------------------
$pf_require(PF_ROOT . '/app/vendor/phpmailer/PHPMailer.php', false);
$pf_require(PF_ROOT . '/app/vendor/phpmailer/SMTP.php', false);
$pf_require(PF_ROOT . '/app/vendor/phpmailer/Exception.php', false);
