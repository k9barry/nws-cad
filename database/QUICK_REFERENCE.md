# NWS Aegis CAD Schema Quick Reference

## Schema at a Glance

**13 Tables | 150+ Columns | 51 Indexes | 12 Foreign Keys**

## Table Structure

```
┌─────────────────────┐
│      CALLS          │ ← ROOT TABLE
│  - call_id (UK)     │
│  - call_number      │
│  - create_datetime  │
│  - xml_data (JSON)  │
└──────────┬──────────┘
           │
           ├─────────────────────────────────────────────────┐
           │                                                 │
    ┌──────▼──────────┐  ┌──────────────┐  ┌─────────────┐ │
    │ AGENCY_CONTEXTS │  │  LOCATIONS   │  │  INCIDENTS  │ │
    │ - agency_type   │  │ - full_addr  │  │ - inc_num   │ │
    │ - priority      │  │ - lat/long   │  │ - type      │ │
    │ - dispatcher    │  │ - beat/dist  │  │ - case_num  │ │
    └─────────────────┘  └──────────────┘  └─────────────┘ │
           │                                                 │
    ┌──────▼──────────┐  ┌──────────────┐  ┌─────────────┐ │
    │     UNITS       │  │  NARRATIVES  │  │   PERSONS   │ │
    │ - unit_number   │  │ - text       │  │ - name      │ │
    │ - timestamps×10 │  │ - user       │  │ - role      │ │
    └──────┬──────────┘  │ - datetime   │  │ - license   │ │
           │             └──────────────┘  └─────────────┘ │
           │                                                │
    ┌──────▼─────────┐   ┌──────────────┐  ┌─────────────┐ │
    │ UNIT_PERSONNEL │   │   VEHICLES   │  │ CALL_DISPOS │◄┘
    │ - first_name   │   │ - plate/vin  │  │ - name      │
    │ - shield_num   │   │ - make/model │  │ - datetime  │
    └────────────────┘   └──────────────┘  └─────────────┘
           │
    ┌──────▼──────────┐
    │   UNIT_LOGS     │
    │ - datetime      │
    │ - status        │
    └─────────────────┘
           │
    ┌──────▼──────────┐
    │ UNIT_DISPOS     │
    │ - name          │
    └─────────────────┘

    ┌─────────────────┐
    │ PROCESSED_FILES │ ← TRACKING TABLE (independent)
    │ - filename (UK) │
    │ - file_hash     │
    └─────────────────┘
```

## Primary Indexes

| Table | Key Indexes |
|-------|-------------|
| **calls** | call_id (UK), call_number, create_datetime, close_datetime |
| **agency_contexts** | call_id (FK), agency_type, status, dispatcher |
| **locations** | call_id (FK), city, police_beat, coordinates |
| **incidents** | call_id (FK), incident_number, agency_type, case_number |
| **units** | call_id (FK), unit_number, jurisdiction |
| **unit_personnel** | unit_id (FK), id_number, shield_number, name |
| **narratives** | call_id (FK), create_datetime, create_user |
| **persons** | call_id (FK), name, role, license |
| **vehicles** | call_id (FK), license, vin |

## Field Counts by Table

| Table | Columns | Nullable | Timestamps |
|-------|---------|----------|------------|
| calls | 16 | 10 | 3 |
| agency_contexts | 13 | 11 | 4 |
| locations | 37 | 35 | 2 |
| incidents | 9 | 5 | 2 |
| units | 14 | 10 | 12 |
| unit_personnel | 9 | 5 | 2 |
| unit_logs | 5 | 0 | 2 |
| narratives | 7 | 2 | 1 |
| persons | 20 | 18 | 2 |
| vehicles | 12 | 10 | 2 |
| call_dispositions | 6 | 3 | 1 |
| unit_dispositions | 6 | 3 | 1 |
| processed_files | 7 | 2 | 1 |

## Critical Fields Reference

### Call Tracking
- `calls.call_id` - NWS system ID (unique)
- `calls.call_number` - Human-readable call number
- `calls.create_datetime` - Call creation time
- `calls.close_datetime` - Call closed time

### Location
- `locations.full_address` - Complete address string
- `locations.latitude_y`, `longitude_x` - GPS coordinates
- `locations.police_beat` - Police beat/zone
- `locations.ems_district` - EMS district
- `locations.fire_quadrant` - Fire quadrant

### Unit Timeline (10 timestamps)
1. assigned_datetime
2. dispatch_datetime
3. enroute_datetime
4. arrive_datetime
5. staged_datetime
6. at_patient_datetime (EMS)
7. transport_datetime (EMS)
8. at_hospital_datetime (EMS)
9. depart_hospital_datetime (EMS)
10. clear_datetime

### Person Information
- `persons.role` - Inquiry, Suspect, Victim, Witness, etc.
- `persons.primary_caller_flag` - Is this the caller?
- `persons.license_number`, `license_state` - Driver's license
- Physical descriptors: sex, race, height, weight, hair, eyes

## Common Queries

### Get complete call details
```sql
SELECT c.*, l.full_address, l.police_beat
FROM calls c
LEFT JOIN locations l ON c.id = l.call_id
WHERE c.call_number = '285';
```

### Get all units for a call
```sql
SELECT u.unit_number, u.unit_type, u.is_primary,
       u.dispatch_datetime, u.arrive_datetime, u.clear_datetime
FROM units u
JOIN calls c ON u.call_id = c.id
WHERE c.call_number = '260'
ORDER BY u.is_primary DESC;
```

### Get unit personnel
```sql
SELECT u.unit_number, up.first_name, up.last_name, 
       up.shield_number, up.is_primary_officer
FROM units u
JOIN unit_personnel up ON u.id = up.unit_id
JOIN calls c ON u.call_id = c.id
WHERE c.call_number = '285';
```

### Get call timeline
```sql
SELECT create_datetime, create_user, text
FROM narratives
WHERE call_id = (SELECT id FROM calls WHERE call_number = '260')
ORDER BY create_datetime;
```

### Response time analysis
```sql
SELECT 
    u.unit_number,
    u.dispatch_datetime,
    u.arrive_datetime,
    TIMESTAMPDIFF(MINUTE, u.dispatch_datetime, u.arrive_datetime) as response_minutes
FROM units u
JOIN calls c ON u.call_id = c.id
WHERE DATE(c.create_datetime) = '2022-12-03'
  AND u.arrive_datetime IS NOT NULL
ORDER BY response_minutes;
```

## Data Type Reference

### MySQL → PostgreSQL Mapping
- `BIGINT UNSIGNED` → `BIGINT`
- `AUTO_INCREMENT` → `SERIAL/BIGSERIAL`
- `DATETIME` → `TIMESTAMP`
- `BOOLEAN` → `BOOLEAN`
- `JSON` → `JSONB`
- `DECIMAL(10,7)` → `DECIMAL(10,7)`
- `ENUM(...)` → `VARCHAR + CHECK`

## File Information

- **MySQL**: `database/mysql/init.sql` (426 lines, 15 KB)
- **PostgreSQL**: `database/postgres/init.sql` (479 lines, 16 KB)
- **Documentation**: `database/SCHEMA.md` (comprehensive guide)

## Maintenance Commands

### View all tables
```bash
# MySQL
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "SHOW TABLES;"

# PostgreSQL
docker-compose exec postgres psql -U nws_user -d nws_cad -c "\dt"
```

### Check table structure
```bash
# MySQL
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "DESCRIBE calls;"

# PostgreSQL
docker-compose exec postgres psql -U nws_user -d nws_cad -c "\d calls"
```

### View indexes
```bash
# MySQL
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "SHOW INDEX FROM calls;"

# PostgreSQL
docker-compose exec postgres psql -U nws_user -d nws_cad -c "\di"
```

### Row counts
```bash
# MySQL
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "
    SELECT table_name, table_rows 
    FROM information_schema.tables 
    WHERE table_schema = 'nws_cad';"

# PostgreSQL
docker-compose exec postgres psql -U nws_user -d nws_cad -c "
    SELECT schemaname, tablename, n_live_tup as rows
    FROM pg_stat_user_tables;"
```
