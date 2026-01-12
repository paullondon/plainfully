-- 0006_create_consultation_details.sql
-- Sensitive / rich text fields. Store as ciphertext blobs.

CREATE TABLE IF NOT EXISTS consultation_details (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    consultation_id         BIGINT UNSIGNED NOT NULL,
    role                    VARCHAR(32) NOT NULL DEFAULT 'user' COMMENT 'user/system/assistant etc.',
    sequence_no             INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Order of messages within consultation',

    prompt_ciphertext       LONGBLOB NULL COMMENT 'Encrypted original prompt',
    clarification_ciphertext LONGBLOB NULL COMMENT 'Encrypted clarified prompt or system output',
    model_response_ciphertext LONGBLOB NULL COMMENT 'Encrypted AI response',
    redacted_summary_ciphertext LONGBLOB NULL COMMENT 'Encrypted, de-risked summary we may be allowed to keep longer later if policy changes',

    created_at              DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at              DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    expires_at              DATETIME(6) NOT NULL COMMENT 'Must match parent consultation.expires_at',

    PRIMARY KEY (id),
    KEY idx_consultation_details_consultation (consultation_id),
    KEY idx_consultation_details_expires (expires_at),
    CONSTRAINT fk_consultation_details_consultation
        FOREIGN KEY (consultation_id) REFERENCES consultations(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
