-- Users
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT,
    magic_token TEXT,
    magic_token_expires DATETIME,
    push_subscription TEXT,
    timezone TEXT DEFAULT 'America/New_York',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Plants
CREATE TABLE IF NOT EXISTS plants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    species TEXT,
    species_confidence REAL,
    pot_size TEXT,
    soil_type TEXT,
    light_condition TEXT,
    location TEXT,
    acquired_date DATE,
    notes TEXT,
    health_status TEXT DEFAULT 'unknown',
    last_health_check DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Photos
CREATE TABLE IF NOT EXISTS photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plant_id INTEGER NOT NULL,
    filename TEXT NOT NULL,
    thumbnail TEXT,
    ai_analysis TEXT,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
);

-- Care Plans
CREATE TABLE IF NOT EXISTS care_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plant_id INTEGER NOT NULL,
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    valid_until DATE,
    season TEXT,
    ai_reasoning TEXT,
    next_photo_check DATE,
    photo_check_reason TEXT,
    is_active INTEGER DEFAULT 1,
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
);

-- Tasks
CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    care_plan_id INTEGER,
    plant_id INTEGER NOT NULL,
    task_type TEXT NOT NULL,
    due_date DATE NOT NULL,
    recurrence TEXT,
    instructions TEXT,
    priority TEXT DEFAULT 'normal',
    completed_at DATETIME,
    skipped_at DATETIME,
    skip_reason TEXT,
    notes TEXT,
    FOREIGN KEY (care_plan_id) REFERENCES care_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
);

-- Care Log
CREATE TABLE IF NOT EXISTS care_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plant_id INTEGER NOT NULL,
    task_id INTEGER,
    action TEXT NOT NULL,
    performed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    photo_id INTEGER,
    outcome TEXT,
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE SET NULL
);

-- Sitter Sessions
CREATE TABLE IF NOT EXISTS sitter_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token TEXT UNIQUE NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    sitter_name TEXT,
    instructions TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    accessed_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Sitter Plants
CREATE TABLE IF NOT EXISTS sitter_plants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL,
    plant_id INTEGER NOT NULL,
    custom_instructions TEXT,
    FOREIGN KEY (session_id) REFERENCES sitter_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_tasks_due ON tasks(due_date, completed_at);
CREATE INDEX IF NOT EXISTS idx_plants_user ON plants(user_id);
CREATE INDEX IF NOT EXISTS idx_care_log_plant ON care_log(plant_id, performed_at);
CREATE INDEX IF NOT EXISTS idx_sitter_token ON sitter_sessions(token);
CREATE INDEX IF NOT EXISTS idx_photos_plant ON photos(plant_id);
