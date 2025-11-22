<?php declare(strict_types=1);

/**
 * rate_limiter.php
 *
 * Generic rate limiting utilities for Plainfully.
 * Uses rate_limit_log table to enforce per-key limits.
 */

require_once __DIR__ . '/db.php';

/**
 * Check if a rate limit is exceeded for a given key + endpoint,
 * and optionally record the current attempt.
 *
 * @param string  $endpoint       Logical endpoint name (e.g. 'magic_link_request')
 * @param string  $keyType        'email' or 'ip'
 * @param string  $keyValue       Email address OR IP string
 * @param int     $maxAttempts    Max allowed in given window
 * @param int     $windowSeconds  Time window in seconds
 * @param bool    $recordNow      If true, insert a log row for this attempt
 *
 * @return bool   true if limit exceeded (request should be blocked)
 */
function pf_rate_limit_exceeded(
    string $endpoint,
    string $keyType,
    string $keyValue,
    int $maxAttempts,
    int $windowSeconds,
    bool $recordNow = true
): bool {
    $keyValue = trim($keyValue);
    if ($keyValue === '') {
        // No key = no rate limit; caller should avoid this where possible.
        return false;
    }

    $pdo = pf_db();

    $windowStart = (new DateTimeImmutable("-{$windowSeconds} seconds"))
        ->format('Y-m-d H:i:s');

    // Count recent attempts for this key/endpoint
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS cnt
         FROM rate_limit_log
         WHERE endpoint = :endpoint
           AND key_type = :key_type
           AND key_value = :key_value
           AND occurred_at >= :window_start'
    );
    $stmt->execute([
        ':endpoint'      => $endpoint,
        ':key_type'      => $keyType,
        ':key_value'     => $keyValue,
        ':window_start'  => $windowStart,
    ]);

    $row       = $stmt->fetch();
    $attempts  = $row ? (int)$row['cnt'] : 0;
    $exceeded  = $attempts >= $maxAttempts;

    // Record this attempt regardless, so we can see abuse patterns.
    if ($recordNow) {
        $insert = $pdo->prepare(
            'INSERT INTO rate_limit_log (endpoint, key_type, key_value)
             VALUES (:endpoint, :key_type, :key_value)'
        );
        $insert->execute([
            ':endpoint'  => $endpoint,
            ':key_type'  => $keyType,
            ':key_value' => $keyValue,
        ]);
    }

    return $exceeded;
}

/**
 * Convenience guard for the magic-link request endpoint.
 * Applies both per-email and per-IP limits in one go.
 *
 * @return bool true if blocked by any rule
 */
function pf_rate_limit_magic_link(?string $email, ?string $ip): bool
{
    $endpoint = 'magic_link_request';

    $email = $email !== null ? trim(mb_strtolower($email)) : '';
    $ip    = $ip   !== null ? trim($ip) : '';

    // Tunable numbers:
    // - max 5 requests per email per 15 minutes
    // - max 20 requests per IP per 15 minutes
    $windowSeconds        = 15 * 60;
    $maxPerEmail          = 5;
    $maxPerIp             = 20;

    // First, check email-based limit (if we have an email)
    if ($email !== '') {
        if (pf_rate_limit_exceeded(
            $endpoint,
            'email',
            $email,
            $maxPerEmail,
            $windowSeconds,
            true // record this attempt
        )) {
            return true;
        }
    }

    // Then IP-based limit (if we have an IP)
    if ($ip !== '') {
        if (pf_rate_limit_exceeded(
            $endpoint,
            'ip',
            $ip,
            $maxPerIp,
            $windowSeconds,
            true // record this attempt
        )) {
            return true;
        }
    }

    return false;
}
