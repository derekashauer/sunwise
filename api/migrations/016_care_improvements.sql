-- 0.15.0 care improvements

-- Per-user care preferences. fertilizer_method is 'separate' for slow-release
-- granules (like Osmocote) or 'mixed' for liquid fertilizer mixed into water.
-- winter_rest pauses fertilizing from September through February.
ALTER TABLE users ADD COLUMN climate_notes TEXT;
ALTER TABLE users ADD COLUMN fertilizer_method TEXT DEFAULT 'separate';
ALTER TABLE users ADD COLUMN winter_rest INTEGER DEFAULT 1;
ALTER TABLE users ADD COLUMN last_push_sent DATE;

-- Links that make "undo" possible. generated_from_task_id points at the task
-- whose completion or skip created this one. skipped_by_task_id points at the
-- completed task that auto-skipped this one.
ALTER TABLE tasks ADD COLUMN generated_from_task_id INTEGER;
ALTER TABLE tasks ADD COLUMN skipped_by_task_id INTEGER;

-- Intervals the owner chose by hand. Care plan rebuilds use these instead of
-- the AI's suggestion, so a tweak survives season changes and rebuilds.
CREATE TABLE IF NOT EXISTS plant_schedule_overrides (
    plant_id INTEGER NOT NULL,
    task_type TEXT NOT NULL,
    interval_days INTEGER NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (plant_id, task_type),
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tasks_plant_type_pending ON tasks(plant_id, task_type, completed_at, skipped_at);
CREATE INDEX IF NOT EXISTS idx_tasks_generated_from ON tasks(generated_from_task_id);
CREATE INDEX IF NOT EXISTS idx_tasks_skipped_by ON tasks(skipped_by_task_id);
CREATE INDEX IF NOT EXISTS idx_care_log_plant_action ON care_log(plant_id, action, performed_at);
