<?php declare(strict_types=1);

/**
 * Compatibility shim
 *
 * Older scripts referenced app/support/trace.php.
 * The canonical implementation now lives in app/core/trace.php.
 */

require_once dirname(__DIR__) . '/core/trace.php';
