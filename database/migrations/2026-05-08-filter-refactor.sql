-- database/migrations/2026-05-08-filter-refactor.sql
-- Filter refactor: reference tables, FDID column, and indexes.
-- Idempotent: safe to re-run.

CREATE TABLE IF NOT EXISTS ref_agencies (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL,
    label VARCHAR(128) NOT NULL,
    kind ENUM('police','fire','ems') NOT NULL,
    ori VARCHAR(16) DEFAULT NULL,
    fdid VARCHAR(10) DEFAULT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order SMALLINT NOT NULL DEFAULT 100,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ref_agencies_code (code),
    KEY idx_ref_agencies_kind (kind, active),
    KEY idx_ref_agencies_ori (ori),
    KEY idx_ref_agencies_fdid (fdid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_oris (
    ori VARCHAR(16) NOT NULL,
    label VARCHAR(128) NOT NULL,
    kind ENUM('police','fire','ems') NOT NULL,
    agency_id INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (ori),
    KEY idx_ref_oris_agency (agency_id),
    CONSTRAINT fk_ref_oris_agency FOREIGN KEY (agency_id) REFERENCES ref_agencies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_fdids (
    fdid VARCHAR(10) NOT NULL,
    label VARCHAR(128) NOT NULL,
    agency_id INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (fdid),
    KEY idx_ref_fdids_agency (agency_id),
    CONSTRAINT fk_ref_fdids_agency FOREIGN KEY (agency_id) REFERENCES ref_agencies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_beats (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL,
    label VARCHAR(128) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    jurisdiction VARCHAR(64) DEFAULT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ref_beats_code (code),
    KEY idx_ref_beats_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_areas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL,
    label VARCHAR(128) NOT NULL,
    kind ENUM('fire_quad','ems_district') NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ref_areas_code (code),
    KEY idx_ref_areas_kind (kind, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New column on agency_contexts (idempotent via INFORMATION_SCHEMA check)
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agency_contexts' AND COLUMN_NAME = 'fdid');
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE agency_contexts ADD COLUMN fdid VARCHAR(10) NULL AFTER agency_type',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indexes (idempotent: CREATE INDEX IF NOT EXISTS works on MySQL 8.0.29+; use procedure for safety)
DELIMITER $$
DROP PROCEDURE IF EXISTS ensure_index $$
CREATE PROCEDURE ensure_index(IN tbl VARCHAR(64), IN idx VARCHAR(64), IN cols VARCHAR(255))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND INDEX_NAME = idx) THEN
        SET @s := CONCAT('CREATE INDEX ', idx, ' ON ', tbl, ' (', cols, ')');
        PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END $$
DELIMITER ;

CALL ensure_index('calls', 'idx_calls_create_closed', 'create_datetime, closed_flag, canceled_flag');
CALL ensure_index('agency_contexts', 'idx_ac_call_type', 'call_type');
CALL ensure_index('agency_contexts', 'idx_ac_fdid', 'fdid');
CALL ensure_index('locations', 'idx_loc_police_ori', 'police_ori');
CALL ensure_index('locations', 'idx_loc_ems_ori', 'ems_ori');
CALL ensure_index('locations', 'idx_loc_fire_ori', 'fire_ori');
CALL ensure_index('locations', 'idx_loc_police_beat', 'police_beat');
CALL ensure_index('locations', 'idx_loc_fire_quad', 'fire_quadrant');
CALL ensure_index('locations', 'idx_loc_ems_district', 'ems_district');
CALL ensure_index('locations', 'idx_loc_city', 'city');
CALL ensure_index('units', 'idx_units_unit_number', 'unit_number');
CALL ensure_index('incidents', 'idx_inc_incident_type', 'incident_type');

DROP PROCEDURE ensure_index;
