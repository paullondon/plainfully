-- 0007_create_consultation_uploads.sql
-- Upload metadata + OCR text for consultations.

CREATE TABLE IF NOT EXISTS consultation_uploads (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    consultation_id         BIGINT UNSIGNED NOT NULL,

    storage_key             VARCHAR(255) NOT NULL COMMENT 'Path/key in object storage (e.g. R2)',
    original_filename       VARCHAR(255) NOT NULL,
    mime_type               VARCHAR(128) NOT NULL,
    size_bytes              BIGINT UNSIGNED NOT NULL,

    ocr_ciphertext          LONGBLOB NULL COMMENT 'Encrypted OCR text from the file, if we run OCR',

    created_at              DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at              DATETIME(6) NOT NULL,

    PRIMARY KEY (id),
    KEY idx_consultation_uploads_consultation (consultation_id),
    KEY idx_consultation_uploads_expires (expires_at),
    CONSTRAINT fk_consultation_uploads_consultation
        FOREIGN KEY (consultation_id) REFERENCES consultations(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
