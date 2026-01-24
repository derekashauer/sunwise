-- Add check_data column to care_log for structured check task data
-- Stores JSON with moisture_level, light_reading, observations, etc.
ALTER TABLE care_log ADD COLUMN check_data TEXT;
