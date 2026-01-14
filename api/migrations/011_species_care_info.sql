-- Species care info and AI usage logging

-- Add species care info column to plants
ALTER TABLE plants ADD COLUMN species_care_info TEXT;

-- AI usage log for tracking API calls and errors
CREATE TABLE IF NOT EXISTS ai_usage_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    model TEXT,
    success INTEGER DEFAULT 1,
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_ai_log_user ON ai_usage_log(user_id, created_at);
