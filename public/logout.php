<?php declare(strict_types=1);
session_start();

/**
 * Simple logout handler for Plainfully.
 * Destroys the session and sends user back to login.
 */

// Unset all session variables
$_SESSION = [];

// Delete the session cookie if it exists
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

// Destroy session storage
session_destroy();

// Redirect to login
header('Location: /login.php');
exit;
