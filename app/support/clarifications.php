<?php declare(strict_types=1);

/**
 * Get a PDO connection for Plainfully.
 * Adjust DSN / env variable names to match how you already store them.
 */

function plainfully_pdo(): \PDO
{
    static $pdo = null;

    if ($pdo instanceof \PDO) {
        return $pdo;
    }

    // If the core app has a DB helper, reuse it.
    if (function_exists('pf_db')) {
        $pdo = pf_db();   // <- uses your existing config from app/support/db.php
        return $pdo;
    }

    // Fallback only if pf_db() doesn't exist (shouldn't normally happen)
    $dsn      = getenv('plainfully_db_dsn')      ?: 'mysql:host=localhost;dbname=live_plainfully;charset=utf8mb4';
    $user     = getenv('plainfully_db_user')     ?: 'plainfully';
    $password = getenv('plainfully_db_password') ?: '';

    try {
        $pdo = new \PDO(
            $dsn,
            $user,
            $password,
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (\PDOException $e) {
        // Let the global exception handler show this nicely
        throw new \RuntimeException('Plainfully DB connection failed: ' . $e->getMessage(), 0, $e);
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

    // If libsodium isn't available, log and store plaintext (DEV-safe behaviour).
    if (!function_exists('sodium_crypto_secretbox')) {
        error_log('[Plainfully] WARN: libsodium not available, storing text unencrypted.');
        return $plaintext;
    }

    // env is loaded via putenv(), so use getenv()
    $keyBase64 = getenv('plainfully_secret_key') ?: '';
    $key       = $keyBase64 !== '' ? base64_decode($keyBase64, true) : null;

    if ($key === false || $key === null || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        // Misconfigured key – log and fall back to plaintext instead of throwing.
        error_log('[Plainfully] WARN: invalid plainfully_secret_key, storing text unencrypted.');
        return $plaintext;
    }

    $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

    return base64_encode($nonce . $cipher);
}