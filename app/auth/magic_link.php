<?php declare(strict_types=1);

// app/auth/magic_link.php

require_once __DIR__ . '/session_helpers.php';

use DateTimeImmutable;
use Throwable;

if (!function_exists('handle_magic_request')) {

    function handle_magic_request(array $config): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            pf_redirect('/login');
        }

        pf_verify_csrf_or_abort();

        $baseUrl    = rtrim((string)($config['app']['base_url'] ?? ''), '/');
        $ttlMinutes = (int)($config['auth']['magic_link_ttl_minutes'] ?? 30);

        if ($baseUrl === '') {
            $_SESSION['magic_link_error'] = 'Configuration error. Please try again later.';
            error_log('handle_magic_request: base_url missing in config');
            pf_redirect('/login');
        }

        $emailRaw = (string)($_POST['email'] ?? '');
        $email    = pf_normalise_email($emailRaw);

        if ($email === null) {
            $_SESSION['magic_link_error'] = 'Please enter a valid email address.';
            if (function_exists('pf_log_auth_event')) {
                pf_log_auth_event('magic_link_invalid_email', null, $emailRaw, 'Invalid email format');
            }
            pf_redirect('/login');
        }

        // Rate limit
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (function_exists('pf_rate_limit_magic_link') && pf_rate_limit_magic_link($email, $ip)) {
            $_SESSION['magic_link_error'] = 'Too many requests. Please wait.';
            if (function_exists('pf_log_auth_event')) {
                pf_log_auth_event('magic_link_rate_limited', null, $email, 'Rate limit hit');
            }
            pf_redirect('/login');
        }

        $userId = null;

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

                if (function_exists('pf_log_auth_event')) {
                    pf_log_auth_event('user_created', $userId, $email, 'User created via magic link');
                }
            }

            // magic token
            $rawToken  = pf_generate_magic_token();
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = (new DateTimeImmutable("+{$ttlMinutes} minutes"))->format('Y-m-d H:i:s');
            $agent     = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

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
                ':agent'      => function_exists('mb_substr') ? mb_substr($agent, 0, 255) : substr($agent, 0, 255),
            ]);

            $pdo->commit();

            $link = $baseUrl . '/magic/verify?token=' . urlencode($rawToken);

            // Send email using existing mailer wrapper
            $subject = 'Your Plainfully sign-in link';

            $inner = '
                <h2 style="margin:0 0 10px;font-size:18px;color:#111827;">Sign in to Plainfully</h2>
                <p style="margin:0 0 14px;color:#111827;line-height:1.5;">
                    Use the button below to sign in. This link expires soon and can only be used once.
                </p>
                <p style="margin:18px 0 0;">
                    <a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="
                        display:inline-block;background:#2C6F63;color:#ffffff;text-decoration:none;
                        padding:12px 16px;border-radius:10px;font-weight:700;line-height:1.2;
                    ">Sign in</a>
                </p>
                <p style="margin:10px 0 0;color:#6B7280;font-size:13px;line-height:1.4;">
                    If the button doesn’t work, copy and paste this link into your browser:<br>
                    <span style="word-break:break-all;color:#2C6F63;">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</span>
                </p>
            ';

            $html = function_exists('pf_email_template') ? pf_email_template($subject, $inner) : $inner;

            $ok = false;
            if (function_exists('pf_send_email')) {
                [$ok] = pf_send_email($email, $subject, $html, 'noreply', null);
            }

            if (!$ok) {
                $_SESSION['magic_link_error'] = 'Something went wrong sending your link.';
                if (function_exists('pf_log_auth_event')) {
                    pf_log_auth_event('magic_link_email_failed', $userId, $email, 'Email send failed');
                }
            } else {
                if (!empty($config['debug']['magic_links'])) {
                    $_SESSION['magic_link_ok'] = 'Debug sign-in link is available below.';
                    $_SESSION['magic_link_debug_url'] = $link;

                    if (function_exists('pf_log_auth_event')) {
                        pf_log_auth_event('magic_link_email_sent', $userId, $email, 'Magic link email sent (debug exposed)');
                    }
                } else {
                    $_SESSION['magic_link_ok'] = 'If that email is registered, a sign-in link will arrive shortly.';
                    if (function_exists('pf_log_auth_event')) {
                        pf_log_auth_event('magic_link_email_sent', $userId, $email, 'Magic link email sent');
                    }
                }
            }

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['magic_link_error'] = 'We had trouble processing that request.';
            if (function_exists('pf_log_auth_event')) {
                pf_log_auth_event('magic_link_exception', $userId, $email ?? null, $e->getMessage());
            } else {
                error_log('magic_link_exception: ' . $e->getMessage());
            }
        }

        pf_redirect('/login');
    }
}

function handle_magic_verify(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

    // Friendly page for invalid/expired/used links
    $renderInvalid = function (string $title, string $msg): void {
        $titleSafe = $title;
        $msgSafe   = $msg;

        try {
            // Make variables available to the view
            $title = $titleSafe;
            $msg   = $msgSafe;

            ob_start();
            require __DIR__ . '/../views/auth_invalid_link.php';
            $inner = (string)ob_get_clean();

            if (function_exists('pf_render_shell')) {
                pf_render_shell('Link invalid', $inner);
            } else {
                echo $inner;
            }
        } catch (Throwable $e) {
            error_log('magic_verify render failed: ' . $e->getMessage());
            echo 'Link invalid.';
        }
        exit;
    };

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        if (function_exists('pf_log_auth_event')) {
            pf_log_auth_event('magic_verify_invalid_method', null, null, 'Non-GET request');
        }
        $renderInvalid('Invalid request', 'This link must be opened in your browser.');
    }

    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        if (function_exists('pf_log_auth_event')) {
            pf_log_auth_event('magic_verify_missing_token', null, null, 'No token provided');
        }
        $renderInvalid('Missing token', 'No token was provided.');
    }

    $tokenHash = hash('sha256', $token);

    try {
        $pdo = pf_db();

        $stmt = $pdo->prepare(
            'SELECT id, user_id, expires_at, consumed_at
             FROM magic_login_tokens
             WHERE token_hash = :h LIMIT 1'
        );
        $stmt->execute([':h' => $tokenHash]);
        $row = $stmt->fetch();

        if (!$row) {
            if (function_exists('pf_log_auth_event')) {
                pf_log_auth_event('magic_verify_not_found', null, null, 'Token not found');
            }
            $renderInvalid('Not found', 'That link is not recognised.');
        }

        if (!empty($row['consumed_at'])) {
            if (function_exists('pf_log_auth_event')) {
                pf_log_auth_event('magic_verify_already_used', (int)$row['user_id'], null, 'Token already consumed');
            }
            $renderInvalid('Already used', 'This link was already used.');
        }

        $now     = new DateTimeImmutable();
        $expires = new DateTimeImmutable((string)$row['expires_at']);

        if ($now > $expires) {
            if (function_exists('pf_log_auth_event')) {
                pf_log_auth_event('magic_verify_expired', (int)$row['user_id'], null, 'Token expired');
            }
            $renderInvalid('Expired', 'This link has expired.');
        }

        // consume
        $update = $pdo->prepare('UPDATE magic_login_tokens SET consumed_at = NOW() WHERE id = :id');
        $update->execute([':id' => (int)$row['id']]);

        // email for session
        $u = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
        $u->execute([':id' => (int)$row['user_id']]);
        $email = (string)($u->fetchColumn() ?: '');

        pf_session_login((int)$row['user_id'], $email);

        // Extra security fields (optional)
        if (function_exists('pf_generate_fingerprint')) {
            $_SESSION['pf_fingerprint'] = pf_generate_fingerprint();
        }
        $_SESSION['pf_ip_prefix']   = implode('.', array_slice(explode('.', (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')), 0, 3));
        $_SESSION['pf_last_active'] = time();
        $_SESSION['pf_created_at']  = $_SESSION['pf_created_at'] ?? time();

        if (function_exists('pf_log_auth_event')) {
            pf_log_auth_event('login_success', (int)$row['user_id'], null, 'Magic link verified');
        }

    } catch (Throwable $e) {
        if (function_exists('pf_log_auth_event')) {
            pf_log_auth_event('magic_verify_exception', null, null, $e->getMessage());
        } else {
            error_log('magic_verify_exception: ' . $e->getMessage());
        }

        // Never expose stack traces in live/prod
        $env = strtolower((string)(getenv('APP_ENV') ?: 'local'));
        $msg = ($env === 'live' || $env === 'production')
            ? 'We could not verify that link.'
            : ($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());

        $renderInvalid('Could not verify', $msg);
    }

    pf_redirect('/dashboard');
}
