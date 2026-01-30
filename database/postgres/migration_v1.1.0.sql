-- PostgreSQL Migration Script
-- Add call_number and file_timestamp columns to processed_files table
-- Version: 1.1.0
-- Date: 2026-01-30

-- Check if columns already exist and add them if they don't
DO $$ 
BEGIN
    -- Add call_number column if it doesn't exist
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'processed_files' AND column_name = 'call_number'
    ) THEN
        ALTER TABLE processed_files ADD COLUMN call_number VARCHAR(50);
        COMMENT ON COLUMN processed_files.call_number IS 'Extracted from filename';
    END IF;
    
    -- Add file_timestamp column if it doesn't exist
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'processed_files' AND column_name = 'file_timestamp'
    ) THEN
        ALTER TABLE processed_files ADD COLUMN file_timestamp BIGINT;
        COMMENT ON COLUMN processed_files.file_timestamp IS 'Timestamp from filename for version tracking';
    END IF;
END $$;

-- Add indexes for the new columns if they don't exist
CREATE INDEX IF NOT EXISTS idx_processed_files_call_number ON processed_files(call_number);
CREATE INDEX IF NOT EXISTS idx_processed_files_file_timestamp ON processed_files(file_timestamp);

-- Update existing records with extracted metadata (if any exist)
-- This is optional and will be empty if no files have been processed yet
-- The filename parser will populate these fields for future processed files

SELECT 'Migration completed successfully' AS status;
