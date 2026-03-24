-- Add task_types column to sitter_sessions for filtering which task types the sitter sees
ALTER TABLE sitter_sessions ADD COLUMN task_types TEXT;
