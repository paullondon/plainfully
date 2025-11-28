-- 0009_add_tone_to_consultations.sql

ALTER TABLE consultations
    ADD COLUMN tone VARCHAR(32) NOT NULL DEFAULT 'calm' AFTER source;
