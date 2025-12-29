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
 * Change history:
 *   - 2025-12-28 16:44:40Z  Initial MVP implementation
 *   - 2025-12-29 17:15:00Z  Remove validated_expires_at dependency; enforce 30m via validated_at
 *
 * Notes:
 *   - Stores NO plaintext email; hashes only.
 *   - Token hashing uses RESULT_TOKEN_PEPPER env var (required).
 *   - Fail-closed (friendly): invalid/expired token shows an error page + login button.
 * ============================================================
 */

if (!function_exists('result_access_controller')) {
    function result_access_controller(string $token): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $token = trim($token);
        if ($token === '' || strlen($token) < 16) {
            pf_result_link_error('That link is not valid.', 404);
            return;
        }

        $pepper = (string)(getenv('RESULT_TOKEN_PEPPER') ?: '');
        if ($pepper === '') {
            error_log('RESULT_TOKEN_PEPPER missing (Flow B).');
            pf_result_link_error('Something went wrong (server configuration).', 500);
            return;
        }

        $pdo = pf_db();
        if (!($pdo instanceof PDO)) {
            pf_result_link_error('Something went wrong. Please try again.', 500);
            return;
        }

        $tokenHash = hash_hmac('sha256', $token, $pepper);

        // NOTE: Do NOT reference validated_expires_at (not present in your schema).
        $stmt = $pdo->prepare("
            SELECT id, user_id, check_id, recipient_email_hash, expires_at, validated_at
            FROM result_access_tokens
            WHERE token_hash = :th
              AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([':th' => $tokenHash]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rec) {
            pf_result_link_error('This link has expired or is not valid.', 404);
            return;
        }

        $tokenRowId    = (int)($rec['id'] ?? 0);
        $userId        = (int)($rec['user_id'] ?? 0);
        $checkId       = (int)($rec['check_id'] ?? 0);
        $recipientHash = (string)($rec['recipient_email_hash'] ?? '');
        $validatedAt   = (string)($rec['validated_at'] ?? '');

        if ($tokenRowId <= 0 || $userId <= 0 || $checkId <= 0 || $recipientHash === '') {
            pf_result_link_error('Something went wrong. Please try again.', 500);
            return;
        }

        // If already validated, allow only for 30 minutes since validated_at.
        if ($validatedAt !== '') {
            try {
                $validatedTs = strtotime($validatedAt . ' UTC');
                if ($validatedTs === false) {
                    pf_result_link_error('This link has expired. Please log in to view your results.', 403);
                    return;
                }

                $ageSeconds = time() - $validatedTs;
                if ($ageSeconds > (30 * 60)) {
                    pf_result_link_error('This link has expired. Please log in to view your results.', 403);
                    return;
                }

                if (pf_result_access_login_user($pdo, $userId) === true) {
                    pf_redirect('/clarifications/view?id=' . $checkId);
                    return;
                }

                pf_result_link_error('Something went wrong. Please log in again.', 500);
                return;

            } catch (Throwable $e) {
                error_log('result_access_controller: validated check failed: ' . $e->getMessage());
                pf_result_link_error('Something went wrong. Please log in again.', 500);
                return;
            }
        }

        // Not validated yet -> ask to confirm email, then validate+login.
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
                        $upd->execute([':id' => $tokenRowId]);

                        if (pf_result_access_login_user($pdo, $userId) === true) {
                            pf_redirect('/clarifications/view?id=' . $checkId);
                            return;
                        }

                        pf_result_link_error('Something went wrong. Please log in and try again.', 500);
                        return;

                    } catch (Throwable $e) {
                        error_log('result_access_controller: validation update failed: ' . $e->getMessage());
                        pf_result_link_error('Something went wrong. Please log in and try again.', 500);
                        return;
                    }
                }

                // Wrong email -> friendly error page (reduces brute-force loops)
                pf_result_link_error('That email address does not match this link.', 403);
                return;
            }
        }

        // Render confirm form
        $viewData = [
            'token'    => $token,
            'errors'   => $errors,
            'oldEmail' => $oldEmail,
        ];

        ob_start();
        $vm = $viewData;
        require dirname(__DIR__) . '/views/results/confirm_email.php';
        $inner = ob_get_clean();

        pf_render_shell('Confirm email address', $inner);
    }
}

if (!function_exists('pf_result_link_error')) {
    /**
     * Friendly error page for Flow B failures.
     */
    function pf_result_link_error(string $message, int $code = 400): void
    {
        http_response_code($code);

        $vm = [
            'message' => $message,
            'code'    => $code,
        ];

        ob_start();
        require dirname(__DIR__) . '/views/results/link_error.php';
        $inner = ob_get_clean();

        pf_render_shell('Oops!', $inner);
    }
}

if (!function_exists('pf_result_access_login_user')) {
    function pf_result_access_login_user(PDO $pdo, int $userId): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $userId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$u) { return false; }

            $email = (string)($u['email'] ?? '');
            $id    = (int)($u['id'] ?? 0);

            if ($id <= 0 || $email === '') { return false; }

            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }

            if (function_exists('session_regenerate_id')) {
                @session_regenerate_id(true);
            }

            $_SESSION['user_id']    = $id;
            $_SESSION['user_email'] = $email;

            return true;

        } catch (Throwable $e) {
            error_log('pf_result_access_login_user failed: ' . $e->getMessage());
            return false;
        }
    }
}
