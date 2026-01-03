-- Migration 003: Chat messages and AI settings with encrypted API keys

-- Chat messages for plant conversations
CREATE TABLE IF NOT EXISTS chat_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plant_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('user', 'assistant')),
    content TEXT NOT NULL,
    provider TEXT CHECK (provider IN ('claude', 'openai')),
    suggested_actions TEXT,  -- JSON: array of suggested actions
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- AI settings per user with encrypted API keys
CREATE TABLE IF NOT EXISTS ai_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER UNIQUE NOT NULL,
    default_provider TEXT DEFAULT 'openai' CHECK (default_provider IN ('claude', 'openai')),
    claude_api_key_encrypted TEXT,           -- AES-256-GCM encrypted
    openai_api_key_encrypted TEXT,           -- AES-256-GCM encrypted
    claude_key_added_at DATETIME,
    openai_key_added_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_chat_messages_plant ON chat_messages(plant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_chat_messages_user ON chat_messages(user_id);
CREATE INDEX IF NOT EXISTS idx_ai_settings_user ON ai_settings(user_id);
