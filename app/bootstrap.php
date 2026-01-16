<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — App Bootstrap (minimal + safe)
 * ============================================================
 * Loads:
 *  - PF_ROOT
 *  - env.php + .env
 *  - core support files
 *  - pipeline storage pillars
 *  - optional vendors (no hard crash)
 */

define('PF_ROOT', dirname(__DIR__));

/**
 * Safe require:
 * - required=true  => stop with readable message if missing
 * - required=false => skip if missing
 */
$pf_require = static function (string $path, bool $required = true): void {
    if (is_file($path)) {
        require_once $path;
        return;
    }

    error_log('[plainfully] bootstrap missing file: ' . $path);

    if ($required) {
        http_response_code(500);
        echo 'Server Error (bootstrap missing dependency).';
        exit;
    }
};

// 1) Env first
$pf_require(PF_ROOT . '/app/support/env.php', true);
pf_load_env_file(PF_ROOT . '/.env');

// 2) Core support
$pf_require(PF_ROOT . '/app/support/helpers.php', true);
$pf_require(PF_ROOT . '/app/support/security.php', true);
$pf_require(PF_ROOT . '/app/support/db.php', true);

// 3) Pipeline pillars (storage)
$pf_require(PF_ROOT . '/app/pipelines/pillars/storage/attachment_store.php', true);
$pf_require(PF_ROOT . '/app/pipelines/pillars/storage/local_attachment_store.php', true);
$pf_require(PF_ROOT . '/app/pipelines/pillars/storage/r2_attachment_store.php', true);

// 4) Optional vendors (do not crash site if missing)
$pf_require(PF_ROOT . '/app/vendor/phpmailer/PHPMailer.php', false);
$pf_require(PF_ROOT . '/app/vendor/phpmailer/SMTP.php', false);
$pf_require(PF_ROOT . '/app/vendor/phpmailer/Exception.php', false);
