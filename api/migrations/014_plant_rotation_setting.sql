-- Add can_rotate setting to plants (default 1 = true, can be rotated)
-- Some plants like hanging plants or symmetrical plants don't need rotation
ALTER TABLE plants ADD COLUMN can_rotate INTEGER DEFAULT 1;
