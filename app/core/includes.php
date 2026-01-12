<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully Central Include List
 * ============================================================
 * File: app/core/includes.php
 * Purpose:
 *   Single place to load shared helpers/modules/controllers.
 *
 * Rules:
 *   - Only "function holders" / definitions should live here.
 *   - Avoid side effects (no routing, no redirects, no output).
 *   - Idempotent: safe to include multiple times.
 * ============================================================
 */

$rootDir = dirname(__DIR__, 2);

// ---------------------------------------------------------
// Views
// ---------------------------------------------------------
// Views are loaded by controllers/shell renderers as needed.

// ---------------------------------------------------------
// Core utilities
// ---------------------------------------------------------
require_once $rootDir . '/app/core/db.php';
require_once $rootDir . '/app/core/trace.php';
require_once $rootDir . '/app/core/request.php';
require_once $rootDir . '/app/core/csrf.php';
require_once $rootDir . '/app/core/rate_limiter.php';
require_once $rootDir . '/app/core/auth_log.php';
require_once $rootDir . '/app/core/auth_middleware.php';
require_once $rootDir . '/app/core/session_security.php';
require_once $rootDir . '/app/core/helpers.php';
require_once $rootDir . '/app/core/mailer.php';
require_once $rootDir . '/app/core/email_templates.php';
// IMAP attachment parser (optional; only needed by the IMAP bridge)
$imapAttachmentsCandidates = [
    $rootDir . '/app/core/imap_attachments.php',
    $rootDir . '/app/support/imap_attachments.php',
];
foreach ($imapAttachmentsCandidates as $p) {
    if (is_readable($p)) {
        require_once $p;
        break;
    }
}
// ---------------------------------------------------------
// Auth (handlers)
// ---------------------------------------------------------
require_once $rootDir . '/app/auth/session_helpers.php';
require_once $rootDir . '/app/auth/magic_link.php';
require_once $rootDir . '/app/auth/login.php';

// ---------------------------------------------------------
// Feature: Checks (no composer)
// ---------------------------------------------------------
require_once $rootDir . '/app/features/checks/ai_mode.php';
require_once $rootDir . '/app/features/checks/ai_client.php';
require_once $rootDir . '/app/features/checks/dummy_ai_client.php';
require_once $rootDir . '/app/features/checks/ai_client_factory.php';
require_once $rootDir . '/app/features/checks/check_input.php';
require_once $rootDir . '/app/features/checks/check_result.php';
require_once $rootDir . '/app/features/checks/check_engine.php';

// ---------------------------------------------------------
// Controllers
// ---------------------------------------------------------
require_once $rootDir . '/app/controllers/welcome_controller.php';
require_once $rootDir . '/app/controllers/health_controller.php';
require_once $rootDir . '/app/controllers/logout_controller.php';
require_once $rootDir . '/app/controllers/dashboard.php';
require_once $rootDir . '/app/controllers/clarifications_controller.php';
require_once $rootDir . '/app/controllers/result_access_controller.php';
require_once $rootDir . '/app/controllers/admin_debug_controller.php';
require_once $rootDir . '/app/controllers/trace_controller.php';

// ---------------------------------------------------------
// Pipelines / Hooks
// ---------------------------------------------------------
require_once $rootDir . '/app/pipelines/hooks/email_hooks_controller.php';
