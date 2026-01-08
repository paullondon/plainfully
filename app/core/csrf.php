<?php declare(strict_types=1);

/**
 * CSRF protection helpers for Plainfully
 *
 * - pf_csrf_token(): generate / return token
 * - pf_csrf_field(): echo hidden input field
 * - pf_verify_csrf_or_abort(): validate POST token
 */

if (!function_exists('pf_csrf_token')) {
    function pf_csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            pf_session_start();
        }

        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION['csrf_token'];
    }
}

if (!function_exists('pf_csrf_field')) {
    function pf_csrf_field(): void
    {
        $token = pf_csrf_token();

        echo '<input type="hidden" name="_token" value="'
            . htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
            . '">';
    }
}

if (!function_exists('pf_verify_csrf_or_abort')) {
    function pf_verify_csrf_or_abort(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            pf_session_start();
        }

        $sessionToken = $_SESSION['csrf_token'] ?? null;
        $postedToken  = $_POST['_token'] ?? null;

        $ok = is_string($sessionToken)
            && is_string($postedToken)
            && $sessionToken !== ''
            && $postedToken !== ''
            && hash_equals($sessionToken, $postedToken);

        if ($ok) {
            return;
        }

        $env = strtolower((string)(getenv('APP_ENV') ?: 'local'));

        // Clear session safely
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

        // Start a fresh session for the login message
        pf_session_start();
        $_SESSION['magic_link_error'] = 'Your session expired. Please try again.';

        if ($env !== 'live' && $env !== 'production') {
            $_SESSION['debug_logout_reason'] = 'csrf_failed';
        }

        pf_redirect('/login');
    }
}
