-- ============================================================
-- Plainfully N6
-- File: sql/migrations/2025_12_29_n6_indexes.sql
-- Purpose: add pragmatic indexes for token lookup + queue scanning
-- ============================================================

-- result_access_tokens fast lookup by token_hash + expiry checks
ALTER TABLE result_access_tokens
  ADD INDEX idx_result_access_tokens_token_hash (token_hash),
  ADD INDEX idx_result_access_tokens_expires_at (expires_at),
  ADD INDEX idx_result_access_tokens_validated_at (validated_at);

-- inbound_queue scanning by status/lock/attempts
ALTER TABLE inbound_queue
  ADD INDEX idx_inbound_queue_status_id (status, id),
  ADD INDEX idx_inbound_queue_lock (locked_at, status),
  ADD INDEX idx_inbound_queue_attempts (attempts, status);
