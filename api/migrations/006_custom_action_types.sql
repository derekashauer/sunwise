-- Migration 006: Custom action types for care log

CREATE TABLE IF NOT EXISTS custom_action_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    icon TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(user_id, name)
);

-- Index for faster lookups
CREATE INDEX IF NOT EXISTS idx_custom_actions_user ON custom_action_types(user_id);
