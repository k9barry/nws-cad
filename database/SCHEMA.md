# Database Schema Guide

## Overview

This document describes the database schema used by the NWS CAD system and provides guidance on customizing it for your specific needs.

## Current Schema

The default schema includes three main tables designed to store CAD event data and track XML file processing.

### Table: cad_events

Stores individual CAD events parsed from XML files.

**Columns:**
- `id` - Auto-incrementing primary key
- `event_id` - Unique event identifier (from XML)
- `event_type` - Type of event (emergency, fire, police, etc.)
- `event_time` - Timestamp when the event occurred
- `location` - Event location description
- `description` - Detailed event description
- `priority` - Event priority level (high, medium, low, etc.)
- `status` - Current event status (active, pending, closed, etc.)
- `xml_data` - Full XML data stored as JSON/JSONB for reference
- `created_at` - Record creation timestamp
- `updated_at` - Record last update timestamp

**Indexes:**
- `event_id` - For quick lookups by event ID
- `event_time` - For time-based queries
- `status` - For filtering by status

### Table: processed_files

Tracks all XML files that have been processed by the system.

**Columns:**
- `id` - Auto-incrementing primary key
- `filename` - Name of the processed file (unique)
- `file_hash` - SHA-256 hash of file contents
- `processed_at` - Timestamp when file was processed
- `status` - Processing status (success, failed, partial)
- `error_message` - Error details if processing failed
- `records_processed` - Number of records extracted from the file

**Indexes:**
- `filename` - For quick file lookup
- `processed_at` - For time-based queries

### Table: xml_metadata

Stores metadata extracted from XML files.

**Columns:**
- `id` - Auto-incrementing primary key
- `file_id` - Foreign key to processed_files table
- `metadata_key` - Metadata key/name
- `metadata_value` - Metadata value
- `created_at` - Record creation timestamp

**Indexes:**
- `file_id` - For retrieving metadata by file
- `metadata_key` - For filtering by metadata type

## Customizing the Schema

### Step 1: Define Your Requirements

Before modifying the schema, document:
1. What data fields are in your XML files?
2. What queries will you need to run?
3. What reports or dashboards will you create?

### Step 2: Modify the SQL Files

Edit both database initialization files to match your needs:

**MySQL:** `database/mysql/init.sql`
**PostgreSQL:** `database/postgres/init.sql`

### Step 3: Update the XML Parser

Modify `src/XmlParser.php` to match your new schema:

```php
private function extractEventData(SimpleXMLElement $event): array
{
    return [
        // Map your XML structure to database fields
        'field_name' => (string)$event->xml_element,
        // Add all your custom fields
    ];
}
```

### Step 4: Update Insert Queries

Modify the `insertEvent()` method in `src/XmlParser.php`:

```php
$sql = "INSERT INTO your_table 
        (field1, field2, field3)
        VALUES (:field1, :field2, :field3)";
```

### Step 5: Reset the Database

```bash
# Stop services
docker-compose down -v

# Start services (will recreate databases)
docker-compose up -d
```

## Example: Custom Schema

Here's an example of a more detailed CAD schema:

```sql
-- Incidents table
CREATE TABLE incidents (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    incident_number VARCHAR(50) UNIQUE NOT NULL,
    incident_type VARCHAR(50),
    received_datetime DATETIME,
    dispatch_datetime DATETIME,
    arrival_datetime DATETIME,
    cleared_datetime DATETIME,
    address VARCHAR(255),
    city VARCHAR(100),
    state VARCHAR(2),
    zip VARCHAR(10),
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    priority_level INT,
    nature_of_call TEXT,
    notes TEXT,
    status VARCHAR(50),
    INDEX idx_incident_number (incident_number),
    INDEX idx_received (received_datetime),
    INDEX idx_status (status)
);

-- Units table
CREATE TABLE units (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    unit_identifier VARCHAR(50) NOT NULL,
    unit_type VARCHAR(50),
    station VARCHAR(50),
    INDEX idx_unit_id (unit_identifier)
);

-- Unit dispatches (many-to-many)
CREATE TABLE unit_dispatches (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    incident_id BIGINT NOT NULL,
    unit_id BIGINT NOT NULL,
    dispatched_at DATETIME,
    arrived_at DATETIME,
    cleared_at DATETIME,
    unit_status VARCHAR(50),
    FOREIGN KEY (incident_id) REFERENCES incidents(id),
    FOREIGN KEY (unit_id) REFERENCES units(id),
    INDEX idx_incident (incident_id),
    INDEX idx_unit (unit_id)
);
```

## Data Types Reference

### MySQL vs PostgreSQL

| Data Type | MySQL | PostgreSQL |
|-----------|-------|------------|
| Auto-increment | BIGINT UNSIGNED AUTO_INCREMENT | BIGSERIAL |
| Text | TEXT, VARCHAR | TEXT, VARCHAR |
| JSON | JSON | JSONB |
| Timestamp | TIMESTAMP, DATETIME | TIMESTAMP |
| Enum | ENUM('val1', 'val2') | VARCHAR with CHECK |

## Performance Optimization

### Indexes

Add indexes for:
- Columns used in WHERE clauses
- Columns used in JOIN conditions
- Columns used in ORDER BY
- Foreign key columns

### Partitioning (Large Datasets)

For very large datasets, consider partitioning by:
- Date ranges (monthly, yearly)
- Event types
- Geographic regions

### Example MySQL Partitioning:

```sql
CREATE TABLE cad_events (
    -- columns
) PARTITION BY RANGE (YEAR(event_time)) (
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p2025 VALUES LESS THAN (2026),
    PARTITION p2026 VALUES LESS THAN (2027)
);
```

## Best Practices

1. **Use appropriate data types** - Don't use VARCHAR(255) for everything
2. **Add constraints** - NOT NULL, UNIQUE, CHECK where appropriate
3. **Index wisely** - Too many indexes slow down writes
4. **Consider normalization** - Avoid data duplication
5. **Plan for growth** - Use BIGINT for IDs if expecting large volumes
6. **Document changes** - Keep this file updated with schema changes

## Migration Strategy

When changing schema in production:

1. Create migration SQL files
2. Test on development database
3. Back up production database
4. Apply migrations during maintenance window
5. Verify data integrity
6. Update application code

## Schema Validation

Validate your schema:

```bash
# MySQL
docker-compose exec mysql mysql -u nws_user -p nws_cad -e "SHOW TABLES;"
docker-compose exec mysql mysql -u nws_user -p nws_cad -e "DESCRIBE cad_events;"

# PostgreSQL
docker-compose exec postgres psql -U nws_user -d nws_cad -c "\dt"
docker-compose exec postgres psql -U nws_user -d nws_cad -c "\d cad_events"
```

## Troubleshooting

### Schema Not Created

```bash
# Check init script logs
docker-compose logs mysql
docker-compose logs postgres

# Manually run init script
docker-compose exec mysql mysql -u root -p < /docker-entrypoint-initdb.d/init.sql
```

### Data Type Mismatches

Ensure your PHP code matches database types:
- Use DateTime objects for timestamps
- Use proper escaping for strings
- Validate data before inserting

## Resources

- [MySQL Documentation](https://dev.mysql.com/doc/)
- [PostgreSQL Documentation](https://www.postgresql.org/docs/)
- [Database Design Best Practices](https://www.postgresql.org/docs/current/ddl.html)
