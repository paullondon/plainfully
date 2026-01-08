<?php declare(strict_types=1);

/**
 * Global helper functions for Plainfully
 *  - Redirects
 *  - Email normalisation
 *  - Token generation
 *  - Clarification normalisation + hashing
 *  - Plan limits / duplicate detection
 *
 * Notes:
 * - Keep helpers side-effect free where possible.
 * - Fail fast internally; global bootstrap renders user-safe output.
 */

use Throwable;

if (!function_exists('pf_redirect')) {
    /**
     * Safe redirect helper.
     */
    function pf_redirect(string $path, int $status = 302): never
    {
        if (headers_sent()) {
            // Cannot safely redirect; hard stop (bootstrap will handle if uncaught elsewhere).
            throw new RuntimeException('Headers already sent; cannot redirect.');
        }

        // Basic hardening: ensure it's a local path (prevents open redirects).
        $path = trim($path);
        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $path = '/';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        header('Location: ' . $path, true, $status);
        exit;
    }
}

if (!function_exists('pf_normalise_email')) {
    /**
     * Normalise and validate an email address.
     */
    function pf_normalise_email(string $email): ?string
    {
        $email = trim($email);

        // Use mb_strtolower if available, else fallback
        $email = function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }
}

if (!function_exists('pf_generate_magic_token')) {
    /**
     * Generate secure random magic-link token.
