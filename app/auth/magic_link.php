<?php declare(strict_types=1);

function handle_magic_request(array $config): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pf_redirect('/login');
    }

    $baseUrl = rtrim($config['app']['base_url'], '/');
    $ttlMinutes = (int)($config['auth']['magic_link_ttl_minutes'] ?? 30);

    $emailRaw = $_POST['email'] ?? '';
    $email = pf_normalise_email($emailRaw);

    if ($email === null) {
        $_SESSION['magic_link_error'] = 'Please enter a valid email address.';
        pf_redirect('/login');
    }

    // Turnstile
    $turnstileToken = $_POST['cf-turnstile-response'] ?? null;
    if (!pf_verify_turnstile($turnstileToken)) {
        $_SESSION['magic_link_error'] = 'Verification failed. Please try again.';
        pf_redirect('/login');
    }

    // Rate limit
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (pf_rate_limit_magic_link($email, $ip)) {
        $_SESSION['magic_link_error'] = 'Too many requests. Please wait.';
        pf_redirect('/login');
    }

    try {
        $pdo = pf_db();
        $pdo->beginTransaction();

        // user find/create
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        $userId = $user ? (int) $user['id'] : null;

        if (!$userId) {
            $stmt = $pdo->prepare('INSERT INTO users (email) VALUES (:email)');
            $stmt->execute([':email' => $email]);
            $userId = (int)$pdo->lastInsertId();
        }

        // magic token
        $rawToken = pf_generate_magic_token();
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new DateTimeImmutable("+{$ttlMinutes} minutes"))->format('Y-m-d H:i:s');
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $insert = $pdo->prepare(
            'INSERT INTO magic_login_tokens
                (user_id, token_hash, expires_at, created_ip, created_agent)
             VALUES
                (:user_id, :token_hash, :expires_at, :ip, :agent)'
        );

        $insert->execute([
            ':user_id' => $userId,
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
            ':ip' => $ip,
            ':agent' => mb_substr($agent, 0, 255)
        ]);

        $pdo->commit();

        $link = $baseUrl . '/magic/verify?token=' . urlencode($rawToken);

        if (!pf_send_magic_link_email($email, $link)) {
            $_SESSION['magic_link_error'] = 'Something went wrong sending your link.';
        } else {
            $_SESSION['magic_link_ok'] = 'If that email is registered, a sign-in link will arrive shortly.';
        }

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['magic_link_error'] = 'We had trouble processing that request.';
    }

    pf_redirect('/login');
}

function handle_magic_verify(): void
{
    $env = getenv('APP_ENV') ?: 'local';
    $debug = !in_array(strtolower($env), ['live', 'production'], true);

    $showDebug = function ($title, $msg) use ($debug) {
        if (!$debug) pf_redirect('/login');
        pf_render_shell($title, '<pre>' . htmlspecialchars($msg) . '</pre>');
        exit;
    };

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $showDebug('Invalid', 'Must be GET.');
    }

    $token = trim($_GET['token'] ?? '');
    if ($token === '') $showDebug('Missing token', 'No token provided.');

    $tokenHash = hash('sha256', $token);

    try {
        $pdo = pf_db();

        // find token
        $stmt = $pdo->prepare(
            'SELECT id, user_id, expires_at, consumed_at
             FROM magic_login_tokens
             WHERE token_hash = :h LIMIT 1'
        );
        $stmt->execute([':h' => $tokenHash]);
        $row = $stmt->fetch();

        if (!$row) $showDebug('Not found', 'Token missing.');

        if ($row['consumed_at']) $showDebug('Used', 'This link was already used.');

        $now = new DateTimeImmutable();
        $expires = new DateTimeImmutable($row['expires_at']);
        if ($now > $expires) $showDebug('Expired', 'This link is expired.');

        // consume
        $update = $pdo->prepare('UPDATE magic_login_tokens SET consumed_at = NOW() WHERE id = :id');
        $update->execute([':id' => $row['id']]);

        // login user
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['user_id'];
        $_SESSION['pf_fingerprint'] = pf_generate_fingerprint();
        $_SESSION['pf_ip_prefix'] = implode('.', array_slice(explode('.', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 3));
        $_SESSION['pf_last_active'] = time();
        $_SESSION['pf_created_at']  = time();

    } catch (Throwable $e) {
        $showDebug('Exception', $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
    }

    pf_redirect('/');
}
