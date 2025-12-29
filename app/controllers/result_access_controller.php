<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/controllers/result_access_controller.php
 * Purpose:
 *   Handles guest OR logged-in access to a single clarification
 *   result via a result-scoped token + email confirmation (Flow B).
 *
 * Endpoints:
 *   GET  /r/{token}  -> show confirm page (or auto-redirect if validated)
 *   POST /r/{token}  -> verify email matches token recipient, then login
 *
 * Failure UX:
 *   Any failure renders a friendly error page with a "Return to login" button.
 *
 * Data:
 *   Table: result_access_tokens
 *     - token_hash (HMAC-SHA256)
 *     - recipient_email_hash (HMAC-SHA256 of lower(trim(email)))
 *     - user_id, check_id, expires_at, validated_at, validated_expires_at
 *
 * Security notes:
 *   - Stores NO plaintext email; hashes only.
 *   - Token hashing uses RESULT_TOKEN_PEPPER env var.
 *   - Uses hash_equals for timing-safe compares.
 *
 * Change history:
 *   - 2025-12-28: Force result redirect + add session return_to fallback + remove noisy use statements
 *   - 2025-12-29  Add friendly failure page (invalid/expired/mismatch).
 * ============================================================
 */

if (!function_exists('result_access_controller')) {
    function result_access_controller(string $token): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $token = trim($token);
        if ($token === '' || strlen($token) < 16) {
            pf_result_access_fail('That link is not valid.', 'The link looks malformed or incomplete.');
            return;
        }

        // DB helper (project standard)
        $pdo = pf_db();
        if (!($pdo instanceof PDO)) {
            pf_result_access_fail('Oops! Something went wrong.', 'We could not connect to the database. Please try again.');
            return;
        }

        $pepper = (string)(getenv('RESULT_TOKEN_PEPPER') ?: '');
        if ($pepper === '') {
            // Fail-closed (misconfigured server)
            pf_result_access_fail('Oops! Something went wrong.', 'Result-token security is not configured on the server.');
            return;
        }

        $tokenHash = hash_hmac('sha256', $token, $pepper);

        // NOTE: expires_at is the 24h "claim window"
        $stmt = $pdo->prepare("
            SELECT id, user_id, check_id, recipient_email_hash, expires_at, validated_at, validated_expires_at
            FROM result_access_tokens
            WHERE token_hash = :th
              AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([':th' => $tokenHash]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rec) {
            pf_result_access_fail('Link expired.', 'This link has expired or is not valid anymore.');
            return;
        }

        $rowId         = (int)($rec['id'] ?? 0);
        $userId        = (int)($rec['user_id'] ?? 0);
        $checkId       = (int)($rec['check_id'] ?? 0);
        $recipientHash = (string)($rec['recipient_email_hash'] ?? '');
        $validatedAt   = (string)($rec['validated_at'] ?? '');
        $validatedExp  = (string)($rec['validated_expires_at'] ?? '');

        if ($rowId <= 0 || $userId <= 0 || $checkId <= 0 || $recipientHash === '') {
            pf_result_access_fail('Oops! Something went wrong.', 'This link is missing required data. Please request a fresh link.');
            return;
        }

        // If already validated: treat as a short-lived "magic session grant"
        if ($validatedAt !== '') {
            // validated_expires_at must exist and be in the future
            if ($validatedExp === '') {
                pf_result_access_fail('Link expired.', 'This link has already been used and is no longer valid. Please log in.');
                return;
            }

            $expStmt = $pdo->prepare("SELECT (validated_expires_at > NOW()) AS ok FROM result_access_tokens WHERE id = :id LIMIT 1");
            $expStmt->execute([':id' => $rowId]);
            $ok = (int)($expStmt->fetchColumn() ?: 0);

            if ($ok !== 1) {
                pf_result_access_fail('Link expired.', 'This link has timed out. Please log in to view your clarifications.');
                return;
            }

            if (pf_result_access_login_user($pdo, $userId) === true) {
                pf_redirect('/clarifications/view?id=' . $checkId);
                return;
            }

            pf_result_access_fail('Oops! Something went wrong.', 'We could not sign you in. Please try logging in normally.');
            return;
        }

        // Not validated yet: show confirm form, or process POST
        $errors   = [];
        $oldEmail = '';

        if ($method === 'POST') {
            $oldEmail = strtolower(trim((string)($_POST['email'] ?? '')));

            if ($oldEmail === '' || !filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) {
                // Invalid format is user-fixable
                $errors[] = 'Please enter a valid email address.';
            } else {
                $enteredHash = hash_hmac('sha256', $oldEmail, $pepper);

                if (hash_equals($recipientHash, $enteredHash)) {
                    // Mark validated and start the 30 minute window
                    try {
                        $upd = $pdo->prepare("
                            UPDATE result_access_tokens
                            SET validated_at = NOW(),
                                validated_expires_at = (NOW() + INTERVAL 30 MINUTE)
                            WHERE id = :id
                              AND validated_at IS NULL
                            LIMIT 1
                        ");
                        $upd->execute([':id' => $rowId]);

                        if (pf_result_access_login_user($pdo, $userId) === true) {
                            pf_redirect('/clarifications/view?id=' . $checkId);
                            return;
                        }

                        pf_result_access_fail('Oops! Something went wrong.', 'We could not sign you in. Please try logging in normally.');
                        return;

                    } catch (Throwable $e) {
                        error_log('result_access_controller: validation update failed: ' . $e->getMessage());
                        pf_result_access_fail('Oops! Something went wrong.', 'We could not validate this link. Please try again.');
                        return;
                    }
                }

                // Mismatch email -> login page (avoid endless guessing)
                pf_result_access_fail('Email does not match.', 'That email address does not match this link. Please log in.');
                return;
            }
        }

        $viewData = [
            'token'    => $token,
            'errors'   => $errors,
            'oldEmail' => $oldEmail,
        ];

        ob_start();
        $vm = $viewData;
        require dirname(__DIR__) . '/views/results/confirm_email.php';
        $inner = (string)ob_get_clean();

        pf_render_shell('Confirm email address', $inner);
    }
}

if (!function_exists('pf_result_access_fail')) {
    /**
     * Friendly failure page used by /r/{token}.
     * Always includes a "Return to login" button.
     */
    function pf_result_access_fail(string $title, string $message): void
    {
        http_response_code(200);

        $data = [
            'title'    => $title,
            'message'  => $message,
            'loginUrl' => '/login',
        ];

        ob_start();
        require dirname(__DIR__) . '/views/results/link_error.php';
        $inner = (string)ob_get_clean();

        pf_render_shell('Result link', $inner);
    }
}

if (!function_exists('pf_result_access_login_user')) {
    /**
     * Logs a user into the session by user id (Flow B).
     * Uses DB lookup to fetch canonical email.
     */
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

            $_SESSION['user_id'] = $id;
            $_SESSION['user_email'] = $email;

            return true;
        } catch (Throwable $e) {
            error_log('pf_result_access_login_user failed: ' . $e->getMessage());
            return false;
        }
    }
}
