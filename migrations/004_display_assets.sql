ALTER TABLE pilots ADD COLUMN IF NOT EXISTS assets_value NUMERIC(20,2) NOT NULL DEFAULT -1;
 
CREATE TABLE IF NOT EXISTS pilot_display_assets (
    pilot_id  BIGINT  NOT NULL REFERENCES pilots(id) ON DELETE CASCADE,
    type_id   INTEGER NOT NULL,
    quantity  BIGINT  NOT NULL DEFAULT 1,
    PRIMARY KEY (pilot_id, type_id)
);
 
INSERT INTO schema_migrations (version) VALUES ('004_display_assets');

