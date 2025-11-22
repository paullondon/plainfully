<?php declare(strict_types=1);

/**
 * Plainfully – front controller
 *
 * Single entry point for all web requests:
 *  - /              → welcome
 *  - /login         → magic-link login form
 *  - /magic/request → POST, send magic link
 *  - /magic/verify  → GET, verify magic link
 *  - /logout        → POST, logout
 *  - /health        → health check (requires DEBUG_TOKEN)
 */

// ---------------------------------------------------------
// 0. Simple .env loader (root-level .env)
// ---------------------------------------------------------
$envPath = dirname(__DIR__) . '/.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        putenv("$k=$v");
    }
}

// ---------------------------------------------------------
// 1. Error reporting based on environment
// ---------------------------------------------------------
$appEnv = getenv('APP_ENV') ?: 'local';

if (strtolower($appEnv) === 'live' || strtolower($appEnv) === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// Basic error / exception handlers – in live, log only
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e): void {
    // TODO: log to file / monitoring here
    http_response_code(500);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Plainfully – Error</title></head><body>';
    echo '<h1>Something went wrong</h1>';
    echo '<p>Please try again in a moment.</p>';
    // In non-live envs, show message for debugging
    $env = getenv('APP_ENV') ?: 'local';
    if (strtolower($env) !== 'live' && strtolower($env) !== 'production') {
        echo '<pre style="white-space:pre-wrap;">' . htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    echo '</body></html>';
});

// ---------------------------------------------------------
// 2. Security headers
// ---------------------------------------------------------
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');

// Content-Security-Policy kept simple for now; expand later
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com;");

// ---------------------------------------------------------
// 3. Sessions – secure cookie flags
// ---------------------------------------------------------
$cookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $cookieParams['path'],
    'domain'   => $cookieParams['domain'],
    'secure'   => true,      // HTTPS only
    'httponly' => true,      // not accessible to JS
    'samesite' => 'Lax',     // good default for auth
]);
session_start();

// ---------------------------------------------------------
// 4. Load config + helpers
// ---------------------------------------------------------
$config = require dirname(__DIR__) . '/config/app.php';

require dirname(__DIR__) . '/app/support/db.php';
require dirname(__DIR__) . '/app/support/mailer.php';
require dirname(__DIR__) . '/app/support/rate_limiter.php';
require dirname(__DIR__) . '/app/support/session_hardening.php';
require dirname(__DIR__) . '/app/support/auth_middleware.php';

// ---------------------------------------------------------
// 5. Small helper functions
// ---------------------------------------------------------

function pf_redirect(string $path, int $status = 302): never
{
    header('Location: ' . $path, true, $status);
    exit;
}

/**
 * Render a simple HTML page shell with a central card.
 */
function pf_render_shell(string $title, string $innerHtml): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="/assets/css/app.css">
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    </head>
    <body>
    <main class="pf-auth-shell">
        <section class="pf-auth-card">
            <?= $innerHtml ?>
        </section>
    </main>
    </body>
    </html>
    <?php
}

/**
 * Normalise and validate an email address.
 */
function pf_normalise_email(string $email): ?string
{
    $email = trim(mb_strtolower($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return $email;
}

/**
 * Generate secure random magic-link token.
 */
function pf_generate_magic_token(): string
{
    return bin2hex(random_bytes(32)); // 64-char hex
}

/**
 * Verify Cloudflare Turnstile token server-side.
 */
function pf_verify_turnstile(string $token = null): bool
{
    $token = $token ?? '';
    $token = trim($token);
    if ($token === '') {
        return false;
    }

    $config = require dirname(__DIR__) . '/config/app.php';
    $secret = $config['security']['turnstile_secret_key'] ?? '';
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

    if ($secret === '') {
        // Fail-safe: require Turnstile to be configured
        return false;
    }

    $payload = http_build_query([
        'secret'   => $secret,
        'response' => $token,
        'remoteip' => $remoteIp,
    ]);

    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 5,
        ],
    ];

    $context = stream_context_create($options);

    try {
        $result = file_get_contents(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            false,
            $context
        );

        if ($result === false) {
            return false;
        }

        $data = json_decode($result, true, 16, JSON_THROW_ON_ERROR);
        return isset($data['success']) && $data['success'] === true;
    } catch (Throwable $e) {
        // TODO: log $e->getMessage()
        return false;
    }
}

/**
 * Send magic-link email via PHPMailer wrapper.
 */
function pf_send_magic_link_email(string $toEmail, string $link): bool
{
    $subject = 'Your Plainfully sign-in link';

    $body = "Hi,\n\n"
        . "Use the link below to sign in to Plainfully. "
        . "It will expire shortly and can only be used once.\n\n"
        . $link . "\n\n"
        . "If you did not request this, you can ignore this email.\n";

    return pf_send_email($toEmail, $subject, $body);
}

// ---------------------------------------------------------
// 6. Route handlers
// ---------------------------------------------------------

function handle_welcome(): void
{
    $userId = $_SESSION['user_id'] ?? null;

    if ($userId) {
        $inner = '
            <h1 class="pf-auth-title">Plainfully</h1>
            <p class="pf-auth-subtitle">
                You’re signed in. (User ID: ' . (int)$userId . ')
            </p>
            <form method="post" action="/logout">
                <button type="submit" class="pf-button">Sign out</button>
            </form>
        ';
    } else {
        $inner = '
            <h1 class="pf-auth-title">Welcome to Plainfully</h1>
            <p class="pf-auth-subtitle">
                You’re not logged in yet. Use a magic link to sign in.
            </p>
            <a class="pf-button" href="/login">Go to login</a>
        ';
    }

    pf_render_shell('Plainfully', $inner);
}

function handle_login_form(array $config): void
{
    $siteKey = $config['security']['turnstile_site_key'] ?? '';

    $loginError = $_SESSION['magic_link_error']  ?? '';
    $loginOk    = $_SESSION['magic_link_ok']     ?? '';

    unset($_SESSION['magic_link_error'], $_SESSION['magic_link_ok']);

    ob_start();
    ?>
    <h1 class="pf-auth-title">Sign in to Plainfully</h1>
    <p class="pf-auth-subtitle">
        We’ll email you a one-time magic link to sign in.
    </p>

    <form method="post"
          action="/magic/request"
          novalidate
          id="magic-link-form">
        <div class="pf-field">
            <label for="email" class="pf-label">Email address</label>
            <input
                id="email"
                name="email"
                type="email"
                required
                autocomplete="email"
                class="pf-input"
                placeholder="you@example.com">
        </div>

        <!-- Invisible Turnstile widget -->
        <div class="cf-turnstile"
             data-sitekey="<?= htmlspecialchars((string)$siteKey, ENT_QUOTES, 'UTF-8') ?>"
             data-callback="pfOnTurnstileSuccess"
             data-action="magic_link"
             data-size="invisible">
        </div>

        <button type="submit" class="pf-button">
            Send magic link
        </button>
    </form>

    <p class="pf-note">
        For security, the link will expire in about 30 minutes and can only be used once.
    </p>

    <?php if ($loginOk): ?>
        <p class="pf-message-ok"><?= htmlspecialchars($loginOk, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if ($loginError): ?>
        <p class="pf-message-error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <script>
        // Invisible Turnstile: only submit form after successful verification
        function pfOnTurnstileSuccess() {
            const form = document.getElementById('magic-link-form');
            if (form && !form.dataset.submitted) {
                form.dataset.submitted = '1';
                form.submit();
            }
        }
    </script>
    <?php
    $inner = ob_get_clean();
    pf_render_shell('Login | Plainfully', $inner);
}

function handle_magic_request(array $config): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pf_redirect('/login');
    }

    $baseUrl    = rtrim($config['app']['base_url'], '/');
    $ttlMinutes = (int)($config['auth']['magic_link_ttl_minutes'] ?? 30);

    $emailRaw = $_POST['email'] ?? '';
    $email    = pf_normalise_email($emailRaw);

    $genericMessage = 'If that email is registered, a sign-in link will arrive shortly.';

    if ($email === null) {
        $_SESSION['magic_link_error'] = 'Please enter a valid email address.';
        pf_redirect('/login');
    }

    // Turnstile verification
    $turnstileToken = $_POST['cf-turnstile-response'] ?? null;
    if (!pf_verify_turnstile($turnstileToken)) {
        $_SESSION['magic_link_error'] = 'We could not verify your request. Please try again.';
        pf_redirect('/login');
    }

    // Rate limiting (per email + per IP)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (pf_rate_limit_magic_link($email, $ip)) {
        $_SESSION['magic_link_error'] = 'You have requested too many links. Please wait a little while and try again.';
        pf_redirect('/login');
    }

    try {
        $pdo = pf_db();
        $pdo->beginTransaction();

        // Find or create user
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            $stmt = $pdo->prepare('INSERT INTO users (email) VALUES (:email)');
            $stmt->execute([':email' => $email]);
            $userId = (int)$pdo->lastInsertId();
        } else {
            $userId = (int)$user['id'];
        }

        // Create token
        $rawToken  = pf_generate_magic_token();
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new DateTimeImmutable("+{$ttlMinutes} minutes"))->format('Y-m-d H:i:s');

        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $insert = $pdo->prepare(
            'INSERT INTO magic_login_tokens
                (user_id, token_hash, expires_at, created_ip, created_agent)
             VALUES
                (:user_id, :token_hash, :expires_at, :ip, :agent)'
        );
        $insert->execute([
            ':user_id'    => $userId,
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
            ':ip'         => $ip,
            ':agent'      => mb_substr((string)$agent, 0, 255),
        ]);

        $pdo->commit();

        $link = $baseUrl . '/magic/verify?token=' . urlencode($rawToken);

        if (!pf_send_magic_link_email($email, $link)) {
            $_SESSION['magic_link_error'] = 'Something went wrong sending your link. Please try again in a moment.';
        } else {
            $_SESSION['magic_link_ok'] = $genericMessage;
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // TODO: log $e
        $_SESSION['magic_link_error'] = 'We had trouble processing that request. Please try again.';
    }

    pf_redirect('/login');
}

function handle_magic_verify(): void
{
    $env   = getenv('APP_ENV') ?: 'local';
    $debug = !in_array(strtolower($env), ['live', 'production'], true);

    $showDebug = function (string $title, string $message) use ($debug): void {
        if (!$debug) {
            pf_redirect('/login');
        }
        $inner = '
            <h1 class="pf-auth-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>
            <p class="pf-auth-subtitle">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
            <p class="pf-note"><a href="/login">Back to login</a></p>
        ';
        pf_render_shell('Magic link issue', $inner);
        exit;
    };

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $showDebug('Invalid request', 'Magic link must be opened via GET request.');
    }

    $token = $_GET['token'] ?? '';
    $token = trim($token);

    if ($token === '') {
        $showDebug('Missing token', 'No token was provided in the URL.');
    }

    $tokenHash = hash('sha256', $token);

    try {
        $pdo = pf_db();

        // Look up token
        $stmt = $pdo->prepare(
            'SELECT id, user_id, expires_at, consumed_at
             FROM magic_login_tokens
             WHERE token_hash = :hash
             LIMIT 1'
        );
        $stmt->execute([':hash' => $tokenHash]);
        $row = $stmt->fetch();

        if (!$row) {
            $showDebug('Token not found', 'We could not find this magic link token in the database. It may have been deleted or never created.');
        }

        if ($row['consumed_at'] !== null) {
            $showDebug('Token already used', 'This magic link has already been used once. For security, links are single-use only.');
        }

        $now     = new DateTimeImmutable('now');
        $expires = new DateTimeImmutable($row['expires_at']);

        if ($now > $expires) {
            $showDebug('Token expired', 'This magic link has expired. Please request a new one.');
        }

        // Mark token as consumed
        $update = $pdo->prepare(
            'UPDATE magic_login_tokens
             SET consumed_at = NOW()
             WHERE id = :id'
        );
        $update->execute([':id' => $row['id']]);
z
        // Log user in
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['user_id'];
        $_SESSION['pf_fingerprint'] = pf_generate_fingerprint();
        $_SESSION['pf_ip_prefix']   = implode('.', array_slice(explode('.', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 3));
        $_SESSION['pf_last_active'] = time();
        $_SESSION['pf_created_at']  = time();

    } catch (Throwable $e) {
        $showDebug(
            'Verification error',
            'Exception: ' . $e->getMessage()
            . "\n\nFile: " . $e->getFile()
            . "\nLine: " . $e->getLine()
        );
    }


    pf_redirect('/');
}


function handle_logout(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pf_redirect('/');
    }

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

function handle_health(array $config): void
{
    $debugToken = getenv('DEBUG_TOKEN') ?: '';
    $token      = $_GET['token'] ?? '';

    if ($debugToken === '' || $token !== $debugToken) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $status = [
        'app_env'   => $config['app']['env'] ?? 'unknown',
        'db_ok'     => false,
        'turnstile' => [
            'site_key_set'   => !empty($config['security']['turnstile_site_key']),
            'secret_set'     => !empty($config['security']['turnstile_secret_key']),
        ],
        'mail'      => [
            'from_email_set' => !empty($config['mail']['from_email']),
            'smtp_host_set'  => !empty($config['smtp']['host']),
        ],
    ];

    try {
        $pdo = pf_db();
        $pdo->query('SELECT 1');
        $status['db_ok'] = true;
    } catch (Throwable $e) {
        $status['db_ok'] = false;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($status, JSON_PRETTY_PRINT);
}

// ---------------------------------------------------------
// 7. Very small router
// ---------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 🔐 GLOBAL SESSION SECURITY (only affects logged-in users)
pf_verify_session_security();

// Normalise trailing slash (except root)
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

switch (true) {
    case $path === '/' && $method === 'GET':
        require_guest();
        handle_welcome();
        break;

    case $path === '/login' && $method === 'GET':
        require_guest();
        handle_login_form($config);
        break;

    case $path === '/magic/request' && $method === 'POST':
        require_guest();
        handle_magic_request($config);
        break;

    case $path === '/magic/verify' && $method === 'GET':
        require_guest();
        handle_magic_verify();
        break;

    case $path === '/logout' && $method === 'POST':
        handle_logout();
        break;

    case $path === '/health' && $method === 'GET':
        require_guest();
        handle_health($config);
        break;

    default:
        http_response_code(404);
        pf_render_shell('Not found', '<h1 class="pf-auth-title">404</h1><p class="pf-auth-subtitle">Page not found.</p>');
        break;
}
