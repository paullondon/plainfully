<?php declare(strict_types=1);

/**
 * CSRF protection helpers for Plainfully
 *
 * - pf_csrf_token(): generate / return token
 * - pf_csrf_field(): echo hidden input field
 * - pf_verify_csrf_or_abort(): validate POST token
 */

function pf_csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output a hidden form field with the CSRF token.
 */
function pf_csrf_field(): void
{
    $token = pf_csrf_token();
    echo '<input type="hidden" name="_token" value="'
         . htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
         . '">';
}

/**
 * Verify CSRF token for POST requests.
 * If invalid, destroy session and redirect to login with an error.
 */
function pf_verify_csrf_or_abort(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return; // Only care about POST
    }

    $sessionToken = $_SESSION['csrf_token'] ?? null;
    $postedToken  = $_POST['_token']        ?? null;

    if (!$sessionToken || !$postedToken || !hash_equals($sessionToken, $postedToken)) {
        // Kill session + send user back to login
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

        session_start();
        $_SESSION['magic_link_error'] = 'Your session expired. Please try again.';
        pf_redirect('/login');
    }
}
