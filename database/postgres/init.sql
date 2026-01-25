-- NWS CAD Database Schema - PostgreSQL Version
-- This is a placeholder schema. Replace with your actual schema.

-- Table to store CAD event information
CREATE TABLE IF NOT EXISTS cad_events (
    id BIGSERIAL PRIMARY KEY,
    event_id VARCHAR(100) UNIQUE NOT NULL,
    event_type VARCHAR(50),
    event_time TIMESTAMP,
    location VARCHAR(255),
    description TEXT,
    priority VARCHAR(20),
    status VARCHAR(50),
    xml_data JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_event_id ON cad_events(event_id);
CREATE INDEX IF NOT EXISTS idx_event_time ON cad_events(event_time);
CREATE INDEX IF NOT EXISTS idx_status ON cad_events(status);

-- Table to track processed XML files
CREATE TABLE IF NOT EXISTS processed_files (
    id BIGSERIAL PRIMARY KEY,
    filename VARCHAR(255) UNIQUE NOT NULL,
    file_hash VARCHAR(64) NOT NULL,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) CHECK (status IN ('success', 'failed', 'partial')) DEFAULT 'success',
    error_message TEXT,
    records_processed INT DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_filename ON processed_files(filename);
CREATE INDEX IF NOT EXISTS idx_processed_at ON processed_files(processed_at);

-- Table for storing parsed XML metadata
CREATE TABLE IF NOT EXISTS xml_metadata (
    id BIGSERIAL PRIMARY KEY,
    file_id BIGINT NOT NULL,
    metadata_key VARCHAR(100) NOT NULL,
    metadata_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (file_id) REFERENCES processed_files(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_file_id ON xml_metadata(file_id);
CREATE INDEX IF NOT EXISTS idx_metadata_key ON xml_metadata(metadata_key);

-- Function to update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Trigger to automatically update updated_at
CREATE TRIGGER update_cad_events_updated_at BEFORE UPDATE ON cad_events
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
