# Sample Files Directory

This directory contains sample XML files from NWS Aegis CAD exports for testing and development.

## Available Sample Files

The directory contains **89 sample XML files** representing real CAD call data:

- **Call Numbers:** 231-244, 260, 261, 285, 287
- **Multiple Versions:** Many calls have multiple versions showing progressive updates
- **Date Range:** December 2022 and January 2026

### Example Files

Notable sample files include:
- `260_2022120307164448.xml` - Standard call with complete data
- `261_2022120307162437~20241007-075033.xml` - Call with non-standard filename format
- `285_2022120307195970.xml` - Sample incident
- `287_2022120307210477.xml` - Sample incident

### Call Version Examples

The samples demonstrate the CAD system's versioning:
- **Call #232** - 19 versions (most versions)
- **Call #240** - 9 versions
- **Call #242** - 9 versions
- **Call #231** - 8 versions
- Other calls with 1-8 versions

This showcases the system's intelligent file processing that only processes the latest version of each call.

## Usage

### Test File Processing

```bash
# Copy a single sample file to the watch folder
cp samples/260_2022120307164448.xml watch/

# Monitor the processing logs
docker-compose logs -f app

# Check the database for processed data
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "SELECT * FROM calls WHERE call_number='260';"
```

### Test Version Detection (v1.1.0+)

```bash
# Copy all sample files to watch folder
cp samples/*.xml watch/

# The system will automatically:
# 1. Group files by call number
# 2. Process only the latest version of each call
# 3. Skip 73 older versions (82% reduction)
# 4. Process only 16 latest files

# Monitor the version analysis
docker-compose logs -f app | grep "File Version Analysis"
```

### Test Specific Call

```bash
# Process all versions of call #232 (19 files)
cp samples/232_*.xml watch/

# Only the latest version will be processed
# Check logs for skipped files
docker-compose logs -f app
```

## File Format

All files conform to the **NWS Aegis CAD XML export format** which includes:
- Complete call information with timestamps
- Agency contexts (Police, Fire, EMS)
- Location data with GPS coordinates
- Unit assignments and status timelines
- Personnel information
- Narratives and call notes
- Incident classifications
- Disposition codes

## File Naming Convention

Standard format: `CallNumber_YYYYMMDDHHMMSSsuffix.xml`

Examples:
- `260_2022120307164448.xml` - Call #260 from Dec 3, 2022 at 07:16:44
- `232_2026012611061506.xml` - Call #232 from Jan 26, 2026 at 11:06:15

## Performance Testing

Use these samples to test system performance:

```bash
# Process all 89 files
cp samples/*.xml watch/

# With v1.1.0+ optimization:
# - 16 files processed (latest versions)
# - 73 files skipped (older versions)
# - 82% reduction in processing overhead
```

## Viewing Sample Data

After processing samples, explore the data:

```bash
# View all processed calls
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "SELECT call_number, create_datetime, nature FROM calls;"

# Count records by call number
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "SELECT call_number, COUNT(*) FROM calls GROUP BY call_number;"

# View units for a specific call
docker-compose exec mysql mysql -u nws_user -pnws_password nws_cad -e "SELECT u.unit_number, u.unit_type FROM units u JOIN calls c ON u.call_id = c.id WHERE c.call_number='232';"
```

## Dashboard Testing

After processing samples, view in the dashboard:

1. Open http://localhost:8080/
2. View calls on the map
3. Browse calls at http://localhost:8080/calls
4. Check analytics at http://localhost:8080/analytics

## Troubleshooting

### Files Not Processing

1. Check file permissions: `ls -la samples/`
2. Verify watch folder exists: `ls -la watch/`
3. Check application logs: `docker-compose logs -f app`
4. Ensure database is running: `docker-compose ps`

### Version Detection Issues

If older files are being processed:
- Verify system version is 1.1.0+
- Check filename format matches expected pattern
- Review logs for parsing warnings

## Additional Resources

- [Database Schema](../database/SCHEMA.md) - See what data is extracted
- [API Documentation](../src/Api/Controllers/README.md) - Query processed data
- [Dashboard Guide](../docs/DASHBOARD.md) - Visualize the data
- [File Processing Optimization](../CHANGELOG.md#110---2026-01-30) - Version detection feature
