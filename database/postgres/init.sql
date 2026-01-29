-- NWS Aegis CAD Database Schema - PostgreSQL Version
-- Comprehensive schema for New World Systems CAD XML export format

-- ============================================================================
-- CORE CALL TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS calls (
    id BIGSERIAL PRIMARY KEY,
    call_id BIGINT NOT NULL,
    call_number VARCHAR(50) NOT NULL,
    call_source VARCHAR(100),
    caller_name VARCHAR(255),
    caller_phone VARCHAR(50),
    nature_of_call TEXT,
    additional_info TEXT,
    
    -- Timestamps
    create_datetime TIMESTAMP NOT NULL,
    close_datetime TIMESTAMP,
    created_by VARCHAR(100),
    
    -- Status flags
    closed_flag BOOLEAN DEFAULT FALSE,
    canceled_flag BOOLEAN DEFAULT FALSE,
    
    -- Codes and levels
    alarm_level INTEGER,
    emd_code VARCHAR(50),
    fire_controlled_time TIMESTAMP,
    
    -- Full XML storage
    xml_data JSONB,
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT uk_call_id UNIQUE (call_id)
);

CREATE INDEX idx_calls_call_number ON calls(call_number);
CREATE INDEX idx_calls_create_datetime ON calls(create_datetime);
CREATE INDEX idx_calls_close_datetime ON calls(close_datetime);
CREATE INDEX idx_calls_closed_flag ON calls(closed_flag);
CREATE INDEX idx_calls_created_by ON calls(created_by);

-- ============================================================================
-- AGENCY CONTEXTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS agency_contexts (
    id BIGSERIAL PRIMARY KEY,
    call_id BIGINT NOT NULL,
    
    agency_type VARCHAR(50),
    call_type VARCHAR(100),
    priority VARCHAR(100),
    status VARCHAR(100),
    dispatcher VARCHAR(100),
    radio_channel VARCHAR(100),
    
    -- Timestamps
    created_datetime TIMESTAMP,
    closed_datetime TIMESTAMP,
    
    -- Flags
    closed_flag BOOLEAN DEFAULT FALSE,
    canceled_flag BOOLEAN DEFAULT FALSE,
    
    -- EMD
    emd_case_number VARCHAR(100),
    emd_code VARCHAR(50),
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
);

CREATE INDEX idx_agency_contexts_call_id ON agency_contexts(call_id);
CREATE INDEX idx_agency_contexts_agency_type ON agency_contexts(agency_type);
CREATE INDEX idx_agency_contexts_status ON agency_contexts(status);
CREATE INDEX idx_agency_contexts_dispatcher ON agency_contexts(dispatcher);

-- ============================================================================
-- LOCATION
-- ============================================================================

CREATE TABLE IF NOT EXISTS locations (
    id BIGSERIAL PRIMARY KEY,
    call_id BIGINT NOT NULL,
    
    -- Full address
    full_address VARCHAR(500),
    house_number VARCHAR(20),
    house_number_suffix VARCHAR(10),
    prefix_directional VARCHAR(10),
    prefix_type VARCHAR(20),
    street_name VARCHAR(200),
    street_type VARCHAR(20),
    street_directional VARCHAR(10),
    qualifier VARCHAR(100),
    
    -- Location details
    city VARCHAR(100),
    state VARCHAR(10),
    zip VARCHAR(10),
    zip4 VARCHAR(10),
    venue VARCHAR(100),
    common_name VARCHAR(255),
    
    -- Cross streets
    nearest_cross_streets VARCHAR(255),
    x_prefix_directional VARCHAR(10),
    x_prefix_type VARCHAR(20),
    x_street_name VARCHAR(200),
    x_street_type VARCHAR(20),
    x_street_directional VARCHAR(10),
    
    -- Coordinates
    latitude_y DECIMAL(10, 7),
    longitude_x DECIMAL(10, 7),
    lat_lon_description VARCHAR(255),
    
    -- Districts and zones
    police_beat VARCHAR(50),
    police_ori VARCHAR(50),
    ems_district VARCHAR(50),
    ems_ori VARCHAR(50),
    fire_quadrant VARCHAR(50),
    fire_ori VARCHAR(50),
    census_tract VARCHAR(50),
    rural_grid VARCHAR(50),
    station_area VARCHAR(50),
    custom_layer VARCHAR(100),
    additional_info TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
);

CREATE INDEX idx_locations_call_id ON locations(call_id);
CREATE INDEX idx_locations_city ON locations(city);
CREATE INDEX idx_locations_police_beat ON locations(police_beat);
CREATE INDEX idx_locations_ems_district ON locations(ems_district);
CREATE INDEX idx_locations_fire_quadrant ON locations(fire_quadrant);
CREATE INDEX idx_locations_coordinates ON locations(latitude_y, longitude_x);

-- ============================================================================
-- INCIDENTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS incidents (
    id BIGSERIAL PRIMARY KEY,
    call_id BIGINT NOT NULL,
    
    incident_number VARCHAR(50) NOT NULL,
    incident_type VARCHAR(100),
    type_description VARCHAR(255),
    agency_type VARCHAR(50),
    case_number VARCHAR(100),
    jurisdiction VARCHAR(50),
    create_datetime TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
);

CREATE INDEX idx_incidents_call_id ON incidents(call_id);
CREATE INDEX idx_incidents_incident_number ON incidents(incident_number);
CREATE INDEX idx_incidents_agency_type ON incidents(agency_type);
CREATE INDEX idx_incidents_case_number ON incidents(case_number);
CREATE INDEX idx_incidents_jurisdiction ON incidents(jurisdiction);

-- ============================================================================
-- UNITS
-- ============================================================================

CREATE TABLE IF NOT EXISTS units (
    id BIGSERIAL PRIMARY KEY,
    call_id BIGINT NOT NULL,
    
    unit_number VARCHAR(50) NOT NULL,
    unit_type VARCHAR(50),
    is_primary BOOLEAN DEFAULT FALSE,
    jurisdiction VARCHAR(50),
    
    -- Timestamps for unit activity
    assigned_datetime TIMESTAMP,
    dispatch_datetime TIMESTAMP,
    enroute_datetime TIMESTAMP,
    arrive_datetime TIMESTAMP,
    staged_datetime TIMESTAMP,
    at_patient_datetime TIMESTAMP,
    transport_datetime TIMESTAMP,
    at_hospital_datetime TIMESTAMP,
    depart_hospital_datetime TIMESTAMP,
    clear_datetime TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    UNIQUE (call_id, unit_number)
);

CREATE INDEX idx_units_call_id ON units(call_id);
CREATE INDEX idx_units_unit_number ON units(unit_number);
CREATE INDEX idx_units_jurisdiction ON units(jurisdiction);
CREATE INDEX idx_units_is_primary ON units(is_primary);

-- ============================================================================
-- UNIT PERSONNEL
-- ============================================================================

CREATE TABLE IF NOT EXISTS unit_personnel (
    id BIGSERIAL PRIMARY KEY,
    unit_id BIGINT NOT NULL,
    
    first_name VARCHAR(100),
    middle_name VARCHAR(100),
    last_name VARCHAR(100),
    id_number VARCHAR(50),
    shield_number VARCHAR(50),
    jurisdiction VARCHAR(50),
    is_primary_officer BOOLEAN DEFAULT FALSE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
);

CREATE INDEX idx_unit_personnel_unit_id ON unit_personnel(unit_id);
CREATE INDEX idx_unit_personnel_id_number ON unit_personnel(id_number);
CREATE INDEX idx_unit_personnel_shield_number ON unit_personnel(shield_number);
CREATE INDEX idx_unit_personnel_name ON unit_personnel(last_name, first_name);

-- ============================================================================
-- UNIT LOGS
-- ============================================================================

CREATE TABLE IF NOT EXISTS unit_logs (
    id BIGSERIAL PRIMARY KEY,
    unit_id BIGINT NOT NULL,
    
    log_datetime TIMESTAMP NOT NULL,
    status VARCHAR(100) NOT NULL,
    location VARCHAR(500) NOT NULL DEFAULT '',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE,
    UNIQUE (unit_id, log_datetime, status, location)
);

CREATE INDEX idx_unit_logs_unit_id ON unit_logs(unit_id);
CREATE INDEX idx_unit_logs_log_datetime ON unit_logs(log_datetime);
CREATE INDEX idx_unit_logs_status ON unit_logs(status);

-- ============================================================================
-- NARRATIVES
-- ============================================================================

CREATE TABLE IF NOT EXISTS narratives (
    id BIGSERIAL PRIMARY KEY,
    call_id BIGINT NOT NULL,
    
    create_datetime TIMESTAMP NOT NULL,
    create_user VARCHAR(100) NOT NULL DEFAULT '',
    narrative_type VARCHAR(50),
    restriction VARCHAR(50),
    text TEXT NOT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    UNIQUE (call_id, create_datetime, create_user, text)
);

CREATE INDEX idx_narratives_call_id ON narratives(call_id);
CREATE INDEX idx_narratives_create_datetime ON narratives(create_datetime);
CREATE INDEX idx_narratives_create_user ON narratives(create_user);
CREATE INDEX idx_narratives_type ON narratives(narrative_type);

-- ============================================================================
-- PERSONS
-- ============================================================================

CREATE TABLE IF NOT EXISTS persons (
    id BIGSERIAL PRIMARY KEY,
    call_id BIGINT NOT NULL,
    
    -- Name
    first_name VARCHAR(100),
    middle_name VARCHAR(100),
    last_name VARCHAR(100),
    name_suffix VARCHAR(20),
    
    -- Contact
    contact_phone VARCHAR(50),
    address TEXT,
    
    -- Identification
    date_of_birth DATE,
    ssn VARCHAR(20),
    global_subject_id VARCHAR(100),
    
    -- License
    license_number VARCHAR(50),
    license_state VARCHAR(10),
    
    -- Physical descriptors
    sex VARCHAR(20),
    race VARCHAR(50),
    height_inches INTEGER,
    weight INTEGER,
    hair_color VARCHAR(50),
    eye_color VARCHAR(50),
    
    -- Role
    role VARCHAR(100),
    primary_caller_flag BOOLEAN DEFAULT FALSE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
);

CREATE INDEX idx_persons_call_id ON persons(call_id);
CREATE INDEX idx_persons_name ON persons(last_name, first_name);
CREATE INDEX idx_persons_role ON persons(role);
CREATE INDEX idx_persons_primary_caller ON persons(primary_caller_flag);
CREATE INDEX idx_persons_license ON persons(license_number, license_state);

-- ============================================================================
-- VEHICLES
-- ============================================================================

CREATE TABLE IF NOT EXISTS vehicles (
    id BIGSERIAL PRIMARY KEY,
    call_id BIGINT NOT NULL,
    
    -- Vehicle identification
    license_plate VARCHAR(20),
    license_state VARCHAR(10),
    vin VARCHAR(50),
    
    -- Vehicle details
    make VARCHAR(50),
    model VARCHAR(50),
    year INTEGER,
    color VARCHAR(50),
    vehicle_type VARCHAR(50),
    
    -- Registration
    registered_owner VARCHAR(255),
    
    -- Additional info
    description TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
);

CREATE INDEX idx_vehicles_call_id ON vehicles(call_id);
CREATE INDEX idx_vehicles_license ON vehicles(license_plate, license_state);
CREATE INDEX idx_vehicles_vin ON vehicles(vin);

-- ============================================================================
-- DISPOSITIONS
-- ============================================================================

CREATE TABLE IF NOT EXISTS call_dispositions (
    id BIGSERIAL PRIMARY KEY,
    call_id BIGINT NOT NULL,
    
    disposition_name VARCHAR(100) NOT NULL,
    description VARCHAR(255),
    count INTEGER DEFAULT 1,
    disposition_datetime TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
);

CREATE INDEX idx_call_dispositions_call_id ON call_dispositions(call_id);
CREATE INDEX idx_call_dispositions_name ON call_dispositions(disposition_name);
CREATE INDEX idx_call_dispositions_datetime ON call_dispositions(disposition_datetime);

-- ============================================================================
-- UNIT DISPOSITIONS (dispositions specific to a unit)
-- ============================================================================

CREATE TABLE IF NOT EXISTS unit_dispositions (
    id BIGSERIAL PRIMARY KEY,
    unit_id BIGINT NOT NULL,
    
    disposition_name VARCHAR(100) NOT NULL,
    description VARCHAR(255),
    count INTEGER DEFAULT 1,
    disposition_datetime TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
);

CREATE INDEX idx_unit_dispositions_unit_id ON unit_dispositions(unit_id);
CREATE INDEX idx_unit_dispositions_name ON unit_dispositions(disposition_name);

-- ============================================================================
-- FILE TRACKING
-- ============================================================================

CREATE TABLE IF NOT EXISTS processed_files (
    id BIGSERIAL PRIMARY KEY,
    filename VARCHAR(255) UNIQUE NOT NULL,
    file_hash VARCHAR(64) NOT NULL,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) CHECK (status IN ('success', 'failed', 'partial')) DEFAULT 'success',
    error_message TEXT,
    records_processed INTEGER DEFAULT 0
);

CREATE INDEX idx_processed_files_filename ON processed_files(filename);
CREATE INDEX idx_processed_files_processed_at ON processed_files(processed_at);
CREATE INDEX idx_processed_files_status ON processed_files(status);

-- ============================================================================
-- TRIGGERS FOR UPDATED_AT COLUMNS
-- ============================================================================

-- Function to update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Triggers for all tables with updated_at
CREATE TRIGGER update_calls_updated_at 
    BEFORE UPDATE ON calls
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_agency_contexts_updated_at 
    BEFORE UPDATE ON agency_contexts
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_locations_updated_at 
    BEFORE UPDATE ON locations
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_incidents_updated_at 
    BEFORE UPDATE ON incidents
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_units_updated_at 
    BEFORE UPDATE ON units
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_unit_personnel_updated_at 
    BEFORE UPDATE ON unit_personnel
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_persons_updated_at 
    BEFORE UPDATE ON persons
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_vehicles_updated_at 
    BEFORE UPDATE ON vehicles
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
