-- Migration 004: New features - email digest, SMS, window orientation, public gallery

-- Add email digest settings to users
ALTER TABLE users ADD COLUMN email_digest_enabled INTEGER DEFAULT 0;
ALTER TABLE users ADD COLUMN email_digest_time TEXT DEFAULT '08:00';  -- HH:MM format
ALTER TABLE users ADD COLUMN last_digest_sent DATE;

-- Add SMS settings to users
ALTER TABLE users ADD COLUMN sms_enabled INTEGER DEFAULT 0;
ALTER TABLE users ADD COLUMN sms_phone TEXT;
ALTER TABLE users ADD COLUMN twilio_account_sid TEXT;
ALTER TABLE users ADD COLUMN twilio_auth_token_encrypted TEXT;
ALTER TABLE users ADD COLUMN twilio_phone_number TEXT;

-- Add window orientation to locations
ALTER TABLE locations ADD COLUMN window_orientation TEXT CHECK (window_orientation IN ('north', 'south', 'east', 'west', 'none'));

-- Add public sharing to users
ALTER TABLE users ADD COLUMN public_gallery_enabled INTEGER DEFAULT 0;
ALTER TABLE users ADD COLUMN public_gallery_token TEXT UNIQUE;
ALTER TABLE users ADD COLUMN public_gallery_name TEXT;

-- Add species candidates to plants for AI picker
ALTER TABLE plants ADD COLUMN species_candidates TEXT;  -- JSON: [{species: '', confidence: 0.9}, ...]
ALTER TABLE plants ADD COLUMN species_confirmed INTEGER DEFAULT 0;  -- 0=auto, 1=user confirmed

-- Index for public gallery lookups
CREATE INDEX IF NOT EXISTS idx_users_public_gallery ON users(public_gallery_token);
