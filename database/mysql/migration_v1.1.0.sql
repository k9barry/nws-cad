-- MySQL Migration Script
-- Add call_number and file_timestamp columns to processed_files table
-- Version: 1.1.0
-- Date: 2026-01-30

USE nws_cad;

-- Add new columns to processed_files table if they don't exist
SET @call_number_exists = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
    AND table_name = 'processed_files' 
    AND column_name = 'call_number'
);

SET @file_timestamp_exists = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
    AND table_name = 'processed_files' 
    AND column_name = 'file_timestamp'
);

-- Add call_number if it doesn't exist
SET @sql = IF(@call_number_exists = 0, 
    'ALTER TABLE processed_files ADD COLUMN call_number VARCHAR(50) COMMENT "Extracted from filename" AFTER file_hash',
    'SELECT "call_number column already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add file_timestamp if it doesn't exist
SET @sql = IF(@file_timestamp_exists = 0,
    'ALTER TABLE processed_files ADD COLUMN file_timestamp BIGINT COMMENT "Timestamp from filename for version tracking" AFTER call_number',
    'SELECT "file_timestamp column already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add indexes if they don't exist
SET @index1_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
    AND table_name = 'processed_files'
    AND index_name = 'idx_call_number'
);

SET @index2_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
    AND table_name = 'processed_files'
    AND index_name = 'idx_file_timestamp'
);

SET @sql = IF(@index1_exists = 0,
    'CREATE INDEX idx_call_number ON processed_files(call_number)',
    'SELECT "idx_call_number already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@index2_exists = 0,
    'CREATE INDEX idx_file_timestamp ON processed_files(file_timestamp)',
    'SELECT "idx_file_timestamp already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update existing records with extracted metadata (if any exist)
-- This is optional and will be empty if no files have been processed yet
-- The filename parser will populate these fields for future processed files

SELECT 'Migration completed successfully' AS status;
