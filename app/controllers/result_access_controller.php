<?php declare(strict_types=1);

use PDO;
use Throwable;

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/controllers/result_access_controller.php
 * Purpose:
 *   Handles guest access to a single clarification result via a
 *   result-scoped token + email confirmation, then logs the user
 *   in (Flow B) and redirects to /clarifications/view?id=...
 *
 * Endpoints:
 *   GET  /r/{token}  -> show confirm page (or auto-redirect if validated)
 *   POST /r/{token}  -> verify email matches token recipient, then login
 *
 * Data:
 *   Uses table: result_access_tokens
 *     - token_hash (HMAC-SHA256)
 *     - recipient_email_hash (HMAC-SHA256 of lower(trim(email)))
 *     - user_id, check_id, expires_at, validated_at
 *
 * Change history:
 *   - 2025-12-28 16:44:40Z  Initial MVP implementation (result token + email confirm + login)
 *
 * Notes:
 *   - Stores NO plaintext email; hashes only.
 *   - Token hashing uses RESULT_TOKEN_PEPPER env var (recommended).
 *   - Fail-closed: invalid/expired token returns 404-like page.
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

        $pdo = pf_db();
        if (!($pdo instanceof PDO)) {
            http_response_code(500);
            pf_render_shell('Error', '<p>Something went wrong. Please try again.</p>');
            return;
        }

        $pepper = (string)(getenv('RESULT_TOKEN_PEPPER') ?: '');
        $tokenHash = hash_hmac('sha256', $token, $pepper);

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
            http_response_code(404);
            pf_render_shell('Not found', '<p>This link has expired or is not valid.</p>');
            return;
        }

        $userId  = (int)($rec['user_id'] ?? 0);
        $checkId = (int)($rec['check_id'] ?? 0);
        $recipientHash = (string)($rec['recipient_email_hash'] ?? '');
        $validatedAt   = $rec['validated_at'] ?? null;

        if ($userId <= 0 || $checkId <= 0 || $recipientHash === '') {
            http_response_code(500);
            pf_render_shell('Error', '<p>Something went wrong. Please try again.</p>');
            return;
        }

        // Already validated => login + redirect
        if ($validatedAt !== null && $validatedAt !== '') {
            if (pf_result_access_login_user($pdo, $userId) === true) {
                pf_redirect('/clarifications/view?id=' . $checkId);
                return;
            }

            http_response_code(500);
            pf_render_shell('Error', '<p>Something went wrong. Please try again.</p>');
            return;
        }

        $errors = [];
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
                        $upd->execute([':id' => (int)$rec['id']]);

                        if (pf_result_access_login_user($pdo, $userId) === true) {
                            pf_redirect('/clarifications/view?id=' . $checkId);
                            return;
                        }

                        $errors[] = 'Something went wrong. Please try again.';
                    } catch (Throwable $e) {
                        error_log('result_access_controller: validation update failed: ' . $e->getMessage());
                        $errors[] = 'Something went wrong. Please try again.';
                    }
                } else {
                    $errors[] = 'That email address does not match this link.';
                }
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
        $inner = ob_get_clean();

        pf_render_shell('Confirm email address', $inner);
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

            $_SESSION['user_id'] = $id;
            $_SESSION['user_email'] = $email;

            return true;
        } catch (Throwable $e) {
            error_log('pf_result_access_login_user failed: ' . $e->getMessage());
            return false;
        }
    }
}
