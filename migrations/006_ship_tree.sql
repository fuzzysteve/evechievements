-- Ship tree display
-- Factions the pilot has chosen to publish a ship tree for, plus a snapshot of
-- the ships in those factions they can fly and their mastery level (0 = can fly,
-- no mastery). Ships absent from pilot_shiptree_ships cannot be flown.

CREATE TABLE IF NOT EXISTS pilot_display_shiptree_factions (
    pilot_id   BIGINT  NOT NULL REFERENCES pilots(id) ON DELETE CASCADE,
    faction_id INTEGER NOT NULL,
    PRIMARY KEY (pilot_id, faction_id)
);

CREATE TABLE IF NOT EXISTS pilot_shiptree_ships (
    pilot_id      BIGINT   NOT NULL REFERENCES pilots(id) ON DELETE CASCADE,
    type_id       INTEGER  NOT NULL,
    mastery_level SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (pilot_id, type_id)
);

INSERT INTO schema_migrations (version) VALUES ('006_ship_tree');
