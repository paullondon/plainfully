<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully Global Helpers
 * ============================================================
 * File: app/core/helpers.php
 * Purpose:
 *   A small set of globally useful helpers that are used across
 *   routes/controllers/features.
 *
 * Security principles:
 *   - Validate inputs (fail-closed where sensible)
 *   - Use cryptographically secure randomness for tokens
 * ============================================================
 */

/**
 * Redirect and end the request.
 */
if (!function_exists('pf_redirect')) {
    function pf_redirect(string $path, int $status = 302): never
    {
        header('Location: ' . $path, true, $status);
        exit;
    }
}

/**
 * Normalise and validate an email address.
 *
 * @return string|null Normalised email or null if invalid.
 */
if (!function_exists('pf_normalise_email')) {
    function pf_normalise_email(string $email): ?string
    {
        $email = trim(mb_strtolower($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return $email;
    }
}

/**
 * Generate a secure random token for magic links.
 *
 * - 32 bytes -> 64 hex chars
 */
if (!function_exists('pf_generate_magic_token')) {
    function pf_generate_magic_token(): string
    {
        return bin2hex(random_bytes(32));
    }
}
