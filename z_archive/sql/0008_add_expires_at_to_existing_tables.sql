-- 0008_add_expires_at_to_existing_tables.sql

ALTER TABLE auth_events
    ADD COLUMN expires_at DATETIME(6) NULL AFTER created_at;

CREATE INDEX idx_auth_events_expires ON auth_events (expires_at);

UPDATE auth_events
SET expires_at = DATE_ADD(created_at, INTERVAL 28 DAY)
WHERE expires_at IS NULL;
