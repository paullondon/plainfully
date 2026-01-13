-- Plainfully reboot schema (MVP)
-- This is intentionally minimal; we will expand once pipelines are wired.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- Inbound queue (single entry point)
CREATE TABLE IF NOT EXISTS pf_inbound_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  trace_id CHAR(36) NOT NULL,
  channel VARCHAR(64) NOT NULL,              -- e.g. email-clarify, email-scamcheck, web-clarify
  payload_json LONGTEXT NOT NULL,            -- normalized text package + metadata
  status VARCHAR(16) NOT NULL DEFAULT 'new', -- new|processing|done|dead
  attempts INT NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_status_available (status, available_at),
  KEY idx_trace (trace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbound queue (single output pipeline)
CREATE TABLE IF NOT EXISTS pf_outbound_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  trace_id CHAR(36) NOT NULL,
  channel VARCHAR(64) NOT NULL,              -- e.g. email
  payload_json LONGTEXT NOT NULL,            -- response package
  status VARCHAR(16) NOT NULL DEFAULT 'new', -- new|sending|sent|dead
  attempts INT NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_status_available (status, available_at),
  KEY idx_trace (trace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: queue locks (single worker safety if you run multiple)
CREATE TABLE IF NOT EXISTS pf_queue_locks (
  lock_name VARCHAR(64) NOT NULL,
  holder_id VARCHAR(64) NOT NULL,
  heartbeat_at DATETIME NOT NULL,
  PRIMARY KEY (lock_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
