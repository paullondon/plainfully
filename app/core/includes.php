<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully Central Include List
 * ============================================================
 * File: app/core/includes.php
 * Purpose:
 *   Single place to load config + shared helpers/modules.
 *
 * Rules:
 *   - Only function/class definitions here
 *   - No routing, redirects, output, or execution logic
 * ============================================================
 */

$rootDir = dirname(__DIR__, 2); // httpdocs

// ---------------------------------------------------------
// Config (also expose legacy $config via $GLOBALS)
// ---------------------------------------------------------
$GLOBALS['config'] = require $rootDir . '/config/app.php';

// ---------------------------------------------------------
// Auth
// ---------------------------------------------------------
require_once $rootDir . '/app/auth/login.php';

// ---------------------------------------------------------
// core
// ---------------------------------------------------------
require_once $rootDir . '/app/core/render_shell.php';
require_once $rootDir . '/app/core/db.php';
require_once $rootDir . '/app/core/mailer.php';
require_once $rootDir . '/app/core/email_templates.php';
require_once $rootDir . '/app/core/helpers.php';





// ---------------------------------------------------------
// Core support (helpers / utilities / middleware)
// ---------------------------------------------------------
require_once $rootDir . '/app/support/helpers.php';
require_once $rootDir . '/app/support/rate_limiter.php';
require_once $rootDir . '/app/support/session_hardening.php';
require_once $rootDir . '/app/support/auth_middleware.php';
require_once $rootDir . '/app/support/request.php';
require_once $rootDir . '/app/support/csrf.php';
require_once $rootDir . '/app/support/auth_log.php';
require_once $rootDir . '/app/support/debug_guard.php';
require_once $rootDir . '/app/support/debug_consultations.php';
require_once $rootDir . '/app/support/debug_shell.php';
require_once $rootDir . '/app/support/imap_attachments.php';
require_once $rootDir . '/app/support/trace.php';

// Optional / future
// require_once $rootDir . '/app/support/turnstile.php';

// ---------------------------------------------------------
// Controllers (function holders only)
// ---------------------------------------------------------
require_once $rootDir . '/app/controllers/welcome_controller.php';
require_once $rootDir . '/app/controllers/health_controller.php';
require_once $rootDir . '/app/controllers/logout_controller.php';
require_once $rootDir . '/app/controllers/clarifications_controller.php';
require_once $rootDir . '/app/controllers/dashboard.php';
require_once $rootDir . '/app/controllers/email_hooks_controller.php';
require_once $rootDir . '/app/controllers/checks_debug_controller.php';
require_once $rootDir . '/app/controllers/admin_debug_controller.php';

// ---------------------------------------------------------
// Features (definitions only)
// ---------------------------------------------------------
require_once $rootDir . '/app/features/checks/ai_mode.php';
