<?php declare(strict_types=1);

/**
 * rate_limiter.php
 *
 * Generic rate limiting utilities for Plainfully.
 * Uses rate_limit_log table to enforce per-key limits.
 */

use DateTimeImmutable;

require_once __DIR__ . '/db.php';

if (!function_exists('pf_rate_limit_exceeded')) {
    /**
     * Check if a rate limit is exceeded for a given key + endpoint,
     * and optionally record the current attempt.
     *
     * @return bool true if limit exceeded (request should be blocked)
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

        $windowStart = (new DateTimeImmutable("-{$windowSeconds} seconds"))->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS cnt
             FROM rate_limit_log
             WHERE endpoint = :endpoint
               AND key_type = :key_type
               AND key_value = :key_value
               AND occurred_at >= :window_start'
        );

        $stmt->execute([
            ':endpoint'     => $endpoint,
            ':key_type'     => $keyType,
            ':key_value'    => $keyValue,
            ':window_start' => $windowStart,
        ]);

        $row      = $stmt->fetch();
        $attempts = $row ? (int)$row['cnt'] : 0;
        $exceeded = $attempts >= $maxAttempts;

        // Record attempt (even if exceeded) for abuse visibility.
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
}

if (!function_exists('pf_rate_limit_magic_link')) {
    /**
     * Convenience guard for the magic-link request endpoint.
     *
     * @return bool true if blocked by any rule
     */
    function pf_rate_limit_magic_link(?string $email, ?string $ip): bool
    {
        $endpoint = 'magic_link_request';

        $email = (string)($email ?? '');
        $email = function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email);
        $email = trim($email);

        $ip = trim((string)($ip ?? ''));

        // Tunables:
        $windowSeconds = 15 * 60;
        $maxPerEmail   = 5;
        $maxPerIp      = 20;

        if ($email !== '') {
            if (pf_rate_limit_exceeded($endpoint, 'email', $email, $maxPerEmail, $windowSeconds, true)) {
                return true;
            }
        }

        if ($ip !== '') {
            if (pf_rate_limit_exceeded($endpoint, 'ip', $ip, $maxPerIp, $windowSeconds, true)) {
                return true;
            }
        }

        return false;
    }
}
