-- plainfully: billing + plans (mvp)
-- safe to run multiple times (uses IF NOT EXISTS where possible)

CREATE TABLE IF NOT EXISTS plans (
    code            VARCHAR(32)  NOT NULL PRIMARY KEY,
    name            VARCHAR(64)  NOT NULL,
    is_paid         TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO plans (code, name, is_paid) VALUES
('free', 'Free', 0),
('unlimited', 'Unlimited', 1);

CREATE TABLE IF NOT EXISTS billing_customers (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id              BIGINT UNSIGNED NOT NULL,
    provider             VARCHAR(32) NOT NULL DEFAULT 'stripe',
    provider_customer_id VARCHAR(128) NOT NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bc_provider_customer (provider, provider_customer_id),
    UNIQUE KEY uq_bc_user_provider (user_id, provider),
    CONSTRAINT fk_bc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscriptions (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                  BIGINT UNSIGNED NOT NULL,
    plan_code                VARCHAR(32) NOT NULL DEFAULT 'free',
    provider                 VARCHAR(32) NOT NULL DEFAULT 'stripe',
    provider_subscription_id VARCHAR(128) NULL,
    status                   VARCHAR(32) NOT NULL DEFAULT 'active',
    current_period_start     DATETIME NULL,
    current_period_end       DATETIME NULL,
    cancel_at_period_end     TINYINT(1) NOT NULL DEFAULT 0,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sub_user (user_id),
    KEY idx_sub_plan_status (plan_code, status),
    CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_sub_plan FOREIGN KEY (plan_code) REFERENCES plans(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO subscriptions (user_id, plan_code, status)
SELECT u.id, 'free', 'active'
FROM users u;
