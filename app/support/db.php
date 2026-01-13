<?php declare(strict_types=1);

use PDO;
use PDOException;

/**
 * db.php
 *
 * PDO factory for MariaDB/MySQL.
 * Expects env:
 *  - DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT (optional)
 *
 * Security:
 *  - Uses utf8mb4
 *  - Throws exceptions
 *  - Emulates prepares OFF
 */

function pf_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = pf_env('DB_HOST', '127.0.0.1');
    $name = pf_env('DB_NAME', 'live_plainfully');
    $user = pf_env('DB_USER', 'root');
    $pass = pf_env('DB_PASS', '');
    $port = pf_env('DB_PORT', '3306');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        pf_log('error', 'DB connection failed', ['err' => $e->getMessage()]);
        // Fail closed
        throw $e;
    }
}
