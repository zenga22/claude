-- Migration: Add 'name' column to users table
-- Run this if upgrading from the previous schema version.

ALTER TABLE users ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT '' AFTER username;

-- Backfill existing users with their username as name
UPDATE users SET name = username WHERE name = '';
