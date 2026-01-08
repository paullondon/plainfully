<?php declare(strict_types=1);

/**
 * Authentication / security event logging for Plainfully
 *
 * Rule:
 * - Logging failures must never break the request (fail-open).
 */

use Throwable;

if (!function_exists('pf_log_auth_event')) {
    function pf_log_auth_event(
        string $eventType,
        ?int $userId = null,
        ?string $email = null,
        ?string $detail = null
    ): void {
        try {
            $pdo = pf_db();

            $ip    = $_SERVER['REMOTE_ADDR'] ?? null;
            $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $agentOut = null;
            if (is_string($agent) && $agent !== '') {
                $agentOut = function_exists('mb_substr') ? mb_substr($agent, 0, 255) : substr($agent, 0, 255);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO auth_events
                    (user_id, email, ip_address, user_agent, event_type, detail)
                 VALUES
                    (:user_id, :email, :ip, :agent, :event, :detail)'
            );

            $stmt->execute([
                ':user_id' => $userId,
                ':email'   => $email,
                ':ip'      => $ip,
                ':agent'   => $agentOut,
                ':event'   => $eventType,
                ':detail'  => $detail,
            ]);
        } catch (Throwable $e) {
            error_log('Auth log failed: ' . $e->getMessage());
        }
    }
}
