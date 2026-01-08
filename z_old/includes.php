<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully Central Include List
 * ============================================================
 * File: bootstrap/includes.php
 * Purpose:
 *   Single place to load config + shared helpers/modules/controllers.
 *
 * Rules:
 *   - Only "function holders" / definitions should live here.
 *   - Avoid side effects (no routing, no redirects, no output).
 * ============================================================
 */

// Config (also expose legacy $config pattern via $GLOBALS)
$GLOBALS['config'] = require dirname(__DIR__) . '/config/app.php';

// ---------------------------------------------------------
// Auth
// ---------------------------------------------------------
require_once dirname(__DIR__) . '/app/auth/login.php';

// ---------------------------------------------------------
// Views
// ---------------------------------------------------------
require_once dirname(__DIR__) . '/app/views/render.php';

// ---------------------------------------------------------
// Support (helpers / middleware / utilities)
// ---------------------------------------------------------
require_once dirname(__DIR__) . '/app/support/helpers.php';
require_once dirname(__DIR__) . '/app/support/db.php';
require_once dirname(__DIR__) . '/app/support/mailer.php';
require_once dirname(__DIR__) . '/app/support/rate_limiter.php';
require_once dirname(__DIR__) . '/app/support/session_hardening.php';
require_once dirname(__DIR__) . '/app/support/auth_middleware.php';
require_once dirname(__DIR__) . '/app/support/request.php';
require_once dirname(__DIR__) . '/app/support/csrf.php';
require_once dirname(__DIR__) . '/app/support/auth_log.php';
require_once dirname(__DIR__) . '/app/support/debug_guard.php';
require_once dirname(__DIR__) . '/app/support/debug_consultations.php';
require_once dirname(__DIR__) . '/app/support/debug_shell.php';
require_once dirname(__DIR__) . '/app/support/email_templates.php';
require_once dirname(__DIR__) . '/app/support/imap_attachments.php';
require_once dirname(__DIR__) . '/app/support/trace.php';

// require_once dirname(__DIR__) . '/app/support/turnstile.php';

// ---------------------------------------------------------
// Controllers (function holders)
// ---------------------------------------------------------
require_once dirname(__DIR__) . '/app/controllers/welcome_controller.php';
require_once dirname(__DIR__) . '/app/controllers/health_controller.php';
require_once dirname(__DIR__) . '/app/controllers/logout_controller.php';
require_once dirname(__DIR__) . '/app/controllers/clarifications_controller.php';
require_once dirname(__DIR__) . '/app/controllers/dashboard.php';
require_once dirname(__DIR__) . '/app/controllers/email_hooks_controller.php';
require_once dirname(__DIR__) . '/app/controllers/checks_debug_controller.php';
require_once dirname(__DIR__) . '/app/controllers/admin_debug_controller.php';

// ---------------------------------------------------------
// Features (function holders)
// ---------------------------------------------------------
require_once dirname(__DIR__) . '/app/features/checks/ai_mode.php';
