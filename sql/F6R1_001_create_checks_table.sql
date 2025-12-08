-- sql/0006_create_checks_table.sql
-- Plainfully – Checks table for multi-channel CheckEngine
-- IMPORTANT: No raw user content is stored, only summaries / AI result JSON.

CREATE TABLE IF NOT EXISTS checks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    channel VARCHAR(32) NOT NULL,              -- 'web', 'email', 'sms', 'whatsapp', 'api', etc.
    source_identifier VARCHAR(191) NOT NULL,   -- email, phone number, provider user ID, etc.
    content_type VARCHAR(64) NOT NULL,         -- 'text/plain', 'text/html', 'email/rfc822', etc.
    ai_result_json JSON NOT NULL,              -- full AI result (input capsule, flags, metadata)
    short_summary VARCHAR(255) NOT NULL,       -- brief human summary of the checked content
    is_scam TINYINT(1) NOT NULL DEFAULT 0,     -- 1 = likely scam / fraud
    is_paid TINYINT(1) NOT NULL DEFAULT 0,     -- 1 = processed under paid plan
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_checks_user_id (user_id),
    KEY idx_checks_channel (channel),
    KEY idx_checks_source_identifier (source_identifier),
    CONSTRAINT fk_checks_user_id
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
