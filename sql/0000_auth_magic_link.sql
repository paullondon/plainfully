-- Users table: either email OR phone (at least one) must be present.
-- Login flow currently uses email only; phone is for future use.

CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(255) NULL UNIQUE,
    phone         VARCHAR(32)  NULL UNIQUE,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_users_email_or_phone
        CHECK (
            (email IS NOT NULL AND email <> '')
            OR
            (phone IS NOT NULL AND phone <> '')
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS magic_login_tokens (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        BIGINT UNSIGNED NOT NULL,
    token_hash     CHAR(64) NOT NULL,
    expires_at     DATETIME NOT NULL,
    used_at        DATETIME NULL,
    created_ip     VARCHAR(45) NULL,
    created_agent  VARCHAR(255) NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_magic_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    INDEX idx_token_hash (token_hash),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Rate limiting log (per endpoint / key)
-- ============================================

CREATE TABLE IF NOT EXISTS rate_limit_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    endpoint    VARCHAR(64) NOT NULL,
    key_type    ENUM('email', 'ip') NOT NULL,
    key_value   VARCHAR(255) NOT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rate_endpoint_key_time (endpoint, key_type, key_value, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
