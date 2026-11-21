-- A due date set on purpose (for example "water today" after a dry check)
-- is pinned so the daily cleanup doesn't push it back out because the
-- plant was watered a few days ago.
ALTER TABLE tasks ADD COLUMN due_pinned INTEGER DEFAULT 0;
