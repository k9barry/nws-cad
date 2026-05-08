-- database/migrations/2026-05-08-filter-refactor.pgsql.sql
-- Filter refactor: reference tables, FDID column, and indexes (PostgreSQL).
-- Idempotent: safe to re-run.

CREATE TABLE IF NOT EXISTS ref_agencies (
    id SERIAL PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    label VARCHAR(128) NOT NULL,
    kind VARCHAR(8) NOT NULL CHECK (kind IN ('police','fire','ems')),
    ori VARCHAR(16),
    fdid VARCHAR(10),
    active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order SMALLINT NOT NULL DEFAULT 100
);
CREATE INDEX IF NOT EXISTS idx_ref_agencies_kind ON ref_agencies (kind, active);
CREATE INDEX IF NOT EXISTS idx_ref_agencies_ori  ON ref_agencies (ori);
CREATE INDEX IF NOT EXISTS idx_ref_agencies_fdid ON ref_agencies (fdid);

CREATE TABLE IF NOT EXISTS ref_oris (
    ori VARCHAR(16) PRIMARY KEY,
    label VARCHAR(128) NOT NULL,
    kind VARCHAR(8) NOT NULL CHECK (kind IN ('police','fire','ems')),
    agency_id INT REFERENCES ref_agencies(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_ref_oris_agency ON ref_oris (agency_id);

CREATE TABLE IF NOT EXISTS ref_fdids (
    fdid VARCHAR(10) PRIMARY KEY,
    label VARCHAR(128) NOT NULL,
    agency_id INT REFERENCES ref_agencies(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_ref_fdids_agency ON ref_fdids (agency_id);

CREATE TABLE IF NOT EXISTS ref_beats (
    id SERIAL PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    label VARCHAR(128) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    jurisdiction VARCHAR(64),
    active BOOLEAN NOT NULL DEFAULT TRUE
);
CREATE INDEX IF NOT EXISTS idx_ref_beats_active ON ref_beats (active);

CREATE TABLE IF NOT EXISTS ref_areas (
    id SERIAL PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    label VARCHAR(128) NOT NULL,
    kind VARCHAR(16) NOT NULL CHECK (kind IN ('fire_quad','ems_district')),
    active BOOLEAN NOT NULL DEFAULT TRUE
);
CREATE INDEX IF NOT EXISTS idx_ref_areas_kind ON ref_areas (kind, active);

ALTER TABLE agency_contexts ADD COLUMN IF NOT EXISTS fdid VARCHAR(10);

CREATE INDEX IF NOT EXISTS idx_calls_create_closed ON calls (create_datetime, closed_flag, canceled_flag);
CREATE INDEX IF NOT EXISTS idx_ac_call_type   ON agency_contexts (call_type);
CREATE INDEX IF NOT EXISTS idx_ac_fdid        ON agency_contexts (fdid);
CREATE INDEX IF NOT EXISTS idx_loc_police_ori ON locations (police_ori);
CREATE INDEX IF NOT EXISTS idx_loc_ems_ori    ON locations (ems_ori);
CREATE INDEX IF NOT EXISTS idx_loc_fire_ori   ON locations (fire_ori);
CREATE INDEX IF NOT EXISTS idx_loc_police_beat ON locations (police_beat);
CREATE INDEX IF NOT EXISTS idx_loc_fire_quad  ON locations (fire_quadrant);
CREATE INDEX IF NOT EXISTS idx_loc_ems_district ON locations (ems_district);
CREATE INDEX IF NOT EXISTS idx_loc_city       ON locations (city);
CREATE INDEX IF NOT EXISTS idx_units_unit_number ON units (unit_number);
CREATE INDEX IF NOT EXISTS idx_inc_incident_type ON incidents (incident_type);
