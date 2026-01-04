-- Migration 008: v0.6.0 Features
-- AI Model Selection, Plant Graveyard, Task Configuration, Pots Inventory

-- AI Model Selection: Add model columns to ai_settings
ALTER TABLE ai_settings ADD COLUMN claude_model TEXT DEFAULT 'claude-sonnet-4-20250514';
ALTER TABLE ai_settings ADD COLUMN openai_model TEXT DEFAULT 'gpt-4o';

-- Plant Graveyard: Add archive columns to plants
ALTER TABLE plants ADD COLUMN archived_at DATETIME;
ALTER TABLE plants ADD COLUMN death_reason TEXT;

-- Task Type Configuration: Global settings
CREATE TABLE IF NOT EXISTS task_type_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    task_type TEXT NOT NULL,
    enabled INTEGER DEFAULT 1,
    UNIQUE(user_id, task_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Task Type Configuration: Per-plant overrides
CREATE TABLE IF NOT EXISTS plant_task_overrides (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plant_id INTEGER NOT NULL,
    task_type TEXT NOT NULL,
    enabled INTEGER NOT NULL,
    UNIQUE(plant_id, task_type),
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
);

-- Pots Inventory
CREATE TABLE IF NOT EXISTS pots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT,
    size TEXT NOT NULL,
    diameter_inches REAL,
    has_drainage INTEGER DEFAULT 1,
    material TEXT,
    color TEXT,
    image TEXT,
    image_thumbnail TEXT,
    notes TEXT,
    available INTEGER DEFAULT 1,
    plant_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_pots_user ON pots(user_id);
CREATE INDEX IF NOT EXISTS idx_pots_available ON pots(available);
CREATE INDEX IF NOT EXISTS idx_task_type_settings_user ON task_type_settings(user_id);
CREATE INDEX IF NOT EXISTS idx_plant_task_overrides_plant ON plant_task_overrides(plant_id);
