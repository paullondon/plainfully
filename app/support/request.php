<?php declare(strict_types=1);

/**
 * Request helper functions for Plainfully
 */

function pf_request_method(): string
{
    return $_SERVER['REQUEST_METHOD'] ?? 'GET';
}

function pf_request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    // Normalise trailing slash (except root)
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }
    return $path;
}
