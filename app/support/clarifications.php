<?php declare(strict_types=1);

use PDO;
use PDOException;

/**
 * Get a PDO connection for Plainfully.
 * Adjust DSN / env variable names to match how you already store them.
 */
function plainfully_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn      = $_ENV['plainfully_db_dsn']      ?? 'mysql:host=localhost;dbname=plainfully;charset=utf8mb4';
    $user     = $_ENV['plainfully_db_user']     ?? 'plainfully';
    $password = $_ENV['plainfully_db_password'] ?? '';

    try {
        $pdo = new PDO(
            $dsn,
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        // Fail hard but clear
        http_response_code(500);
        echo 'Database connection failed.';
        error_log('[Plainfully] DB connection failed: ' . $e->getMessage());
        exit;
    }

    return $pdo;
}

/**
 * Simple CSRF helpers (local to Plainfully; not using any external libs).
 */
function plainfully_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['plainfully_csrf'])) {
        $_SESSION['plainfully_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['plainfully_csrf'];
}

function plainfully_verify_csrf_token(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $stored = $_SESSION['plainfully_csrf'] ?? '';

    if ($stored === '' || $token === null) {
        return false;
    }

    return hash_equals($stored, $token);
}

/**
 * Encryption helper for consultation text.
 * Uses libsodium secretbox (symmetric encryption).
 *
 * Set an env var PLAINFULLY_SECRET_KEY as a 32-byte base64 string.
 */
function plainfully_encrypt(string $plaintext): string
{
    if ($plaintext === '') {
        return '';
    }

    if (!function_exists('sodium_crypto_secretbox')) {
        // Fallback: DO NOT use in production without sodium.
        return $plaintext;
    }

    $keyBase64 = $_ENV['plainfully_secret_key'] ?? '';
    $key       = $keyBase64 !== '' ? base64_decode($keyBase64, true) : null;

    if ($key === false || $key === null || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('Invalid Plainfully secret key configuration.');
    }

    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

    // Store nonce + cipher together (base64-encoded string)
    return base64_encode($nonce . $cipher);
}
