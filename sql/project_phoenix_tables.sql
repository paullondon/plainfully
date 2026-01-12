-- =========================================================
-- Plainfully: Queue Tables (MVP, legacy-safe)
-- =========================================================

CREATE TABLE IF NOT EXISTS pf_inbound_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Channel routing
  source_channel VARCHAR(32) NOT NULL,         -- email | web | sms
  source_identifier VARCHAR(255) NULL,         -- email address or phone
  reply_channel VARCHAR(32) NOT NULL,          -- email | sms
  reply_to VARCHAR(255) NOT NULL,              -- email address or phone

  -- Mode
  mode VARCHAR(32) NOT NULL,                   -- clarify | scamcheck | generic

  -- Payload (store already-normalised text for safety)
  content_type VARCHAR(64) NOT NULL DEFAULT 'text/plain',
  content MEDIUMTEXT NOT NULL,

  -- Idempotency / dedupe
  content_hash CHAR(64) NOT NULL,              -- sha256 of normalised content + identifiers

  -- Processing state
  status VARCHAR(16) NOT NULL DEFAULT 'queued', -- queued|processing|done|failed|dead
  locked_at DATETIME NULL,
  locked_by VARCHAR(64) NULL,
  attempts INT NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  next_attempt_at DATETIME NULL,

  -- Traceability
  trace_id CHAR(36) NULL,

  PRIMARY KEY (id),
  KEY idx_status_created (status, created_at),
  KEY idx_next_attempt (next_attempt_at),
  UNIQUE KEY uq_content_hash (content_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pf_outbound_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Where this came from
  inbound_id BIGINT UNSIGNED NULL,
  trace_id CHAR(36) NULL,

  -- Delivery
  channel VARCHAR(32) NOT NULL,                -- email | sms
  to_addr VARCHAR(255) NOT NULL,
  subject VARCHAR(255) NULL,                   -- email only
  body_text MEDIUMTEXT NOT NULL,
  body_html MEDIUMTEXT NULL,                   -- email only

  -- Links to stored result (legacy-safe)
  result_url VARCHAR(512) NULL,
  result_check_id BIGINT UNSIGNED NULL,        -- if you store engine output in checks later

  -- Delivery state
  status VARCHAR(16) NOT NULL DEFAULT 'queued', -- queued|sending|sent|failed|dead
  locked_at DATETIME NULL,
  locked_by VARCHAR(64) NULL,
  attempts INT NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  next_attempt_at DATETIME NULL,
  sent_at DATETIME NULL,

  PRIMARY KEY (id),
  KEY idx_status_created (status, created_at),
  KEY idx_next_attempt (next_attempt_at),
  KEY idx_inbound (inbound_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Single-worker lock (optional but simple + robust)
CREATE TABLE IF NOT EXISTS pf_queue_locks (
  lock_name VARCHAR(64) NOT NULL,
  locked_at DATETIME NOT NULL,
  locked_by VARCHAR(64) NOT NULL,
  PRIMARY KEY (lock_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;