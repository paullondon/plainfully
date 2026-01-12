-- ============================================================
-- Plainfully File Info
-- ============================================================
-- File: sql/migrations/2025-12-28_01_create_result_access_tokens.sql
-- Purpose:
--   Creates result_access_tokens for result-link confirmation
--   (token -> email confirm -> login -> redirect to result)
--
-- Change history:
--   - 2025-12-28 16:44:40Z  Initial MVP migration
-- ============================================================

CREATE TABLE IF NOT EXISTS result_access_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  check_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  recipient_email_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  validated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_user_id (user_id),
  KEY idx_check_id (check_id),
  CONSTRAINT fk_result_access_tokens_user_id
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_result_access_tokens_check_id
    FOREIGN KEY (check_id) REFERENCES checks (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
