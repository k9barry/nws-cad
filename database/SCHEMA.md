# NWS Aegis CAD Database Schema

## Overview

This document describes the comprehensive database schema for the New World Systems (NWS) Aegis CAD XML export format. The schema captures all data from CAD events including calls, units, personnel, incidents, locations, narratives, persons, vehicles, and dispositions.

## Schema Architecture

The schema is designed with proper normalization and parent-child relationships to accurately represent the hierarchical structure of CAD data. All tables include appropriate foreign keys, indexes, and data types for optimal performance and data integrity.

## Core Tables

### Table: calls

The central table storing the main CAD call/event information from the `<Call>` root element.

**Key Columns:**
- `id` - Auto-incrementing primary key
- `call_id` - Unique CallId from NWS (BIGINT, indexed, unique)
- `call_number` - Call number for reference (e.g., "285", "260")
- `call_source` - How the call came in (Phone, Radio, Walk-in, etc.)
- `caller_name` - Name of the person who called
- `caller_phone` - Phone number of caller
- `nature_of_call` - Description of the incident nature
- `create_datetime` - When the call was created
- `close_datetime` - When the call was closed (nullable)
- `created_by` - User who created the call
- `closed_flag` - Whether the call is closed (boolean)
- `canceled_flag` - Whether the call was canceled (boolean)
- `alarm_level` - Alarm level (integer)
- `emd_code` - Emergency Medical Dispatch code
- `xml_data` - Complete XML stored as JSON/JSONB for reference

**Indexes:**
- Unique index on `call_id`
- Indexes on `call_number`, `create_datetime`, `close_datetime`, `closed_flag`, `created_by`

### Table: agency_contexts

Stores agency-specific context for each call from `<AgencyContexts>/<AgencyContext>`.

**Key Columns:**
- `call_id` - Foreign key to calls table
- `agency_type` - Police, Fire, EMS
- `call_type` - Type of call for this agency (e.g., "Accident - PD", "Trouble With")
- `priority` - Priority level (e.g., "1 - Police High", "4 - No Status Checks")
- `status` - Current status (e.g., "In Progress")
- `dispatcher` - Dispatcher name
- `radio_channel` - Radio channel used
- `created_datetime`, `closed_datetime` - Agency-specific timestamps
- `emd_case_number`, `emd_code` - EMD information

**Indexes:** `call_id`, `agency_type`, `status`, `dispatcher`

### Table: locations

Stores detailed location information for each call from `<Location>`.

**Key Columns:**
- `call_id` - Foreign key to calls table
- `full_address` - Complete address string
- `house_number`, `street_name`, `street_type`, `city`, `state`, `zip` - Parsed address components
- `prefix_directional` - N, S, E, W
- `common_name` - Building/landmark name (e.g., "Loves Travel Stops")
- `latitude_y`, `longitude_x` - Coordinates (DECIMAL 10,7)
- `nearest_cross_streets` - Cross street information
- `police_beat`, `ems_district`, `fire_quadrant` - Response zones
- `census_tract`, `rural_grid`, `station_area` - Geographic zones

**Indexes:** `call_id`, `city`, `police_beat`, `ems_district`, `fire_quadrant`, `coordinates`

### Table: incidents

Stores incident information from `<Incidents>/<Incident>`.

**Key Columns:**
- `call_id` - Foreign key to calls table
- `incident_number` - Incident number (e.g., "2022-00042208")
- `incident_type` - Type of incident
- `type_description` - Description of incident type
- `agency_type` - Police, Fire, EMS
- `case_number` - Case number if assigned
- `jurisdiction` - Jurisdiction code
- `create_datetime` - When incident was created

**Indexes:** `call_id`, `incident_number`, `agency_type`, `case_number`, `jurisdiction`

### Table: units

Stores unit dispatch information from `<AssignedUnits>/<Unit>`.

**Key Columns:**
- `call_id` - Foreign key to calls table
- `unit_number` - Unit identifier (e.g., "103", "IN7")
- `unit_type` - Type of unit (Patrol, Engine, Ambulance, etc.)
- `is_primary` - Whether this is the primary unit (boolean)
- `jurisdiction` - Jurisdiction code
- **Timestamps:** `assigned_datetime`, `dispatch_datetime`, `enroute_datetime`, `arrive_datetime`, `staged_datetime`, `at_patient_datetime`, `transport_datetime`, `at_hospital_datetime`, `depart_hospital_datetime`, `clear_datetime`

**Indexes:** `call_id`, `unit_number`, `jurisdiction`, `is_primary`

### Table: unit_personnel

Stores personnel assigned to units from `<Personnel>/<UnitPersonnel>`.

**Key Columns:**
- `unit_id` - Foreign key to units table
- `first_name`, `middle_name`, `last_name` - Personnel name
- `id_number` - Employee ID
- `shield_number` - Badge/shield number
- `jurisdiction` - Jurisdiction code
- `is_primary_officer` - Whether this is the primary officer (boolean)

**Indexes:** `unit_id`, `id_number`, `shield_number`, `name`

### Table: unit_logs

Stores unit status logs from `<UnitLogs>/<UnitLog>`.

**Key Columns:**
- `unit_id` - Foreign key to units table
- `log_datetime` - Timestamp of status change
- `status` - Status (Dispatched, Arrived, Available, etc.)

**Indexes:** `unit_id`, `log_datetime`, `status`

### Table: narratives

Stores narrative entries from `<Narratives>/<Narrative>`.

**Key Columns:**
- `call_id` - Foreign key to calls table
- `create_datetime` - When narrative was created
- `create_user` - User who created the narrative
- `narrative_type` - Type (UserEntry, System, etc.)
- `restriction` - Access restriction level (General, Confidential, etc.)
- `text` - Narrative text content

**Indexes:** `call_id`, `create_datetime`, `create_user`, `narrative_type`

### Table: persons

Stores person information from `<Persons>/<Person>`.

**Key Columns:**
- `call_id` - Foreign key to calls table
- `first_name`, `middle_name`, `last_name`, `name_suffix` - Person name
- `contact_phone`, `address` - Contact information
- `date_of_birth`, `ssn`, `global_subject_id` - Identification
- `license_number`, `license_state` - Driver's license
- `sex`, `race`, `height_inches`, `weight`, `hair_color`, `eye_color` - Physical descriptors
- `role` - Role in call (Inquiry, Suspect, Victim, Witness, etc.)
- `primary_caller_flag` - Whether this is the primary caller (boolean)

**Indexes:** `call_id`, `name`, `role`, `primary_caller_flag`, `license`

### Table: vehicles

Stores vehicle information from `<Vehicles>`.

**Key Columns:**
- `call_id` - Foreign key to calls table
- `license_plate`, `license_state` - License plate information
- `vin` - Vehicle Identification Number
- `make`, `model`, `year`, `color` - Vehicle description
- `vehicle_type` - Type of vehicle (Car, Truck, Motorcycle, etc.)
- `registered_owner` - Owner name
- `description` - Additional description

**Indexes:** `call_id`, `license`, `vin`

### Table: call_dispositions

Stores call-level dispositions from `<Dispositions>/<CallDisposition>`.

**Key Columns:**
- `call_id` - Foreign key to calls table
- `disposition_name` - Name of disposition (e.g., "Report Taken")
- `description` - Disposition description
- `count` - Number of times applied
- `disposition_datetime` - When disposition was applied

**Indexes:** `call_id`, `disposition_name`, `disposition_datetime`

### Table: unit_dispositions

Stores unit-specific dispositions (when present in unit data).

**Key Columns:**
- `unit_id` - Foreign key to units table
- `disposition_name`, `description`, `count`, `disposition_datetime` - Same as call_dispositions

**Indexes:** `unit_id`, `disposition_name`

### Table: processed_files

Tracks processed XML files to prevent duplicate processing.

**Key Columns:**
- `filename` - Name of processed file (unique)
- `file_hash` - SHA-256 hash of file contents
- `processed_at` - Processing timestamp
- `status` - success, failed, or partial
- `error_message` - Error details if failed
- `records_processed` - Number of records extracted

**Indexes:** `filename`, `processed_at`, `status`

## Relationships and Foreign Keys

The schema maintains the following parent-child relationships:

```
calls (root)
├── agency_contexts (1:many)
├── locations (1:1)
├── incidents (1:many)
├── units (1:many)
│   ├── unit_personnel (1:many)
│   ├── unit_logs (1:many)
│   └── unit_dispositions (1:many)
├── narratives (1:many)
├── persons (1:many)
├── vehicles (1:many)
└── call_dispositions (1:many)
```

All child tables use `ON DELETE CASCADE` to maintain referential integrity.

## Data Type Mappings

### MySQL vs PostgreSQL

| Purpose | MySQL | PostgreSQL |
|---------|-------|------------|
| Auto-increment ID | BIGINT UNSIGNED AUTO_INCREMENT | BIGSERIAL |
| Text fields | TEXT, VARCHAR(n) | TEXT, VARCHAR(n) |
| JSON storage | JSON | JSONB |
| Timestamps | DATETIME, TIMESTAMP | TIMESTAMP |
| Booleans | BOOLEAN (TINYINT) | BOOLEAN |
| Decimals | DECIMAL(10,7) | DECIMAL(10,7) |
| Enums | ENUM('a','b') | VARCHAR with CHECK |

### NULL Handling

Many fields can be NULL because the XML frequently contains `i:nil="true"` attributes. Fields marked as NOT NULL:
- Primary keys (id)
- Foreign keys (call_id, unit_id)
- Essential timestamps (create_datetime)
- Critical identifiers (call_number, incident_number, unit_number)

## Indexes and Performance

### Index Strategy

1. **Primary Keys**: All tables have auto-incrementing primary keys
2. **Foreign Keys**: All foreign key columns are indexed
3. **Unique Constraints**: `calls.call_id` is unique and indexed
4. **Frequently Queried Fields**:
   - Call numbers and incident numbers
   - Timestamps (for date range queries)
   - Status fields
   - Geographic fields (beats, districts, quadrants)
   - Personnel identifiers (ID numbers, shield numbers)
5. **Composite Indexes**: Coordinates (latitude, longitude) for spatial queries

### Query Optimization Examples

```sql
-- Find all calls in a specific beat on a date
SELECT c.* FROM calls c
JOIN locations l ON c.id = l.call_id
WHERE l.police_beat = 'AN3'
  AND DATE(c.create_datetime) = '2022-12-03';

-- Get all units assigned to a call with personnel
SELECT c.call_number, u.unit_number, u.unit_type,
       up.first_name, up.last_name, up.shield_number
FROM calls c
JOIN units u ON c.id = u.call_id
JOIN unit_personnel up ON u.id = up.unit_id
WHERE c.call_number = '285';

-- Find all narratives for an incident
SELECT n.create_datetime, n.create_user, n.text
FROM calls c
JOIN narratives n ON c.id = n.call_id
WHERE c.call_number = '260'
ORDER BY n.create_datetime;
```

## Unit Timeline Tracking

Units have comprehensive timestamp tracking for complete lifecycle management:

1. **assigned_datetime** - Unit assigned to call
2. **dispatch_datetime** - Unit dispatched
3. **enroute_datetime** - Unit en route
4. **arrive_datetime** - Unit arrived on scene
5. **staged_datetime** - Unit staged (if applicable)
6. **at_patient_datetime** - Unit at patient (EMS)
7. **transport_datetime** - Transporting patient (EMS)
8. **at_hospital_datetime** - At hospital (EMS)
9. **depart_hospital_datetime** - Departing hospital (EMS)
10. **clear_datetime** - Unit cleared/available

These timestamps enable response time analysis and performance metrics.

## XML Data Storage

Each call stores the complete original XML in the `xml_data` field as:
- **MySQL**: JSON type
- **PostgreSQL**: JSONB type (better performance for querying)

This allows:
- Recovery of any data not explicitly parsed
- Validation of parsed data
- Future schema evolution without data loss
- Debugging and auditing

## Schema Validation Commands

### MySQL
```bash
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "SHOW TABLES;"
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "DESCRIBE calls;"
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "SHOW INDEX FROM calls;"
```

### PostgreSQL
```bash
docker-compose exec postgres psql -U nws_user -d nws_cad -c "\dt"
docker-compose exec postgres psql -U nws_user -d nws_cad -c "\d calls"
docker-compose exec postgres psql -U nws_user -d nws_cad -c "\di"
```

## Customization Guide

### Adding Custom Fields

If your NWS CAD export includes additional fields:

1. **Identify the XML element** containing the new data
2. **Determine the appropriate table** (or create a new one)
3. **Add the column** to both MySQL and PostgreSQL schemas
4. **Update the parser** (`src/XmlParser.php`) to extract the field
5. **Rebuild the database** with `docker-compose down -v && docker-compose up -d`

### Example: Adding a Custom Field

```sql
-- MySQL
ALTER TABLE calls ADD COLUMN custom_field VARCHAR(100);
CREATE INDEX idx_custom_field ON calls(custom_field);

-- PostgreSQL
ALTER TABLE calls ADD COLUMN custom_field VARCHAR(100);
CREATE INDEX idx_custom_field ON calls(custom_field);
```

### Adding a New Table

For complex custom data, create a new related table:

```sql
-- MySQL
CREATE TABLE custom_data (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id BIGINT UNSIGNED NOT NULL,
    custom_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    INDEX idx_call_id (call_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PostgreSQL
CREATE TABLE custom_data (
    id BIGSERIAL PRIMARY KEY,
    call_id BIGINT NOT NULL,
    custom_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
);
CREATE INDEX idx_custom_data_call_id ON custom_data(call_id);
```

## Best Practices

1. **Always use transactions** when inserting related records
2. **Validate foreign keys** before inserting child records
3. **Handle NULL values** appropriately (many XML fields can be nil)
4. **Use prepared statements** to prevent SQL injection
5. **Index wisely** - too many indexes slow writes, too few slow reads
6. **Monitor query performance** using EXPLAIN
7. **Regular backups** of production data
8. **Test schema changes** on development database first

## Performance Considerations

### For Large Datasets (>1 million calls)

1. **Partitioning**: Consider partitioning `calls` table by date
   ```sql
   -- MySQL example
   PARTITION BY RANGE (YEAR(create_datetime)) (
       PARTITION p2022 VALUES LESS THAN (2023),
       PARTITION p2023 VALUES LESS THAN (2024),
       PARTITION p2024 VALUES LESS THAN (2025)
   );
   ```

2. **Archiving**: Move old data to archive tables
3. **Index Maintenance**: Regular ANALYZE/OPTIMIZE
4. **Connection Pooling**: Use connection pools in production
5. **Read Replicas**: Consider read replicas for reporting

### Query Performance Tips

- Use `EXPLAIN` to analyze query execution plans
- Avoid `SELECT *` - specify needed columns
- Use covering indexes where possible
- Batch inserts for bulk operations
- Use appropriate JOIN types

## Resources

- [MySQL Documentation](https://dev.mysql.com/doc/)
- [PostgreSQL Documentation](https://www.postgresql.org/docs/)
- [Database Design Best Practices](https://www.postgresql.org/docs/current/ddl.html)
