<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — DB Support
 * ============================================================
 * Purpose:
 *  - Provide a single, safe PDO connection helper
 *  - No "use PDO" needed in global namespace (causes warnings)
 */

function pf_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string)pf_env('DB_HOST', '');
    $name = (string)pf_env('DB_NAME', '');
    $user = (string)pf_env('DB_USER', '');
    $pass = (string)pf_env('DB_PASS', '');
    $port = (string)pf_env('DB_PORT', '3306');

    if ($host === '' || $name === '' || $user === '') {
        throw new RuntimeException('Database env vars missing (DB_HOST/DB_NAME/DB_USER).');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Don’t leak DSN/user; log minimal
        pf_log('error', 'DB connect failed', ['err' => $e->getMessage()]);
        throw $e;
    }

    return $pdo;
}
