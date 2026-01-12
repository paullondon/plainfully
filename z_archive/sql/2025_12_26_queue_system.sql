CREATE TABLE inbound_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Source identification (dedupe)
  source VARCHAR(20) NOT NULL DEFAULT 'email',           -- email/sms/whatsapp later
  mailbox VARCHAR(190) NOT NULL,                         -- e.g. 'INBOX'
  imap_uid BIGINT UNSIGNED NULL,                         -- UID from IMAP
  message_id VARCHAR(255) NULL,                          -- RFC Message-ID (if present)
  from_email VARCHAR(255) NOT NULL,
  to_email   VARCHAR(255) NULL,
  subject    VARCHAR(998) NULL,                          -- RFC allows long; keep safe
  received_at DATETIME NULL,

  -- Routing / mode
  mode ENUM('generic','clarify','scamcheck') NOT NULL DEFAULT 'generic',

  -- Raw payload (store exactly what you received)
  raw_body LONGTEXT NULL,
  raw_is_html TINYINT(1) NOT NULL DEFAULT 0,

  -- Stage outputs (cheaper derived versions)
  normalised_text LONGTEXT NULL,
  truncated_text  TEXT NULL,
  capsule_text    TEXT NULL,
  short_verdict   VARCHAR(255) NULL,
  is_scam         TINYINT(1) NULL,
  is_paid         TINYINT(1) NULL,

  -- Queue state machine
  status ENUM(
    'ingested',      -- stored, not acked/prepped
    'rejected',      -- rejected (limit/maintenance), no processing
    'queued',        -- ack sent and waiting
    'prepped',       -- cleaned/truncated ready for AI
    'ai_done',       -- AI result stored
    'sending',       -- picked up for sending
    'done',          -- final reply sent
    'error'          -- needs retry / inspection
  ) NOT NULL DEFAULT 'ingested',

  -- Immediate response control (ack)
  ack_sent_at DATETIME NULL,
  ack_message  VARCHAR(255) NULL,                        -- e.g. 'queued', 'limited', 'maintenance'
  queue_position_at_ack INT UNSIGNED NULL,
  eta_minutes_at_ack INT UNSIGNED NULL,

  -- Final response control
  reply_sent_at DATETIME NULL,
  reply_error TEXT NULL,

  -- Retry / locking (prevents two crons grabbing same row)
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_at DATETIME NULL,
  locked_by VARCHAR(64) NULL,

  -- Useful debugging
  last_error TEXT NULL,

  PRIMARY KEY (id),

  -- Dedupe: choose the best combo you can guarantee
  UNIQUE KEY uq_inbound_dedupe (source, mailbox, imap_uid),

  -- Worker picking indexes
  KEY idx_status_created (status, created_at),
  KEY idx_locked (locked_at),
  KEY idx_from_created (from_email, created_at),
  KEY idx_mode_status (mode, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inbound_metrics_daily (
  metric_date DATE NOT NULL,
  mode ENUM('generic','clarify','scamcheck') NOT NULL,
  received_count INT UNSIGNED NOT NULL DEFAULT 0,
  acked_count INT UNSIGNED NOT NULL DEFAULT 0,
  replied_count INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
  avg_seconds_to_ack INT UNSIGNED NULL,
  avg_seconds_to_reply INT UNSIGNED NULL,
  PRIMARY KEY (metric_date, mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
