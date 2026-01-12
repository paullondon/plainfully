-- ============================================================
-- Plainfully: Trace / Debug Timeline + Ingestion Columns
-- ============================================================
-- Apply in this order.
-- Safe to run once. Review before applying in prod.
-- ============================================================

/* 1) inbound_queue: add trace_id + ingestion metadata columns */
ALTER TABLE inbound_queue
  ADD COLUMN trace_id CHAR(36) NULL AFTER id,
  ADD COLUMN has_attachments TINYINT(1) NOT NULL DEFAULT 0 AFTER raw_is_html,
  ADD COLUMN ingestion_json JSON NULL AFTER has_attachments;

CREATE INDEX idx_inbound_trace ON inbound_queue(trace_id);

/* NOTE:
   Your schema already uses British spelling: normalised_text.
   The patched code assumes:
     - inbound_queue.normalised_text (LONGTEXT)
     - inbound_queue.truncated_text (TEXT)
*/

/* 2) trace_events table (TTL-based) */
CREATE TABLE IF NOT EXISTS trace_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  trace_id CHAR(36) NOT NULL,

  stage ENUM('ingest','attachments','prep','ai','output','cleanup') NOT NULL,
  level ENUM('info','warn','error') NOT NULL DEFAULT 'info',
  alert TINYINT(1) NOT NULL DEFAULT 0,

  action VARCHAR(64) NOT NULL,
  summary VARCHAR(255) NOT NULL,

  data_json JSON NULL,

  emailed_at DATETIME NULL,
  expires_at DATETIME NOT NULL,

  PRIMARY KEY (id),
  KEY idx_trace (trace_id, created_at),
  KEY idx_alert (alert, emailed_at, created_at),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
