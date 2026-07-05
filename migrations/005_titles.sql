ALTER TABLE pilots ADD COLUMN IF NOT EXISTS achievement_score INTEGER NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS pilot_titles (
    pilot_id      BIGINT       NOT NULL REFERENCES pilots(id) ON DELETE CASCADE,
    title_id      VARCHAR(36)  NOT NULL,
    title_name    TEXT,
    first_seen_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    last_seen_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    is_displayed  BOOLEAN      NOT NULL DEFAULT FALSE,
    PRIMARY KEY (pilot_id, title_id)
);

CREATE INDEX IF NOT EXISTS idx_pilot_titles_pilot_id ON pilot_titles(pilot_id);
