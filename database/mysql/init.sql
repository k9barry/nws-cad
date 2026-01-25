-- NWS CAD Database Schema - MySQL Version
-- This is a placeholder schema. Replace with your actual schema.

-- Table to store CAD event information
CREATE TABLE IF NOT EXISTS cad_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(100) UNIQUE NOT NULL,
    event_type VARCHAR(50),
    event_time DATETIME,
    location VARCHAR(255),
    description TEXT,
    priority VARCHAR(20),
    status VARCHAR(50),
    xml_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_event_id (event_id),
    INDEX idx_event_time (event_time),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to track processed XML files
CREATE TABLE IF NOT EXISTS processed_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) UNIQUE NOT NULL,
    file_hash VARCHAR(64) NOT NULL,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('success', 'failed', 'partial') DEFAULT 'success',
    error_message TEXT,
    records_processed INT DEFAULT 0,
    INDEX idx_filename (filename),
    INDEX idx_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for storing parsed XML metadata
CREATE TABLE IF NOT EXISTS xml_metadata (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_id BIGINT UNSIGNED NOT NULL,
    metadata_key VARCHAR(100) NOT NULL,
    metadata_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (file_id) REFERENCES processed_files(id) ON DELETE CASCADE,
    INDEX idx_file_id (file_id),
    INDEX idx_metadata_key (metadata_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
