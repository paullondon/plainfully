<?php declare(strict_types=1);

/**
 * ============================================================
 * Database Connector
 * ============================================================
 * File: app/core/db.php
 * Purpose:
 *   Provide a single shared PDO connection.
 *
 * Design:
 * - Lazy initialisation
 * - One connection per request
 * - Fail-fast here, soft-handled by global exception handler
 * ============================================================
 */

use PDO;
use RuntimeException;

if (!function_exists('pf_db')) {

    /**
     * Return a shared PDO connection.
     *
     * @throws RuntimeException if configuration is missing
     */
    function pf_db(): PDO
    {
        static $pdo = null;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        // Config is loaded during bootstrap and stored globally
        $config = is_array($GLOBALS['config'] ?? null) ? $GLOBALS['config'] : [];

        $db = is_array($config['db'] ?? null) ? $config['db'] : [];

        $dsn  = (string)($db['dsn']  ?? '');
        $user = (string)($db['user'] ?? '');
        $pass = (string)($db['pass'] ?? '');

        if ($dsn === '') {
            throw new RuntimeException('Database DSN is not configured.');
        }

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return $pdo;
    }
}
