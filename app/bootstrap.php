<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — App Bootstrap
 * ============================================================
 * Purpose:
 *   - Define PF_ROOT
 *   - Load environment variables FIRST
 *   - Load shared helpers + factories
 *   - Load pipeline pillars
 */

define('PF_ROOT', dirname(__DIR__));

// ------------------------------------------------------------
// 1) Core support (env first so everything else can read it)
// ------------------------------------------------------------
require_once PF_ROOT . '/app/support/env.php';
pf_load_env_file(PF_ROOT . '/.env'); // NOTE: PF_ROOT constant must be defined before this call

// ------------------------------------------------------------
// 2) Remaining support (safe after env loaded)
// ------------------------------------------------------------
require_once PF_ROOT . '/app/support/helpers.php';
require_once PF_ROOT . '/app/support/security.php';
require_once PF_ROOT . '/app/support/db.php';

// ------------------------------------------------------------
// 3) Pipeline pillars (shared infrastructure)
// ------------------------------------------------------------
require_once PF_ROOT . '/app/pipelines/pillars/storage/attachment_store.php';
require_once PF_ROOT . '/app/pipelines/pillars/storage/local_attachment_store.php';
require_once PF_ROOT . '/app/pipelines/pillars/storage/r2_attachment_store.php';

// ------------------------------------------------------------
// 4) Third-party vendors (manual)
// ------------------------------------------------------------
require_once PF_ROOT . '/app/vendor/phpmailer/PHPMailer.php';
require_once PF_ROOT . '/app/vendor/phpmailer/SMTP.php';
require_once PF_ROOT . '/app/vendor/phpmailer/Exception.php';
