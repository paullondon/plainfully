<?php declare(strict_types=1);

// app/auth/magic_link.php

if (!function_exists('handle_magic_request')) {

    function handle_magic_request(array $config): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            pf_redirect('/login');
        }

        pf_verify_csrf_or_abort();

        $baseUrl    = rtrim($config['app']['base_url'], '/');
        $ttlMinutes = (int)($config['auth']['magic_link_ttl_minutes'] ?? 30);

        $emailRaw = $_POST['email'] ?? '';
        $email    = pf_normalise_email($emailRaw);

        if ($email === null) {
            $_SESSION['magic_link_error'] = 'Please enter a valid email address.';
            pf_redirect('/login');
        }
    
        if ($email === null) {
        $_SESSION['magic_link_error'] = 'Please enter a valid email address.';
        pf_log_auth_event('magic_link_invalid_email', null, $emailRaw, 'Invalid email format');
        pf_redirect('/login');
        }

        // Rate limit
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (pf_rate_limit_magic_link($email, $ip)) {
            $_SESSION['magic_link_error'] = 'Too many requests. Please wait.';
            pf_log_auth_event('magic_link_rate_limited', null, $email, 'Rate limit hit');
            pf_redirect('/login');
        }

        try {
            $pdo = pf_db();
            $pdo->beginTransaction();

            // user find/create
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $user   = $stmt->fetch();
            $userId = $user ? (int)$user['id'] : null;

            if (!$userId) {
                $stmt = $pdo->prepare('INSERT INTO users (email) VALUES (:email)');
                $stmt->execute([':email' => $email]);
                $userId = (int)$pdo->lastInsertId();
                pf_log_auth_event('user_created', $userId, $email, 'User created via magic link');
            }

            // magic token
            $rawToken  = pf_generate_magic_token();
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = (new DateTimeImmutable("+{$ttlMinutes} minutes"))->format('Y-m-d H:i:s');
            $agent     = $_SERVER['HTTP_USER_AGENT'] ?? '';

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
                ':agent'      => mb_substr($agent, 0, 255),
            ]);

            $pdo->commit();

            $link = $baseUrl . '/magic/verify?token=' . urlencode($rawToken);

            // make sure $config is in scope
            global $config;

            if (!pf_send_magic_link_email($email, $link)) {
                $_SESSION['magic_link_error'] = 'Something went wrong sending your link.';
                pf_log_auth_event('magic_link_email_failed', $userId, $email, 'Email send failed');
            } else {
                if (!empty($config['debug']['magic_links'])) {
                    // Human-friendly message
                    $_SESSION['magic_link_ok'] = 'Debug sign-in link is available below.';

                    // Store the raw URL separately
                    $_SESSION['magic_link_debug_url'] = $link;

                    pf_log_auth_event(
                        'magic_link_email_sent',
                        $userId,
                        $email,
                        'Magic link email sent (debug link exposed)'
                    );
                } else {
                    $_SESSION['magic_link_ok'] = 'If that email is registered, a sign-in link will arrive shortly.';
                    pf_log_auth_event(
                        'magic_link_email_sent',
                        $userId,
                        $email,
                        'Magic link email sent'
                    );
                }
            }

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['magic_link_error'] = 'We had trouble processing that request.';
            pf_log_auth_event('magic_link_exception', $userId ?? null, $email ?? null, $e->getMessage());
        }

        pf_redirect('/login');
    }
}


function handle_magic_verify(): void
{
    // Always use friendly page for invalid/expired/used links
    $showDebug = function (string $title, string $msg): void {
        ob_start();
        require __DIR__ . '/../views/auth_invalid_link.php';
        $inner = ob_get_clean();
        pf_render_shell('Link invalid', $inner);
        exit;
    };

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        pf_log_auth_event('magic_verify_invalid_method', null, null, 'Non-GET request');
        $showDebug('Invalid', 'Must be GET.');
    }

    $token = trim($_GET['token'] ?? '');
    if ($token === '') {
        pf_log_auth_event('magic_verify_missing_token', null, null, 'No token provided');
        $showDebug('Missing token', 'No token provided.');
    }

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

        if (!$row) {
            pf_log_auth_event('magic_verify_not_found', null, null, 'Token not found');
            $showDebug('Not found', 'Token missing.');
        }

        if ($row['consumed_at']) {
            pf_log_auth_event('magic_verify_already_used', (int)$row['user_id'], null, 'Token already consumed');
            $showDebug('Used', 'This link was already used.');
        }

        $now     = new DateTimeImmutable();
        $expires = new DateTimeImmutable($row['expires_at']);
        if ($now > $expires) {
            pf_log_auth_event('magic_verify_expired', (int)$row['user_id'], null, 'Token expired');
            $showDebug('Expired', 'This link is expired.');
        }

        // consume
        $update = $pdo->prepare('UPDATE magic_login_tokens SET consumed_at = NOW() WHERE id = :id');
        $update->execute([':id' => $row['id']]);

        // login user
        session_regenerate_id(true);
        $_SESSION['user_id']         = (int)$row['user_id'];
        $_SESSION['pf_fingerprint']  = pf_generate_fingerprint();
        $_SESSION['pf_ip_prefix']    = implode('.', array_slice(explode('.', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 3));
        $_SESSION['pf_last_active']  = time();
        $_SESSION['pf_created_at']   = time();

        pf_log_auth_event('login_success', (int)$row['user_id'], null, 'Magic link verified');

    } catch (Throwable $e) {
        pf_log_auth_event('magic_verify_exception', null, null, $e->getMessage());
        $showDebug('Exception', $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
    }

    pf_redirect('/dashboard');
}