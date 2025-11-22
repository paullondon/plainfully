<?php declare(strict_types=1);

/**
 * Session Hardening for Plainfully
 *
 * Provides:
 *  - Device/browser fingerprint binding
 *  - IP drift protection
 *  - Session inactivity + absolute timeout
 */

// How long a session can sit idle before forcing re-login
const PF_SESSION_INACTIVITY_LIMIT = 3600; // 1 hour

// Max total life of a session regardless of activity
const PF_SESSION_MAX_LIFETIME = 86400; // 24 hours

/**
 * Generate the user's fingerprint and return a short stable hash.
 */
function pf_generate_fingerprint(): string
{
    $ua  = $_SERVER['HTTP_USER_AGENT']     ?? '';
    $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    $salt = 'PF_STATIC_SALT_CHANGE_ME'; // you can swap this string for a secret env value

    // Normalisation to avoid false positives
    $data = strtolower(trim($ua . '|' . $lang));

    return hash('sha256', $data . $salt);
}

/**
 * Check session hardening constraints.
 * If any fail, the user is logged out and must re-authenticate.
 */
function pf_verify_session_security(): void
{
    // If user is not logged in, do nothing
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    // ---------------------------------------------------------
    // Fingerprint validation
    // ---------------------------------------------------------
    $currentFp = pf_generate_fingerprint();
    $storedFp  = $_SESSION['pf_fingerprint'] ?? null;

    if ($storedFp === null) {
        // First time storing the fingerprint
        $_SESSION['pf_fingerprint'] = $currentFp;
    } elseif (!hash_equals($storedFp, $currentFp)) {
        // Fingerprint mismatch → potential stolen cookie
        pf_force_logout();
        return;
    }

    // ---------------------------------------------------------
    // IP drift protection
    // Use /24 mask to allow home WiFi/mobile networks changing IP slightly
    // ---------------------------------------------------------
    $currentIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $storedIp  = $_SESSION['pf_ip_prefix'] ?? null;

    $prefix = implode('.', array_slice(explode('.', $currentIp), 0, 3)); // e.g. 192.168.1

    if ($storedIp === null) {
        $_SESSION['pf_ip_prefix'] = $prefix;
    } elseif ($storedIp !== $prefix) {
        pf_force_logout();
        return;
    }

    // ---------------------------------------------------------
    // Inactivity timeout
    // ---------------------------------------------------------
    $now = time();
    $last = $_SESSION['pf_last_active'] ?? null;

    if ($last !== null && ($now - $last) > PF_SESSION_INACTIVITY_LIMIT) {
        pf_force_logout();
        return;
    }

    $_SESSION['pf_last_active'] = $now;

    // ---------------------------------------------------------
    // Absolute max lifetime
    // ---------------------------------------------------------
    $createdAt = $_SESSION['pf_created_at'] ?? null;
    if ($createdAt === null) {
        $_SESSION['pf_created_at'] = $now;
    } elseif (($now - $createdAt) > PF_SESSION_MAX_LIFETIME) {
        pf_force_logout();
        return;
    }
}

/**
 * Force logout with sanitised session kill.
 */
function pf_force_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
    header('Location: /login', true, 302);
    exit;
}
