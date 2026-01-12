-- 0005_create_consultations.sql
-- Core consultation record (non-encrypted, minimal PII)

CREATE TABLE IF NOT EXISTS consultations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NULL COMMENT 'Nullable: guest / non-logged user',
    email_hash      VARBINARY(64) NULL COMMENT 'Hashed email for dedup/metrics; NO raw email here',
    status          VARCHAR(32) NOT NULL DEFAULT 'open',
    source          VARCHAR(32) NOT NULL DEFAULT 'web', -- web, api, webhook etc.
    created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    expires_at      DATETIME(6) NOT NULL COMMENT '28-day retention cutoff for this row and its children',

    PRIMARY KEY (id),
    KEY idx_consultations_user (user_id),
    KEY idx_consultations_expires (expires_at),
    KEY idx_consultations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
