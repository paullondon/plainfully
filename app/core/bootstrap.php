<?php declare(strict_types=1);

/**
 * app/core/bootstrap.php
 *
 * Single bootstrap entry for the whole app.
 * For now, this is a shim that loads the existing bootstrap/app.php
 * so we can migrate file-by-file without breaking the site.
 */

$legacyBootstrap = dirname(__DIR__, 2) . '/bootstrap/app.php';

if (!is_file($legacyBootstrap)) {
    // Fail hard with a safe message (no path leakage in production).
    http_response_code(500);
    echo 'Application bootstrap missing.';
    exit;
}

require $legacyBootstrap;