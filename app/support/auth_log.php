<?php declare(strict_types=1);

/**
 * Authentication / security event logging for Plainfully
 *
 * We never let logging failures break the request.
 */

function pf_log_auth_event(
    string $eventType,
    ?int $userId = null,
    ?string $email = null,
    ?string $detail = null
): void {
    try {
        $pdo = pf_db();

        $ip    = $_SERVER['REMOTE_ADDR']    ?? null;
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

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
            ':agent'   => $agent ? mb_substr($agent, 0, 255) : null,
            ':event'   => $eventType,
            ':detail'  => $detail,
        ]);
    } catch (Throwable $e) {
        // Fail-safe: never break auth flow because of logging.
        error_log('Auth log failed: ' . $e->getMessage());
    }
}
