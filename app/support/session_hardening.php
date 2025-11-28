<?php declare(strict_types=1);

/**
 * Session hardening for Plainfully
 *
 * - Absolute lifetime (SESSION_LIFETIME_DAYS, default 7)
 * - Idle timeout (SESSION_IDLE_HOURS, default 12)
 * - Fingerprint binding (user agent)
 * - IP prefix binding (first 3 octets)
 */

// Read config from env and expose as constants
define('PF_SESSION_ABSOLUTE_LIFETIME', 60 * 60 * 24 * (int)(getenv('SESSION_LIFETIME_DAYS') ?: 7));
define('PF_SESSION_IDLE_TIMEOUT',      60 * 60 *      (int)(getenv('SESSION_IDLE_HOURS')   ?: 12));

function pf_generate_fingerprint(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    return hash('sha256', $ua);
}

/**
 * Force logout and redirect to login.
 */
function pf_force_logout_and_redirect(): never
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
    pf_redirect('/login');
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

    $now = time();

    // 1) Absolute lifetime (max N days from creation)
    $createdAt = $_SESSION['pf_created_at'] ?? null;
    if ($createdAt === null || ($now - (int)$createdAt) > PF_SESSION_ABSOLUTE_LIFETIME) {
        pf_force_logout_and_redirect();
    }

    // 2) Idle timeout (no activity for > PF_SESSION_IDLE_TIMEOUT)
    $lastActive = $_SESSION['pf_last_active'] ?? null;
    if ($lastActive !== null && ($now - (int)$lastActive) > PF_SESSION_IDLE_TIMEOUT) {
        pf_force_logout_and_redirect();
    }

    // 3) Fingerprint check (bind to user agent)
    $currentFingerprint = pf_generate_fingerprint();
    $storedFingerprint  = $_SESSION['pf_fingerprint'] ?? null;

    if ($storedFingerprint === null) {
        // initialise for older sessions
        $_SESSION['pf_fingerprint'] = $currentFingerprint;
    } elseif (!hash_equals($storedFingerprint, $currentFingerprint)) {
        pf_force_logout_and_redirect();
    }

    // 4) IP prefix check (first 3 octets of IPv4)
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $parts    = explode('.', $ip);
    $ipPrefix = count($parts) >= 3 ? implode('.', array_slice($parts, 0, 3)) : $ip;
    $storedIp = $_SESSION['pf_ip_prefix'] ?? null;

    if ($storedIp === null) {
        $_SESSION['pf_ip_prefix'] = $ipPrefix;
    } elseif ($storedIp !== $ipPrefix) {
        pf_force_logout_and_redirect();
    }

    // 5) Refresh last active timestamp (sliding idle window)
    $_SESSION['pf_last_active'] = $now;
}
<?php declare(strict_types=1);

function pf_enforce_idle_timeout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $idleHours = (int)(getenv('SESSION_IDLE_HOURS') ?: 12);

    // 0 or negative means "no idle timeout"
    if ($idleHours <= 0) {
        return;
    }

    $now = time();
    $lastActivity = $_SESSION['last_activity'] ?? null;

    if ($lastActivity === null) {
        // First time we’ve seen this session
        $_SESSION['last_activity'] = $now;
        return;
    }

    $idleLimit = $idleHours * 3600;

    if (($now - (int)$lastActivity) > $idleLimit) {
        // Session has been idle too long – log out
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();

        // Redirect to login with a friendly message
        header('Location: /auth/login?session=expired');
        exit;
    }

    // Still active – refresh the timestamp (sliding window)
    $_SESSION['last_activity'] = $now;
}
