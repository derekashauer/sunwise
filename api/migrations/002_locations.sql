-- Locations table for managed location list
CREATE TABLE IF NOT EXISTS locations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Add location_id to plants (nullable for migration)
ALTER TABLE plants ADD COLUMN location_id INTEGER REFERENCES locations(id) ON DELETE SET NULL;

-- Index for locations
CREATE INDEX IF NOT EXISTS idx_locations_user ON locations(user_id);
CREATE INDEX IF NOT EXISTS idx_plants_location ON plants(location_id);
