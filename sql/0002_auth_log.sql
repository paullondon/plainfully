-- 0002_auth_log.sql
-- Basic authentication event log for Plainfully

CREATE TABLE IF NOT EXISTS auth_events (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NULL,
    email       VARCHAR(255) NULL,
    ip_address  VARCHAR(64)  NULL,
    user_agent  VARCHAR(255) NULL,
    event_type  VARCHAR(64)  NOT NULL,
    detail      TEXT         NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auth_events_user_id (user_id),
    INDEX idx_auth_events_event_type (event_type),
    INDEX idx_auth_events_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
