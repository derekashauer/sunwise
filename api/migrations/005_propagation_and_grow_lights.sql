-- Migration 005: Propagation tracking, grow light support, and missing health_assessment

-- Add propagation fields to plants
ALTER TABLE plants ADD COLUMN parent_plant_id INTEGER REFERENCES plants(id);
ALTER TABLE plants ADD COLUMN propagation_date DATE;
ALTER TABLE plants ADD COLUMN is_propagation INTEGER DEFAULT 0;

-- Add grow light fields to plants
ALTER TABLE plants ADD COLUMN has_grow_light INTEGER DEFAULT 0;
ALTER TABLE plants ADD COLUMN grow_light_hours INTEGER;

-- Add missing health_assessment column to photos (referenced by TaskController)
ALTER TABLE photos ADD COLUMN health_assessment TEXT;

-- Index for finding children of a parent plant
CREATE INDEX IF NOT EXISTS idx_plants_parent ON plants(parent_plant_id);
