<?php declare(strict_types=1);

/**
 * Plainfully – front controller
 *
 * All web requests come through here and are bootstrapped
 * by /bootstrap/app.php, which loads config, helpers,
 * controllers and routes/web.php.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require dirname(__DIR__) . '/bootstrap/app.php';
