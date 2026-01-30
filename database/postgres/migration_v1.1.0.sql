-- PostgreSQL Migration Script
-- Add call_number and file_timestamp columns to processed_files table
-- Version: 1.1.0
-- Date: 2026-01-30

-- Add new columns to processed_files table
ALTER TABLE processed_files 
ADD COLUMN call_number VARCHAR(50),
ADD COLUMN file_timestamp BIGINT;

-- Add comments
COMMENT ON COLUMN processed_files.call_number IS 'Extracted from filename';
COMMENT ON COLUMN processed_files.file_timestamp IS 'Timestamp from filename for version tracking';

-- Add indexes for the new columns
CREATE INDEX idx_processed_files_call_number ON processed_files(call_number);
CREATE INDEX idx_processed_files_file_timestamp ON processed_files(file_timestamp);

-- Update existing records with extracted metadata (if any exist)
-- This is optional and will be empty if no files have been processed yet
-- The filename parser will populate these fields for future processed files

SELECT 'Migration completed successfully' AS status;
