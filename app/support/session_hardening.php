<?php declare(strict_types=1);

/**
 * Session hardening for Plainfully
 *
 * - Absolute lifetime (SESSION_LIFETIME_DAYS, default 7)
 * - Idle timeout (SESSION_IDLE_HOURS, default 12)
 * - Fingerprint binding (user agent)
 * - Optional IP prefix binding (first 3 octets) in LIVE only
 */

// Read config from env and expose as constants
define(
    'PF_SESSION_ABSOLUTE_LIFETIME',
    60 * 60 * 24 * (int)(getenv('SESSION_LIFETIME_DAYS') ?: 7)
);
define(
    'PF_SESSION_IDLE_TIMEOUT',
    60 * 60 * (int)(getenv('SESSION_IDLE_HOURS') ?: 12)
);

/**
 * Generate a simple fingerprint based on the user agent.
 */
function pf_generate_fingerprint(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    return hash('sha256', $ua);
}

/**
 * Centralised logout with a reason (for non-live debugging).
 */
function pf_logout_with_reason(string $reason): never
{
    $env = getenv('APP_ENV') ?: 'local';

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
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    // Start a fresh session for the flash message
    session_start();
    $_SESSION['magic_link_error'] = 'Your session expired. Please try again.';

    // In non-live, expose the reason so you can see what tripped it
    $envLower = strtolower($env);
    if ($envLower !== 'live' && $envLower !== 'production') {
        $_SESSION['debug_logout_reason'] = $reason;
    }

    pf_redirect('/login');
}

/**
 * Backwards-compatible wrapper.
 * Use pf_logout_with_reason instead, but keep this to avoid fatal errors.
 */
function pf_force_logout_and_redirect(): never
{
    pf_logout_with_reason('session_security');
}

/**
 * Verify session security on every request.
 * Only runs for logged-in users.
 */
function pf_verify_session_security(): void
{
    // Guests: nothing to do
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    $now     = time();
    $env     = getenv('APP_ENV') ?: 'local';
    $envLive = in_array(strtolower($env), ['live', 'production'], true);

    // 1) Absolute lifetime (max N days from creation)
    $createdAt = $_SESSION['pf_created_at'] ?? null;
    if ($createdAt === null) {
        // Initialise for existing sessions that pre-date hardening
        $_SESSION['pf_created_at'] = $now;
    } elseif (($now - (int)$createdAt) > PF_SESSION_ABSOLUTE_LIFETIME) {
        pf_logout_with_reason('absolute_lifetime');
    }

    // 2) Idle timeout (no activity for > PF_SESSION_IDLE_TIMEOUT)
    if (PF_SESSION_IDLE_TIMEOUT > 0) {
        $lastActive = $_SESSION['pf_last_active'] ?? null;
        if ($lastActive !== null && ($now - (int)$lastActive) > PF_SESSION_IDLE_TIMEOUT) {
            pf_logout_with_reason('idle_timeout');
        }
    }

    // 3) Fingerprint check (bind to user agent)
    $currentFingerprint = pf_generate_fingerprint();
    $storedFingerprint  = $_SESSION['pf_fingerprint'] ?? null;

    if ($storedFingerprint === null) {
        // Initialise for older sessions
        $_SESSION['pf_fingerprint'] = $currentFingerprint;
    } elseif (!hash_equals($storedFingerprint, $currentFingerprint)) {
        pf_logout_with_reason('fingerprint_mismatch');
    }

    // 4) IP prefix check (first 3 octets of IPv4) – LIVE / PRODUCTION only
    if ($envLive) {
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $parts = explode('.', $ip);

        // Simple IPv4 handling; for IPv6 you may want a different strategy.
        $ipPrefix = count($parts) >= 3
            ? implode('.', array_slice($parts, 0, 3))
            : $ip;

        $storedIp = $_SESSION['pf_ip_prefix'] ?? null;

        if ($storedIp === null) {
            $_SESSION['pf_ip_prefix'] = $ipPrefix;
        } elseif ($storedIp !== $ipPrefix) {
            pf_logout_with_reason('ip_prefix_changed');
        }
    }

    // 5) Refresh last active timestamp (sliding idle window)
    $_SESSION['pf_last_active'] = $now;
}

/**
 * Legacy idle-timeout helper.
 * Keep it for older calls, but delegate to the main verifier.
 */
function pf_enforce_idle_timeout(): void
{
    // We assume session already started in bootstrap.
    pf_verify_session_security();
}
