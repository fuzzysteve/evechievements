-- EVEchievements initial schema
-- Run: psql -U evechievements -d evechievements -f migrations/001_initial_schema.sql

-- Enable UUID extension
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ─────────────────────────────────────────────
-- Pilots (authenticated EVE characters)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pilots (
    id              BIGINT PRIMARY KEY,          -- EVE character_id from ESI
    name            VARCHAR(255) NOT NULL,
    corporation_id  BIGINT,
    corporation_name VARCHAR(255),
    alliance_id     BIGINT,
    alliance_name   VARCHAR(255),
    portrait_64     TEXT,                        -- ESI portrait URL
    portrait_128    TEXT,
    portrait_256    TEXT,
    portrait_512    TEXT,
    security_status NUMERIC(10,6) DEFAULT 0,
    birthday        TIMESTAMPTZ,
    race_id         INTEGER,
    bloodline_id    INTEGER,
    is_public       BOOLEAN NOT NULL DEFAULT false,
    profile_bio     TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_sync_at    TIMESTAMPTZ
);

CREATE INDEX idx_pilots_name ON pilots(name);
CREATE INDEX idx_pilots_corporation_id ON pilots(corporation_id);
CREATE INDEX idx_pilots_is_public ON pilots(is_public);


INSERT INTO schema_migrations (version) VALUES ('001_initial_schema');
