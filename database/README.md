# Database Schema - NWS Aegis CAD

This directory contains comprehensive database schemas for both MySQL and PostgreSQL that capture all data from the New World Systems (NWS) Aegis CAD XML export format.

## Files

- **`mysql/init.sql`** - Complete MySQL database schema (426 lines)
- **`postgres/init.sql`** - Complete PostgreSQL database schema (479 lines)
- **`SCHEMA.md`** - Full documentation with examples and best practices
- **`QUICK_REFERENCE.md`** - Quick reference guide with diagrams and common queries
- **`validate-schema.sh`** - Schema validation script

## Quick Start

### Initialize Databases

```bash
# Start MySQL and PostgreSQL containers
docker-compose up -d mysql postgres

# Verify schemas are created
./database/validate-schema.sh
```

### Verify Schema

```bash
# MySQL
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "SHOW TABLES;"

# PostgreSQL
docker-compose exec postgres psql -U nws_user -d nws_cad -c "\dt"
```

## Schema Overview

### 13 Tables
1. **calls** - Main call/event data (root)
2. **agency_contexts** - Agency-specific context (Police, Fire, EMS)
3. **locations** - Location data with GPS coordinates
4. **incidents** - Incident records
5. **units** - Dispatched units with timeline tracking
6. **unit_personnel** - Personnel assigned to units
7. **unit_logs** - Unit status history
8. **narratives** - Call notes and narratives
9. **persons** - People involved in calls
10. **vehicles** - Vehicles involved
11. **call_dispositions** - Call outcomes
12. **unit_dispositions** - Unit-specific dispositions
13. **processed_files** - File tracking table

### Key Features
- ✓ 11 Foreign key relationships with CASCADE delete
- ✓ 51 Indexes for optimal query performance
- ✓ 150+ Columns capturing all XML data
- ✓ Full XML storage in JSON/JSONB
- ✓ UTF8MB4 charset (MySQL) for full Unicode support
- ✓ Auto-updating timestamps
- ✓ Comprehensive geographic data (beats, districts, coordinates)
- ✓ Complete unit lifecycle tracking (10 timestamps per unit)

## Schema Structure

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

processed_files (independent tracking)
```

## Common Queries

### Get Complete Call Information
```sql
SELECT c.*, l.full_address, l.police_beat
FROM calls c
LEFT JOIN locations l ON c.id = l.call_id
WHERE c.call_number = '285';
```

### Get All Units for a Call
```sql
SELECT u.unit_number, u.unit_type, u.is_primary,
       u.dispatch_datetime, u.arrive_datetime
FROM units u
JOIN calls c ON u.call_id = c.id
WHERE c.call_number = '260';
```

### Response Time Analysis
```sql
SELECT 
    u.unit_number,
    TIMESTAMPDIFF(MINUTE, u.dispatch_datetime, u.arrive_datetime) as response_minutes
FROM units u
JOIN calls c ON u.call_id = c.id
WHERE DATE(c.create_datetime) = '2022-12-03'
  AND u.arrive_datetime IS NOT NULL;
```

## Documentation

- **SCHEMA.md** - Complete schema documentation including:
  - Detailed table descriptions
  - Data type mappings (MySQL ↔ PostgreSQL)
  - Index strategy and performance tips
  - Customization guide
  - Best practices
  - Query examples

- **QUICK_REFERENCE.md** - Quick reference including:
  - Visual schema diagram
  - Field counts by table
  - Common query patterns
  - Maintenance commands
  - Critical fields reference

## Validation

Run the validation script to ensure schemas are correct:

```bash
./database/validate-schema.sh
```

This will verify:
- Table count consistency
- Foreign key relationships
- Index counts
- Documentation files
- Table name matching

## Customization

To add custom fields or tables:

1. Modify both `mysql/init.sql` and `postgres/init.sql`
2. Update the XML parser (`src/AegisXmlParser.php`)
3. Rebuild databases: `docker-compose down -v && docker-compose up -d`
4. Test with sample data

See `SCHEMA.md` for detailed customization instructions.

## Database Comparison

| Feature | MySQL | PostgreSQL |
|---------|-------|------------|
| Auto-increment | BIGINT AUTO_INCREMENT | BIGSERIAL |
| JSON storage | JSON | JSONB (better performance) |
| Timestamps | DATETIME, TIMESTAMP | TIMESTAMP |
| Charset | utf8mb4_unicode_ci | UTF8 (default) |
| Updated triggers | Manual ON UPDATE | Automatic triggers |
| Enums | ENUM type | VARCHAR + CHECK |

## Performance Considerations

### Indexes
- All foreign keys are indexed
- Frequently queried fields (call_number, dates, statuses)
- Geographic fields (beats, districts)
- Composite index on coordinates

### For Large Datasets (>1M records)
- Consider table partitioning by date
- Use connection pooling
- Implement archiving strategy
- Monitor query performance with EXPLAIN

## Maintenance Commands

### Table Information
```bash
# MySQL - Show tables
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "SHOW TABLES;"

# MySQL - Describe table
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "DESCRIBE calls;"

# PostgreSQL - List tables
docker-compose exec postgres psql -U nws_user -d nws_cad -c "\dt"

# PostgreSQL - Describe table
docker-compose exec postgres psql -U nws_user -d nws_cad -c "\d calls"
```

### Row Counts
```bash
# MySQL
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "
    SELECT table_name, table_rows 
    FROM information_schema.tables 
    WHERE table_schema = 'nws_cad';"

# PostgreSQL
docker-compose exec postgres psql -U nws_user -d nws_cad -c "
    SELECT tablename, n_live_tup 
    FROM pg_stat_user_tables;"
```

## Backup and Restore

### MySQL
```bash
# Backup
docker-compose exec mysql mysqldump -u nws_user -pnws_password nws_cad > backup.sql

# Restore
docker-compose exec -T mysql mysql -u nws_user -pnws_password nws_cad < backup.sql
```

### PostgreSQL
```bash
# Backup
docker-compose exec postgres pg_dump -U nws_user nws_cad > backup.sql

# Restore
docker-compose exec -T postgres psql -U nws_user nws_cad < backup.sql
```

## Troubleshooting

### Schema Not Created
```bash
# Check container logs
docker-compose logs mysql
docker-compose logs postgres

# Manually run init script
docker-compose exec mysql mysql -u root -prootpassword < /docker-entrypoint-initdb.d/init.sql
```

### Foreign Key Errors
- Ensure parent records exist before inserting child records
- Use transactions for related inserts
- Check CASCADE delete is working properly

### Performance Issues
- Run EXPLAIN on slow queries
- Check index usage
- Consider query optimization
- Monitor table sizes and growth

## Support

For questions or issues:
1. Review SCHEMA.md for detailed documentation
2. Check QUICK_REFERENCE.md for common patterns
3. Run validate-schema.sh to verify setup
4. Review sample XML files in `/samples`

## License

See LICENSE file in repository root.
