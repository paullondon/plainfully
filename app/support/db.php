<?php declare(strict_types=1);

/**
 * db.php
 *
 * Simple PDO helper. Always returns the same connection instance.
 * Wraps connection errors in a generic message so we don't leak secrets.
 */

function pf_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/../../config/app.php';
    $dbCfg  = $config['db'];

    try {
        $pdo = new PDO(
            $dbCfg['dsn'],
            $dbCfg['user'],
            $dbCfg['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (Throwable $e) {
        // In production you’d log $e->getMessage() to a file / Sentry, not echo it
        http_response_code(500);
        echo 'Database connection failed. Please try again later.';
        exit;
    }

    return $pdo;
}
