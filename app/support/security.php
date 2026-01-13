<?php declare(strict_types=1);

/**
 * security.php
 *
 * Central place for security headers and request protections.
 */

function pf_send_security_headers(): void
{
    // Sensible defaults for MVP. Tighten as features land.
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    // CSP kept minimal for MVP to avoid breaking; update once assets settle.
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'");
}

function pf_require_post(): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') {
        pf_http_error(405, 'Method Not Allowed');
    }
}
