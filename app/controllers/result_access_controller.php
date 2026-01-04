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
 * Endpoints:
 *   GET  /r/{token}  -> show confirm page (or auto-redirect if already validated)
 *   POST /r/{token}  -> verify email matches token recipient, then login+redirect
 *
 * Token lifetime rules (no extra DB columns required):
 *   - Token usable for up to 24 hours UNTIL validated (expires_at).
 *   - Once validated, it acts like a "magic token" for 30 minutes from validated_at.
 *
 * Data:
 *   Uses table: result_access_tokens
 *     - token_hash (HMAC-SHA256)
 *     - recipient_email_hash (HMAC-SHA256 of lower(trim(email)))
 *     - user_id, check_id, expires_at, validated_at
 *
 * Notes:
 *   - Stores NO plaintext email; hashes only.
 *   - RESULT_TOKEN_PEPPER env var is required.
 *   - Friendly fail-closed: shows adaptive error page (404.php) with a login button.
 * ============================================================
 */

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
            error_log('RESULT_TOKEN_PEPPER missing (Flow B).');
            pf_result_link_error('server_config', 500);
            return;
        }

        $pdo = pf_db();
        if (!($pdo instanceof \PDO)) {
            pf_result_link_error('server_error', 500);
            return;
        }

        $tokenHash = hash_hmac('sha256', $token, $pepper);

        try {
            $stmt = $pdo->prepare("
                SELECT id, user_id, check_id, recipient_email_hash, expires_at, validated_at
                FROM result_access_tokens
                WHERE token_hash = :th
                  AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([':th' => $tokenHash]);
            $rec = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('result_access_controller: select failed: ' . $e->getMessage());
            pf_result_link_error('server_error', 500);
            return;
        }

        if (!$rec) {
            pf_result_link_error('expired_or_invalid', 404);
            return;
        }

        $tokenRowId    = (int)($rec['id'] ?? 0);
        $userId        = (int)($rec['user_id'] ?? 0);
        $checkId       = (int)($rec['check_id'] ?? 0);
        $recipientHash = (string)($rec['recipient_email_hash'] ?? '');
        $validatedAt   = (string)($rec['validated_at'] ?? '');

        if ($tokenRowId <= 0 || $userId <= 0 || $checkId <= 0 || $recipientHash === '') {
            pf_result_link_error('server_error', 500);
            return;
        }

        // If already validated, allow only for 30 minutes since validated_at.
        if ($validatedAt !== '') {
            $validatedTs = strtotime($validatedAt);
            if ($validatedTs === false) {
                pf_result_link_error('expired_validated', 403);
                return;
            }

            $ageSeconds = time() - $validatedTs;
            if ($ageSeconds > (30 * 60)) {
                pf_result_link_error('expired_validated', 403);
                return;
            }

            if (pf_result_access_login_user($pdo, $userId) === true) {
                pf_redirect('/clarifications/view?id=' . $checkId);
                return;
            }

            pf_result_link_error('server_error', 500);
            return;
        }

        // Not validated yet -> ask to confirm email, then validate+login.
        if ($method === 'POST') {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                pf_result_link_error('wrong_email', 403);
                return;
            }

            $enteredHash = hash_hmac('sha256', $email, $pepper);

            // Wrong email -> stop loop + show friendly page.
            if (!hash_equals($recipientHash, $enteredHash)) {
                pf_result_link_error('wrong_email', 403);
                return;
            }

            // Correct email -> validate token (once) + login + redirect
            try {
                $upd = $pdo->prepare("
                    UPDATE result_access_tokens
                    SET validated_at = NOW()
                    WHERE id = :id
                      AND validated_at IS NULL
                    LIMIT 1
                ");
                $upd->execute([':id' => $tokenRowId]);
            } catch (\Throwable $e) {
                error_log('result_access_controller: validation update failed: ' . $e->getMessage());
                pf_result_link_error('server_error', 500);
                return;
            }

            if (pf_result_access_login_user($pdo, $userId) === true) {
                pf_redirect('/clarifications/view?id=' . $checkId);
                return;
            }

            pf_result_link_error('server_error', 500);
            return;
        }

        // Render confirm form (GET)
        $viewData = [
            'token'    => $token,
            'errors'   => [],
            'oldEmail' => '',
        ];

        ob_start();
        $vm = $viewData;
        require dirname(__DIR__) . '/views/results/confirm_email.php';
        $inner = (string)ob_get_clean();

        pf_render_shell('Confirm email address', $inner);
    }
}

if (!function_exists('pf_result_link_error')) {
    /**
     * Friendly Flow B error page using the single adaptive view: app/views/errors/404.php
     *
     * @param string $codeKey One of:
     *   invalid_link | expired_or_invalid | expired_validated | wrong_email | server_config | server_error
     */
    function pf_result_link_error(string $codeKey, int $httpCode = 400): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        http_response_code($httpCode);

        $isLoggedIn = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;

        // Default payload
        $payload = [
            'emoji' => '🤔',
            'title' => 'Oops — something went wrong',
            'subtitle' => 'We couldn’t open that result link.',
            'list' => [
                'If this link is old, it may have expired.',
                'If you typed an email address, make sure it matches the one this link was sent to.',
            ],
            'actions' => [
                ['href' => '/login', 'label' => 'Return to login', 'class' => 'pf-btn pf-btn-primary'],
            ],
        ];

        if ($isLoggedIn) {
            $payload['actions'][] = ['href' => '/dashboard', 'label' => 'Go to dashboard', 'class' => 'pf-btn pf-btn-secondary'];
        }

        switch ($codeKey) {
            case 'invalid_link':
                $payload['emoji'] = '🔗';
                $payload['title'] = 'That link isn’t valid';
                $payload['subtitle'] = 'Please use the exact link from your email.';
                break;

            case 'expired_or_invalid':
                $payload['emoji'] = '⏳';
                $payload['title'] = 'That link has expired';
                $payload['subtitle'] = 'For safety, result links only work for a limited time.';
                $payload['list'] = [
                    'Log in to view your past clarifications.',
                    'Or resend your message to receive a fresh link.',
                ];
                break;

            case 'expired_validated':
                $payload['emoji'] = '⏱️';
                $payload['title'] = 'That link has timed out';
                $payload['subtitle'] = 'After you confirm your email, the link only stays active for 30 minutes.';
                $payload['list'] = [
                    'Log in to view your past clarifications.',
                    'Or use a fresh link from your inbox.',
                ];
                break;

            case 'wrong_email':
                $payload['emoji'] = '✉️';
                $payload['title'] = 'Email address didn’t match';
                $payload['subtitle'] = 'For security, we can only open this result for the email it was sent to.';
                $payload['list'] = [
                    'Double-check the spelling.',
                    'Use the same email address that received the link.',
                ];
                break;

            case 'server_config':
                $payload['emoji'] = '🛠️';
                $payload['title'] = 'Server configuration issue';
                $payload['subtitle'] = 'This link flow isn’t configured correctly yet.';
                $payload['list'] = [
                    'Try again later.',
                    'If you’re the admin: check RESULT_TOKEN_PEPPER is set.',
                ];
                break;

            case 'server_error':
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

            $dbUserId = (int)($u['id'] ?? 0);
            $dbEmail  = (string)($u['email'] ?? '');

            if ($dbUserId <= 0 || $dbEmail === '') { return false; }

            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }

            if (function_exists('session_regenerate_id')) {
                @session_regenerate_id(true);
            }

            $_SESSION['user_id']    = $dbUserId;
            $_SESSION['user_email'] = $dbEmail;

            return true;

        } catch (\Throwable $e) {
            error_log('pf_result_access_login_user failed: ' . $e->getMessage());
            return false;
        }
    }
}