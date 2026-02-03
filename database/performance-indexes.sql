-- Performance Optimization Indexes
-- Add composite indexes for common query patterns to improve performance
-- Skip indexes that already exist

-- Composite index for date + closed_flag filtering (most common filter combination)
-- DROP INDEX IF EXISTS idx_calls_date_closed ON calls;
-- CREATE INDEX idx_calls_date_closed ON calls(create_datetime, closed_flag);

-- Composite index for agency contexts filtering
-- CREATE INDEX idx_agency_call_agency ON agency_contexts(call_id, agency_type);

-- Add call_type index for stats queries
CREATE INDEX idx_agency_call_type ON agency_contexts(call_id, call_type);

-- Composite index for incidents jurisdiction filtering  
CREATE INDEX idx_incidents_call_jurisdiction ON incidents(call_id, jurisdiction);

-- Composite index for location coordinates (map queries)
CREATE INDEX idx_locations_call_coords ON locations(call_id, latitude_y, longitude_x);

-- Index for units assigned datetime (date range filtering)
CREATE INDEX idx_units_assigned_datetime ON units(assigned_datetime);

-- Covering index for common call queries (avoid table lookups)
-- Note: nature_of_call is TEXT so we use first 100 chars
CREATE INDEX idx_calls_coverage ON calls(id, call_number, create_datetime, closed_flag, canceled_flag, nature_of_call(100));
