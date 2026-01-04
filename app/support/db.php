<?php declare(strict_types=1);

if (!function_exists('pf_db')) {
    /**
     * Return a PDO connection (or throw on failure).
     */
    function pf_db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) { return $pdo; }

        // Load config the same way your project already does (example)
        $ROOT = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
        $configPath = $ROOT . '/config/app.php';

        $config = $GLOBALS['config'] ?? null;
        if ($config === null && is_readable($configPath)) {
            $config = require $configPath;
            $GLOBALS['config'] = $config;
        }

        $db = is_array($config) ? ($config['db'] ?? []) : [];
        $dsn  = (string)($db['dsn']  ?? '');
        $user = (string)($db['user'] ?? '');
        $pass = (string)($db['pass'] ?? '');

        if ($dsn === '') {
            throw new RuntimeException('DB DSN missing.');
        }

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return $pdo;
    }
}
