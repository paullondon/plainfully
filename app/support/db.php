<?php declare(strict_types=1);

/**
 * db.php
 *
 * Central PDO connection helper for Plainfully.
 */

function pf_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require dirname(__DIR__) . '/config/app.php';

    $dsn  = $config['db']['dsn']  ?? '';
    $user = $config['db']['user'] ?? '';
    $pass = $config['db']['pass'] ?? '';

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        return $pdo;
    } catch (PDOException $e) {
        $env = getenv('APP_ENV') ?: 'local';

        if (in_array(strtolower($env), ['live', 'production'], true)) {
            // In Live: generic message + log
            error_log('Plainfully DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            echo 'Database connection failed. Please try again later.';
        } else {
            // In local/dev: show actual error so we can fix it
            http_response_code(500);
            echo 'Database connection failed: '
               . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
               . '<br><br>DSN: '
               . htmlspecialchars($dsn, ENT_QUOTES, 'UTF-8');
        }
        exit;
    }
}
