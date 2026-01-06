-- Migration 009: Add drainage field to plants
-- Version: 0.7.3

-- Add drainage option to plants
ALTER TABLE plants ADD COLUMN has_drainage INTEGER DEFAULT 1;
