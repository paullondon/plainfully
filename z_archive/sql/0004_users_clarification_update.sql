ALTER TABLE users
ADD plan ENUM('free', 'pro', 'unlimited') NOT NULL DEFAULT 'free';

ALTER TABLE clarifications
ADD text_hash CHAR(64) NULL,
ADD INDEX idx_clarifications_user_hash (user_id, text_hash);
