<?php declare(strict_types=1);
/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/controllers/result_access_controller.php
 * Purpose:
 *   Guest access to a single clarification result via a result-scoped token
 *   + email confirmation (Flow B). Works whether the visitor is logged in or not.
 *
 * Security:
 *   - Token hash (HMAC-SHA256) using RESULT_TOKEN_PEPPER
 *   - No plaintext emails stored (hash only)
 *   - Short post-validation window (30 minutes)
 * ============================================================
 */

require_once dirname(__DIR__) . '/auth/session_helpers.php';
require_once dirname(__DIR__) . '/support/db.php';
require_once dirname(__DIR__) . '/views/render.php';

if (!function_exists('result_access_controller')) {
    function result_access_controller(string $token): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $token = trim($token);
        if ($token === '' || strlen($token) < 16) {
            pf_result_link_error('invalid_link', 404);
            return;
        }

        $pepper = (string)(getenv('RESULT_TOKEN_PEPPER') ?: '');
        if ($pepper === '') {
            error_log('RESULT_TOKEN_PEPPER missing.');
            pf_result_link_error('server_config', 500);
            return;
        }

        try {
            $pdo = pf_db();
        } catch (Throwable $e) {
            pf_result_link_error('server_error', 500);
            return;
        }

        $tokenHash = hash_hmac('sha256', $token, $pepper);

        try {
            $stmt = $pdo->prepare(
                'SELECT id, user_id, check_id, recipient_email_hash, expires_at, validated_at
                 FROM result_access_tokens
                 WHERE token_hash = :th AND expires_at > NOW()
                 LIMIT 1'
            );
            $stmt->execute([':th' => $tokenHash]);
            $rec = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('result_access_controller select failed: ' . $e->getMessage());
            pf_result_link_error('server_error', 500);
            return;
        }

        if (!$rec) {
            pf_result_link_error('expired_or_invalid', 404);
            return;
        }

        $tokenRowId    = (int)$rec['id'];
        $userId        = (int)$rec['user_id'];
        $checkId       = (int)$rec['check_id'];
        $recipientHash = (string)$rec['recipient_email_hash'];
        $validatedAt   = (string)($rec['validated_at'] ?? '');

        // Already validated -> short magic window
        if ($validatedAt !== '') {
            $validatedTs = strtotime($validatedAt);
            if ($validatedTs === false || (time() - $validatedTs) > (30 * 60)) {
                pf_result_link_error('expired_validated', 403);
                return;
            }

            if (pf_result_access_login_user($pdo, $userId)) {
                pf_redirect('/clarifications/view?id=' . $checkId);
                return;
            }

            pf_result_link_error('server_error', 500);
            return;
        }

        // Validate email on POST
        if ($method === 'POST') {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                pf_result_link_error('wrong_email', 403);
                return;
            }

            $enteredHash = hash_hmac('sha256', $email, $pepper);
            if (!hash_equals($recipientHash, $enteredHash)) {
                pf_result_link_error('wrong_email', 403);
                return;
            }

            try {
                $upd = $pdo->prepare(
                    'UPDATE result_access_tokens
                     SET validated_at = NOW()
                     WHERE id = :id AND validated_at IS NULL
                     LIMIT 1'
                );
                $upd->execute([':id' => $tokenRowId]);
            } catch (Throwable $e) {
                error_log('result_access_controller update failed: ' . $e->getMessage());
                pf_result_link_error('server_error', 500);
                return;
            }

            if (pf_result_access_login_user($pdo, $userId)) {
                pf_redirect('/clarifications/view?id=' . $checkId);
                return;
            }

            pf_result_link_error('server_error', 500);
            return;
        }

        // Render confirm form
        ob_start();
        $vm = ['token' => $token, 'errors' => [], 'oldEmail' => ''];
        require dirname(__DIR__) . '/views/results/confirm_email.php';
        $inner = (string)ob_get_clean();

        pf_render_shell('Confirm email address', $inner);
    }
}

if (!function_exists('pf_result_link_error')) {
    function pf_result_link_error(string $codeKey, int $httpCode = 400): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        http_response_code($httpCode);

        $payload = [
            'emoji' => '🤔',
            'title' => 'Oops — something went wrong',
            'subtitle' => 'We couldn’t open that result link.',
            'list' => [],
            'actions' => [['href' => '/login', 'label' => 'Return to login', 'class' => 'pf-btn pf-btn-primary']],
        ];

        switch ($codeKey) {
            case 'invalid_link':
                $payload['emoji'] = '🔗';
                $payload['title'] = 'That link isn’t valid';
                break;
            case 'expired_or_invalid':
                $payload['emoji'] = '⏳';
                $payload['title'] = 'That link has expired';
                break;
            case 'expired_validated':
                $payload['emoji'] = '⏱️';
                $payload['title'] = 'That link has timed out';
                break;
            case 'wrong_email':
                $payload['emoji'] = '✉️';
                $payload['title'] = 'Email address didn’t match';
                break;
            case 'server_config':
                $payload['emoji'] = '🛠️';
                $payload['title'] = 'Server configuration issue';
                break;
            default:
                break;
        }

        ob_start();
        $vm = $payload;
        require dirname(__DIR__) . '/views/errors/404.php';
        $inner = (string)ob_get_clean();

        pf_render_shell('Oops', $inner);
    }
}

if (!function_exists('pf_result_access_login_user')) {
    function pf_result_access_login_user(PDO $pdo, int $userId): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT id, email FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($u)) { return false; }

            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            @session_regenerate_id(true);
            pf_session_login((int)$u['id'], (string)$u['email']);
            return true;
        } catch (Throwable $e) {
            error_log('pf_result_access_login_user failed: ' . $e->getMessage());
            return false;
        }
    }
}
