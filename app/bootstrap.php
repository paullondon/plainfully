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

require_once PF_ROOT . '/app/support/env.php';
require_once PF_ROOT . '/app/support/helpers.php';
require_once PF_ROOT . '/app/support/security.php';
require_once PF_ROOT . '/app/support/db.php';

// Load .env (safe: does nothing if missing)
pf_env_load(PF_ROOT . '/.env');
