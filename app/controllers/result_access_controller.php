<?php declare(strict_types=1);
/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/controllers/result_access_controller.php
 * Purpose:
 *   Flow B: /r/{token} -> confirm email -> (optionally switch user) -> redirect to the *specific* clarification.
 *
 * Key behaviour:
 *   - Always prefers redirecting to /clarifications/view?id={check_id} after validation.
 *   - Sets a session "return_to" flag as a fallback if something else forces dashboard.
 *
 * Change history:
 *   - 2025-12-28: Force result redirect + add session return_to fallback + remove noisy use statements
 * ============================================================
 */

if (!function_exists('result_access_controller')) {
    function result_access_controller(string $token): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $token = trim($token);
        if ($token === '' || strlen($token) < 16) {
            http_response_code(404);
            pf_render_shell('Not found', '<p>That link is not valid.</p>');
            return;
        }

        // Always have a session for Flow B.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $pdo = pf_db();
        if (!($pdo instanceof \PDO)) {
            http_response_code(500);
            pf_render_shell('Error', '<p>Something went wrong. Please try again.</p>');
            return;
        }

        $pepper = (string)(getenv('RESULT_TOKEN_PEPPER') ?: '');
        if ($pepper === '') {
            http_response_code(500);
            pf_render_shell('Error', '<p>Server configuration issue. Please try again later.</p>');
            return;
        }

        $tokenHash = hash_hmac('sha256', $token, $pepper);

        $stmt = $pdo->prepare("
            SELECT id, user_id, check_id, recipient_email_hash, expires_at, validated_at
            FROM result_access_tokens
            WHERE token_hash = :th
              AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([':th' => $tokenHash]);
        $rec = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$rec) {
            http_response_code(404);
            pf_render_shell('Not found', '<p>This link has expired or is not valid.</p>');
            return;
        }

        $rowId         = (int)($rec['id'] ?? 0);
        $userId        = (int)($rec['user_id'] ?? 0);
        $checkId       = (int)($rec['check_id'] ?? 0);
        $recipientHash = (string)($rec['recipient_email_hash'] ?? '');
        $validatedAt   = $rec['validated_at'] ?? null;

        if ($rowId <= 0 || $userId <= 0 || $checkId <= 0 || $recipientHash === '') {
            http_response_code(500);
            pf_render_shell('Error', '<p>Something went wrong. Please try again.</p>');
            return;
        }

        $targetUrl = '/clarifications/view?id=' . $checkId;

        // Fallback if something later forces dashboard:
        $_SESSION['pf_return_to'] = $targetUrl;

        // If token already validated, just ensure correct user is logged in then go to result.
        if ($validatedAt !== null && $validatedAt !== '') {
            // If someone else is logged in, clear session (no CSRF here – this is a safe “switch user” flow).
            if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] !== $userId) {
                pf_result_access_soft_logout();
            }

            if (pf_result_access_login_user($pdo, $userId) === true) {
                pf_redirect($targetUrl);
                exit;
            }

            http_response_code(500);
            pf_render_shell('Error', '<p>Something went wrong. Please try again.</p>');
            return;
        }

        $errors   = [];
        $oldEmail = '';

        if ($method === 'POST') {
            $oldEmail = strtolower(trim((string)($_POST['email'] ?? '')));

            if ($oldEmail === '' || !filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } else {
                $enteredHash = hash_hmac('sha256', $oldEmail, $pepper);

                if (hash_equals($recipientHash, $enteredHash)) {
                    try {
                        $upd = $pdo->prepare("
                            UPDATE result_access_tokens
                            SET validated_at = NOW()
                            WHERE id = :id
                              AND validated_at IS NULL
                            LIMIT 1
                        ");
                        $upd->execute([':id' => $rowId]);

                        // If a different user is currently logged in, switch them out.
                        if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] !== $userId) {
                            pf_result_access_soft_logout();
                        }

                        if (pf_result_access_login_user($pdo, $userId) === true) {
                            pf_redirect($targetUrl);
                            exit; // critical: stop any “default redirect to dashboard” later
                        }

                        $errors[] = 'Something went wrong. Please try again.';
                    } catch (\Throwable $e) {
                        error_log('result_access_controller: validation update failed: ' . $e->getMessage());
                        $errors[] = 'Something went wrong. Please try again.';
                    }
                } else {
                    $errors[] = 'That email address does not match this link.';
                }
            }
        }

        $vm = [
            'token'    => $token,
            'errors'   => $errors,
            'oldEmail' => $oldEmail,
        ];

        ob_start();
        require dirname(__DIR__) . '/views/results/confirm_email.php';
        $inner = ob_get_clean();

        pf_render_shell('Confirm email address', $inner);
    }
}

if (!function_exists('pf_result_access_soft_logout')) {
    function pf_result_access_soft_logout(): void
    {
        // No CSRF here on purpose: this is a controlled “switch user” flow inside /r/{token}.
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

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }

        @session_start();
        @session_regenerate_id(true);
    }
}

if (!function_exists('pf_result_access_login_user')) {
    function pf_result_access_login_user(\PDO $pdo, int $userId): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $userId]);
            $u = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$u) { return false; }

            $email = (string)($u['email'] ?? '');
            $id    = (int)($u['id'] ?? 0);

            if ($id <= 0 || $email === '') { return false; }

            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }

            @session_regenerate_id(true);

            $_SESSION['user_id']    = $id;
            $_SESSION['user_email'] = $email;

            return true;
        } catch (\Throwable $e) {
            error_log('pf_result_access_login_user failed: ' . $e->getMessage());
            return false;
        }
    }
}
