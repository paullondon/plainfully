<?php declare(strict_types=1);

/**
 * Request helpers for Plainfully
 *
 * - Normalised HTTP method
 * - Normalised request path
 */

if (!function_exists('pf_request_method')) {
    function pf_request_method(): string
    {
        return (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }
}

if (!function_exists('pf_request_path')) {
    function pf_request_path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // Normalise trailing slash (except root)
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
