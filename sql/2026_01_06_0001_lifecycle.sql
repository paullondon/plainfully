-- 2026_01_06_0001_lifecycle.sql
-- Plainfully Lifecycle Engine (cron-driven)
-- Safe-by-design: idempotent flags, minimal PII, prepared statements in app layer

-- 1) Users: minimal columns (skip if you already have similar)
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- 2) Token table (if you already have a token system, adapt queries and skip this table)
CREATE TABLE IF NOT EXISTS user_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  type ENUM('free','day','unlimited') NOT NULL,
  source ENUM('system','sms','email','web','direct_debit') NOT NULL DEFAULT 'system',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_user_tokens_user (user_id),
  KEY idx_user_tokens_type (type),
  KEY idx_user_tokens_expires (expires_at),
  CONSTRAINT fk_user_tokens_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Lifecycle flags (send-once markers)
CREATE TABLE IF NOT EXISTS user_lifecycle_flags (
  user_id BIGINT UNSIGNED NOT NULL,
  welcome_sent_at DATETIME NULL,
  tips_sent_at DATETIME NULL,
  underuse_prompt_sent_at DATETIME NULL,
  feedback_sent_at DATETIME NULL,
  single_day_followup_sent_at DATETIME NULL,
  dd_checkin_sent_at DATETIME NULL,
  dd_monthly_review_sent_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_lifecycle_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Feedback capture
CREATE TABLE IF NOT EXISTS user_feedback (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('day20','single_day','dd_checkin','monthly_review') NOT NULL,
  rating ENUM('up','meh','down') NOT NULL,
  comment TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_feedback_user (user_id),
  KEY idx_feedback_created (created_at),
  CONSTRAINT fk_feedback_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

