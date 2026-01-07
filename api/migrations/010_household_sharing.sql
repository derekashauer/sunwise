-- Migration 010: Household Sharing
-- Allow multiple users to share plant management responsibilities

-- Households table - a group of users who share plants
CREATE TABLE IF NOT EXISTS households (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    owner_id INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Household members - links users to households with roles
CREATE TABLE IF NOT EXISTS household_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    household_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT DEFAULT 'member',  -- 'owner', 'member'
    display_name TEXT,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(household_id, user_id),
    FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Household invitations - pending invites sent by email
CREATE TABLE IF NOT EXISTS household_invitations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    household_id INTEGER NOT NULL,
    email TEXT NOT NULL,
    token TEXT UNIQUE NOT NULL,
    invited_by INTEGER NOT NULL,
    share_all_plants INTEGER DEFAULT 0,  -- 1 = share all current plants, 0 = share selected
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Plants shared with household (junction table)
-- Allows selective sharing of plants to household members
CREATE TABLE IF NOT EXISTS household_plants (
    household_id INTEGER NOT NULL,
    plant_id INTEGER NOT NULL,
    shared_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    shared_by INTEGER,  -- User who shared the plant
    PRIMARY KEY (household_id, plant_id),
    FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Track who completed tasks (for attribution display)
ALTER TABLE tasks ADD COLUMN completed_by_user_id INTEGER REFERENCES users(id);

-- Track who performed care actions (for attribution display)
ALTER TABLE care_log ADD COLUMN performed_by_user_id INTEGER REFERENCES users(id);

-- User display name for showing who completed tasks
ALTER TABLE users ADD COLUMN display_name TEXT;

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_households_owner ON households(owner_id);
CREATE INDEX IF NOT EXISTS idx_household_members_household ON household_members(household_id);
CREATE INDEX IF NOT EXISTS idx_household_members_user ON household_members(user_id);
CREATE INDEX IF NOT EXISTS idx_household_plants_household ON household_plants(household_id);
CREATE INDEX IF NOT EXISTS idx_household_plants_plant ON household_plants(plant_id);
CREATE INDEX IF NOT EXISTS idx_household_invitations_token ON household_invitations(token);
CREATE INDEX IF NOT EXISTS idx_household_invitations_email ON household_invitations(email);
CREATE INDEX IF NOT EXISTS idx_tasks_completed_by ON tasks(completed_by_user_id);
CREATE INDEX IF NOT EXISTS idx_care_log_performed_by ON care_log(performed_by_user_id);
