<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — App Bootstrap
 * ============================================================
 * File: app/bootstrap.php
 * Purpose:
 *   - Load environment variables
 *   - Load shared helpers (NO lone functions elsewhere)
 *   - Provide core factories (PDO, etc.)
 */

define('PF_ROOT', dirname(__DIR__));

// support files
require_once PF_ROOT . '/app/support/env.php';
require_once PF_ROOT . '/app/support/helpers.php';
require_once PF_ROOT . '/app/support/security.php';
require_once PF_ROOT . '/app/support/db.php';

// Pipeline pillars (shared infrastructure)
require_once PF_ROOT . '/app/pipelines/pillars/storage/attachment_store.php';
require_once PF_ROOT . '/app/pipelines/pillars/storage/local_attachment_store.php';
require_once PF_ROOT . '/app/pipelines/pillars/storage/r2_attachment_store.php';

// Third-party vendors (manual)
require_once PF_ROOT . '/app/vendor/phpmailer/PHPMailer.php';
require_once PF_ROOT . '/app/vendor/phpmailer/SMTP.php';
require_once PF_ROOT . '/app/vendor/phpmailer/Exception.php';

// Load .env (safe: does nothing if missing)
$envPath = PF_ROOT . '/.env';
pf_load_env_file($envPath);
