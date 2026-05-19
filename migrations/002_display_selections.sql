-- Pilot display selections
-- Stores only what the pilot has chosen to show on their public profile
 
CREATE TABLE IF NOT EXISTS pilot_display_skills (
    pilot_id BIGINT  NOT NULL REFERENCES pilots(id) ON DELETE CASCADE,
    skill_id INTEGER NOT NULL,
    level    SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (pilot_id, skill_id)
);
 
CREATE TABLE IF NOT EXISTS pilot_display_certs (
    pilot_id BIGINT  NOT NULL REFERENCES pilots(id) ON DELETE CASCADE,
    cert_id  INTEGER NOT NULL,
    level    SMALLINT NOT NULL DEFAULT 0,  -- highest completed level at time of save
    PRIMARY KEY (pilot_id, cert_id)
);
 
CREATE TABLE IF NOT EXISTS pilot_display_masteries (
    pilot_id      BIGINT  NOT NULL REFERENCES pilots(id) ON DELETE CASCADE,
    type_id       INTEGER NOT NULL,
    mastery_level SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (pilot_id, type_id)
);
 
INSERT INTO schema_migrations (version) VALUES ('002_display_selections');
 

