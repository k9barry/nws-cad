-- NWS Aegis CAD Database Schema - MySQL Version
-- Comprehensive schema for New World Systems CAD XML export format

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- CORE CALL TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS calls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id BIGINT UNSIGNED NOT NULL COMMENT 'CallId from XML',
    call_number VARCHAR(50) NOT NULL COMMENT 'CallNumber from XML',
    call_source VARCHAR(100) COMMENT 'Phone, Radio, Walk-in, etc.',
    caller_name VARCHAR(255),
    caller_phone VARCHAR(50),
    nature_of_call TEXT,
    additional_info TEXT,
    
    -- Timestamps
    create_datetime DATETIME NOT NULL,
    close_datetime DATETIME,
    created_by VARCHAR(100),
    
    -- Status flags
    closed_flag BOOLEAN DEFAULT FALSE,
    canceled_flag BOOLEAN DEFAULT FALSE,
    
    -- Codes and levels
    alarm_level INT,
    emd_code VARCHAR(50),
    fire_controlled_time DATETIME,
    
    -- Full XML storage
    xml_data JSON COMMENT 'Complete XML for reference',
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY uk_call_id (call_id),
    INDEX idx_call_number (call_number),
    INDEX idx_create_datetime (create_datetime),
    INDEX idx_close_datetime (close_datetime),
    INDEX idx_closed_flag (closed_flag),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- AGENCY CONTEXTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS agency_contexts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id BIGINT UNSIGNED NOT NULL,
    
    agency_type VARCHAR(50) COMMENT 'Police, Fire, EMS',
    call_type VARCHAR(100),
    priority VARCHAR(100),
    status VARCHAR(100),
    dispatcher VARCHAR(100),
    radio_channel VARCHAR(100),
    
    -- Timestamps
    created_datetime DATETIME,
    closed_datetime DATETIME,
    
    -- Flags
    closed_flag BOOLEAN DEFAULT FALSE,
    canceled_flag BOOLEAN DEFAULT FALSE,
    
    -- EMD
    emd_case_number VARCHAR(100),
    emd_code VARCHAR(50),
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    INDEX idx_call_id (call_id),
    INDEX idx_agency_type (agency_type),
    INDEX idx_status (status),
    INDEX idx_dispatcher (dispatcher)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- LOCATION
-- ============================================================================

CREATE TABLE IF NOT EXISTS locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id BIGINT UNSIGNED NOT NULL,
    
    -- Full address
    full_address VARCHAR(500),
    house_number VARCHAR(20),
    house_number_suffix VARCHAR(10),
    prefix_directional VARCHAR(10) COMMENT 'N, S, E, W',
    prefix_type VARCHAR(20),
    street_name VARCHAR(200),
    street_type VARCHAR(20) COMMENT 'ST, AVE, RD, etc.',
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
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    INDEX idx_call_id (call_id),
    INDEX idx_city (city),
    INDEX idx_police_beat (police_beat),
    INDEX idx_ems_district (ems_district),
    INDEX idx_fire_quadrant (fire_quadrant),
    INDEX idx_coordinates (latitude_y, longitude_x)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INCIDENTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS incidents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id BIGINT UNSIGNED NOT NULL,
    
    incident_number VARCHAR(50) NOT NULL COMMENT 'Incident Number from XML',
    incident_type VARCHAR(100),
    type_description VARCHAR(255),
    agency_type VARCHAR(50) COMMENT 'Police, Fire, EMS',
    case_number VARCHAR(100),
    jurisdiction VARCHAR(50),
    create_datetime DATETIME,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    INDEX idx_call_id (call_id),
    INDEX idx_incident_number (incident_number),
    INDEX idx_agency_type (agency_type),
    INDEX idx_case_number (case_number),
    INDEX idx_jurisdiction (jurisdiction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- UNITS
-- ============================================================================

CREATE TABLE IF NOT EXISTS units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id BIGINT UNSIGNED NOT NULL,
    
    unit_number VARCHAR(50) NOT NULL,
    unit_type VARCHAR(50) COMMENT 'Patrol, Engine, Ambulance, etc.',
    is_primary BOOLEAN DEFAULT FALSE,
    jurisdiction VARCHAR(50),
    
    -- Timestamps for unit activity
    assigned_datetime DATETIME,
    dispatch_datetime DATETIME,
    enroute_datetime DATETIME,
    arrive_datetime DATETIME,
    staged_datetime DATETIME,
    at_patient_datetime DATETIME,
    transport_datetime DATETIME,
    at_hospital_datetime DATETIME,
    depart_hospital_datetime DATETIME,
    clear_datetime DATETIME,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    UNIQUE KEY uk_call_unit (call_id, unit_number),
    INDEX idx_call_id (call_id),
    INDEX idx_unit_number (unit_number),
    INDEX idx_jurisdiction (jurisdiction),
    INDEX idx_is_primary (is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- UNIT PERSONNEL
-- ============================================================================

CREATE TABLE IF NOT EXISTS unit_personnel (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unit_id BIGINT UNSIGNED NOT NULL,
    
    first_name VARCHAR(100),
    middle_name VARCHAR(100),
    last_name VARCHAR(100),
    id_number VARCHAR(50),
    shield_number VARCHAR(50),
    jurisdiction VARCHAR(50),
    is_primary_officer BOOLEAN DEFAULT FALSE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE,
    INDEX idx_unit_id (unit_id),
    INDEX idx_id_number (id_number),
    INDEX idx_shield_number (shield_number),
    INDEX idx_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- UNIT LOGS
-- ============================================================================

CREATE TABLE IF NOT EXISTS unit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unit_id BIGINT UNSIGNED NOT NULL,
    
    log_datetime DATETIME NOT NULL,
    status VARCHAR(100) NOT NULL COMMENT 'Dispatched, Arrived, Available, etc.',
    location VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Location information from log',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE,
    UNIQUE KEY uk_unit_log (unit_id, log_datetime, status, location(255)),
    INDEX idx_unit_id (unit_id),
    INDEX idx_log_datetime (log_datetime),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- NARRATIVES
-- ============================================================================

CREATE TABLE IF NOT EXISTS narratives (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id BIGINT UNSIGNED NOT NULL,
    
    create_datetime DATETIME NOT NULL,
    create_user VARCHAR(100) NOT NULL DEFAULT '',
    narrative_type VARCHAR(50) COMMENT 'UserEntry, System, etc.',
    restriction VARCHAR(50) COMMENT 'General, Confidential, etc.',
    text TEXT NOT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    UNIQUE KEY uk_narrative (call_id, create_datetime, create_user, text(255)),
    INDEX idx_call_id (call_id),
    INDEX idx_create_datetime (create_datetime),
    INDEX idx_create_user (create_user),
    INDEX idx_type (narrative_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PERSONS
-- ============================================================================

CREATE TABLE IF NOT EXISTS persons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id BIGINT UNSIGNED NOT NULL,
    
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
    height_inches INT,
    weight INT,
    hair_color VARCHAR(50),
    eye_color VARCHAR(50),
    
    -- Role
    role VARCHAR(100) COMMENT 'Inquiry, Suspect, Victim, Witness, etc.',
    primary_caller_flag BOOLEAN DEFAULT FALSE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    INDEX idx_call_id (call_id),
    INDEX idx_name (last_name, first_name),
    INDEX idx_role (role),
    INDEX idx_primary_caller (primary_caller_flag),
    INDEX idx_license (license_number, license_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- VEHICLES
-- ============================================================================

CREATE TABLE IF NOT EXISTS vehicles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id BIGINT UNSIGNED NOT NULL,
    
    -- Vehicle identification
    license_plate VARCHAR(20),
    license_state VARCHAR(10),
    vin VARCHAR(50),
    
    -- Vehicle details
    make VARCHAR(50),
    model VARCHAR(50),
    year INT,
    color VARCHAR(50),
    vehicle_type VARCHAR(50) COMMENT 'Car, Truck, Motorcycle, etc.',
    
    -- Registration
    registered_owner VARCHAR(255),
    
    -- Additional info
    description TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    INDEX idx_call_id (call_id),
    INDEX idx_license (license_plate, license_state),
    INDEX idx_vin (vin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DISPOSITIONS
-- ============================================================================

CREATE TABLE IF NOT EXISTS call_dispositions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id BIGINT UNSIGNED NOT NULL,
    
    disposition_name VARCHAR(100) NOT NULL,
    description VARCHAR(255),
    count INT DEFAULT 1,
    disposition_datetime DATETIME,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    INDEX idx_call_id (call_id),
    INDEX idx_name (disposition_name),
    INDEX idx_datetime (disposition_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- UNIT DISPOSITIONS (dispositions specific to a unit)
-- ============================================================================

CREATE TABLE IF NOT EXISTS unit_dispositions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unit_id BIGINT UNSIGNED NOT NULL,
    
    disposition_name VARCHAR(100) NOT NULL,
    description VARCHAR(255),
    count INT DEFAULT 1,
    disposition_datetime DATETIME,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE,
    INDEX idx_unit_id (unit_id),
    INDEX idx_name (disposition_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FILE TRACKING
-- ============================================================================

CREATE TABLE IF NOT EXISTS processed_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) UNIQUE NOT NULL,
    file_hash VARCHAR(64) NOT NULL,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('success', 'failed', 'partial') DEFAULT 'success',
    error_message TEXT,
    records_processed INT DEFAULT 0,
    
    INDEX idx_filename (filename),
    INDEX idx_processed_at (processed_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Create database if not exists
-- ============================================================================
CREATE DATABASE IF NOT EXISTS nws_cad CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Use the database
USE nws_cad;

-- Create user with proper privileges (grants to wildcard host for Docker networking)
CREATE USER IF NOT EXISTS 'nws_cad_user'@'%' IDENTIFIED BY 'nek8PRNbNxiDQzfR2eGKGw==';
GRANT ALL PRIVILEGES ON nws_cad.* TO 'nws_cad_user'@'%';

-- Also create for localhost just in case
CREATE USER IF NOT EXISTS 'nws_cad_user'@'localhost' IDENTIFIED BY 'nek8PRNbNxiDQzfR2eGKGw==';
GRANT ALL PRIVILEGES ON nws_cad.* TO 'nws_cad_user'@'localhost';

FLUSH PRIVILEGES;

-- Verify user creation
SELECT User, Host FROM mysql.user WHERE User = 'nws_cad_user';
