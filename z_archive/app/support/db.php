<?php declare(strict_types=1);

/**
 * Compatibility shim
 *
 * Older scripts referenced app/support/db.php.
 * The canonical implementation now lives in app/core/db.php.
 */

require_once dirname(__DIR__) . '/core/db.php';
