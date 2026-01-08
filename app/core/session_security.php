<?php declare(strict_types=1);

/**
 * Session security for Plainfully
 *
 * - Absolute lifetime (SESSION_LIFETIME_DAYS, default 7)
 * - Idle timeout (SESSION_IDLE_HOURS, default 12)
 * - Fingerprint binding (user agent)
 * - Optional IP prefix binding (first 3 octets) in LIVE only
 */

if (!defined('PF_SESSION_ABSOLUTE_LIFETIME')) {
    define(
        'PF_SESSION_ABSOLUTE_LIFETIME',
        60 * 60 * 24 * (int)(getenv('SESSION_LIFETIME_DAYS') ?: 7)
    );
}

if (!defined('PF_SESSION_IDLE_TIMEOUT')) {
    define(
        'PF_SESSION_IDLE_TIMEOUT',
        60 * 60 * (int)(getenv('SESSION_IDLE_HOURS') ?: 12)
    );
}

/**
 * Generate a simple fingerprint based on the user agent.
 */
if (!function_exists('pf_generate_fingerprint')) {
    function pf_generate_fingerprint(): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
        return hash('sha256', $ua);
    }
}

/**
 * Centralised logout with a reason (for non-live debugging).
 */
if (!function_exists('pf_logout_with_reason')) {
    function pf_logout_with_reason(string $reason): never
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            pf_session_start();
        }

        $env = strtolower((string)(getenv('APP_ENV') ?: 'local'));

        // Clear session data
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        // Fresh session for flash message
        pf_session_start();
        $_SESSION['magic_link_error'] = 'Your session expired. Please try again.';

        if ($env !== 'live' && $env !== 'production') {
            $_SESSION['debug_logout_reason'] = $reason;
        }

        pf_redirect('/login');
    }
}

/**
 * Backwards-compatible wrapper.
 */
if (!function_exists('pf_force_logout_and_redirect')) {
    function pf_force_logout_and_redirect(): never
    {
        pf_logout_with_reason('session_security');
    }
}

/**
 * Verify session security on every request.
 * Only runs for logged-in users.
 */
if (!function_exists('pf_verify_session_security')) {
    function pf_verify_session_security(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            pf_session_start();
        }

        // Guests: nothing to do
        if (!isset($_SESSION['user_id'])) {
            return;
        }

        $now     = time();
        $env     = strtolower((string)(getenv('APP_ENV') ?: 'local'));
        $envLive = in_array($env, ['live', 'production'], true);

        // 1) Absolute lifetime
        $createdAt = $_SESSION['pf_created_at'] ?? null;
        if ($createdAt === null) {
            $_SESSION['pf_created_at'] = $now;
        } elseif (($now - (int)$createdAt) > PF_SESSION_ABSOLUTE_LIFETIME) {
            pf_logout_with_reason('absolute_lifetime');
        }

        // 2) Idle timeout
        if (PF_SESSION_IDLE_TIMEOUT > 0) {
            $lastActive = $_SESSION['pf_last_active'] ?? null;
            if ($lastActive !== null && ($now - (int)$lastActive) > PF_SESSION_IDLE_TIMEOUT) {
                pf_logout_with_reason('idle_timeout');
            }
        }

        // 3) Fingerprint check
        $currentFingerprint = pf_generate_fingerprint();
        $storedFingerprint  = $_SESSION['pf_fingerprint'] ?? null;

        if ($storedFingerprint === null) {
            $_SESSION['pf_fingerprint'] = $currentFingerprint;
        } elseif (!hash_equals((string)$storedFingerprint, $currentFingerprint)) {
            pf_logout_with_reason('fingerprint_mismatch');
        }

        // 4) IP prefix check (LIVE only)
        if ($envLive) {
            $ip    = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $parts = explode('.', $ip);

            $ipPrefix = count($parts) >= 3
                ? implode('.', array_slice($parts, 0, 3))
                : $ip;

            $storedIp = $_SESSION['pf_ip_prefix'] ?? null;

            if ($storedIp === null) {
                $_SESSION['pf_ip_prefix'] = $ipPrefix;
            } elseif ((string)$storedIp !== $ipPrefix) {
                pf_logout_with_reason('ip_prefix_changed');
            }
        }

        // 5) Refresh last active
        $_SESSION['pf_last_active'] = $now;
    }
}

/**
 * Legacy helper kept for older calls.
 */
if (!function_exists('pf_enforce_idle_timeout')) {
    function pf_enforce_idle_timeout(): void
    {
        pf_verify_session_security();
    }
}
