-- Add baseline light reading to plants
-- Stores midday light meter reading in foot-candles for care plan calculations
ALTER TABLE plants ADD COLUMN baseline_light_reading INTEGER;
