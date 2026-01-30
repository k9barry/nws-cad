# NWS CAD Database Schema - Entity Relationship Diagram

## Database Structure Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          NWS CAD DATABASE SCHEMA                        │
│                         13 Tables + Relationships                       │
└─────────────────────────────────────────────────────────────────────────┘

                    ┌──────────────────────────────────┐
                    │         CALLS (Main Table)       │
                    │  ─────────────────────────────   │
                    │  • id (PK)                       │
                    │  • call_id (UNIQUE)              │
                    │  • call_number                   │
                    │  • call_source                   │
                    │  • caller_name                   │
                    │  • caller_phone                  │
                    │  • nature_of_call                │
                    │  • create_datetime               │
                    │  • close_datetime                │
                    │  • closed_flag                   │
                    │  • canceled_flag                 │
                    │  • xml_data (JSON)               │
                    │  • created_at, updated_at        │
                    └──────────────────────────────────┘
                                  │
                                  │ (1:N relationships via call_id FK)
                                  │
        ┌────────┬────────┬───────┼────────┬────────┬────────┬────────┬────────┐
        ▼        ▼        ▼       ▼        ▼        ▼        ▼        ▼        ▼
   ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐
   │ AGENCY  │ │LOCATION │ │INCIDENT │ │  UNITS  │ │NARRATIV │ │ PERSONS │ │VEHICLES │
   │CONTEXTS │ │         │ │         │ │         │ │   ES    │ │         │ │         │
   └─────────┘ └─────────┘ └─────────┘ └─────────┘ └─────────┘ └─────────┘ └─────────┘
   • agency    • full      • incident  • unit      • create    • first     • license
     type        address     number      number      datetime    name        plate
   • call_type • city      • case      • unit_type • text      • last      • vin
   • priority  • state       number    • is_prim   • type        name      • make
   • status    • zip       • agency    • assigned  • restrict  • role      • model
   • dispatch  • lat/long    type        datetime  • user      • dob       • year
                • police    • juris     • dispatch                         • color
                  beat                    datetime
                • fire                  • enroute
                  quadrant              • arrive
                • ems                   • clear
                  district                datetime
                                                    ┌─────────┐
                                                    │  CALL   │
                                                    │DISPOSIT │
                                                    │  IONS   │
                                                    └─────────┘
                                        ┌───────────│• name   │
                                        │           │• count  │
                                        │           └─────────┘
                        (1:N via unit_id FK)
                                        │
                        ┌───────────────┼───────────────┐
                        ▼               ▼               ▼
                   ┌─────────┐    ┌─────────┐    ┌─────────┐
                   │  UNIT   │    │  UNIT   │    │  UNIT   │
                   │PERSONNEL│    │  LOGS   │    │DISPOSIT │
                   │         │    │         │    │  IONS   │
                   └─────────┘    └─────────┘    └─────────┘
                   • first      • log         • name
                     name         datetime    • count
                   • last       • status      • datetime
                     name       • location
                   • id_num
                   • shield
                   • is_prim


                            ┌──────────────────────────┐
                            │    PROCESSED_FILES       │
                            │  (File Tracking)         │
                            │  ──────────────────────  │
                            │  • id (PK)               │
                            │  • filename (UNIQUE)     │
                            │  • file_hash             │
                            │  • call_number (NEW!)    │
                            │  • file_timestamp (NEW!) │
                            │  • processed_at          │
                            │  • status                │
                            │  • error_message         │
                            │  • records_processed     │
                            └──────────────────────────┘
                            (Standalone - No FK)
                            Tracks processed XML files
```

## Table Relationships Summary

### Primary Table
- **calls**: One record per emergency call (unique by `call_id`)

### Child Tables (via call_id → calls.id)
1. **agency_contexts**: Multiple agency types per call (Fire, Police, EMS)
2. **locations**: Single location record per call
3. **incidents**: Multiple incident records per call
4. **units**: Multiple response units per call
5. **narratives**: Multiple narrative entries per call
6. **persons**: Multiple persons involved per call
7. **vehicles**: Multiple vehicles involved per call
8. **call_dispositions**: Disposition data for calls

### Grandchild Tables (via unit_id → units.id)
1. **unit_personnel**: Personnel assigned to each unit
2. **unit_logs**: Status change logs for each unit
3. **unit_dispositions**: Dispositions specific to units

### Tracking Table (Independent)
- **processed_files**: Tracks which XML files have been processed

## Foreign Key Cascade Behavior

All foreign keys use `ON DELETE CASCADE`:
- Deleting a call automatically deletes all related records
- Deleting a unit automatically deletes all related personnel/logs/dispositions
- Ensures referential integrity

## Indexes for Performance

### calls table
- call_id (UNIQUE)
- call_number
- create_datetime
- close_datetime
- closed_flag
- created_by

### Child tables
- All have indexes on their foreign key (call_id or unit_id)
- Additional indexes on frequently queried fields

### processed_files table (Enhanced v1.1.0)
- filename (UNIQUE)
- call_number (NEW!)
- file_timestamp (NEW!)
- processed_at
- status

## Version History

### v1.0.0 (Initial)
- 13 tables with comprehensive CAD data model
- Foreign key relationships with CASCADE delete
- JSON storage for complete XML preservation

### v1.1.0 (Current)
- Enhanced processed_files with call_number and file_timestamp
- Enables intelligent file version tracking
- Supports optimization of duplicate file processing

## Key Design Decisions

1. **call_id vs call_number**: 
   - `call_id` is the unique database identifier (from XML)
   - `call_number` is the human-readable call number (appears in filenames)

2. **Upsert Strategy**: 
   - Updates existing calls based on call_id
   - Accumulates child records (doesn't delete on update)

3. **JSON Storage**: 
   - Full XML stored as JSON in calls.xml_data
   - Allows future schema evolution without data loss

4. **Cascade Deletes**: 
   - Simplifies cleanup operations
   - Ensures no orphaned records

## Database Support

Both MySQL and PostgreSQL are fully supported with equivalent schemas:
- MySQL: `database/mysql/init.sql`
- PostgreSQL: `database/postgres/init.sql`
- Migration scripts available for v1.1.0 upgrade
