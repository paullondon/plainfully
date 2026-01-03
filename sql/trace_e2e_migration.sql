-- ============================================================
-- Plainfully: Trace Timeline (E2E) + Viewer
-- ============================================================
-- Adds:
--   - inbound_queue.trace_id (VARCHAR 36)
--   - inbound_queue.has_attachments (TINYINT)
--   - inbound_queue.ingestion_json (LONGTEXT)
-- Creates:
--   - trace_events (append-only, auto-cleaned by worker)
-- ============================================================

ALTER TABLE inbound_queue
  ADD COLUMN trace_id VARCHAR(36) NULL AFTER id;

ALTER TABLE inbound_queue
  ADD COLUMN has_attachments TINYINT(1) NOT NULL DEFAULT 0 AFTER raw_is_html;

ALTER TABLE inbound_queue
  ADD COLUMN ingestion_json LONGTEXT NULL AFTER has_attachments;

CREATE INDEX idx_inbound_trace_id ON inbound_queue(trace_id);

CREATE TABLE IF NOT EXISTS trace_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  trace_id VARCHAR(36) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  level ENUM('debug','info','warn','error') NOT NULL DEFAULT 'info',
  stage VARCHAR(64) NOT NULL,
  event VARCHAR(64) NOT NULL,
  message VARCHAR(255) NOT NULL,
  meta_json LONGTEXT NULL,
  queue_id BIGINT UNSIGNED NULL,
  check_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_trace_time (trace_id, created_at),
  KEY idx_level_time (level, created_at),
  KEY idx_queue (queue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
