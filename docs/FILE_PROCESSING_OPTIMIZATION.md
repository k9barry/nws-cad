# File Processing Optimization

## Overview

The NWS CAD system has been enhanced to intelligently process XML files with multiple versions per call. This optimization ensures that only the latest version of each call is processed, preventing duplicate and outdated data from being stored in the database.

## Filename Structure

XML files follow a specific naming convention:

```
CallNumber_YYYYMMDDHHMMSSsuffix.xml
```

**Example:** `591_2026012705492672.xml`

Breaking down the components:
- `591` = CallNumber (unique identifier for the call)
- `2026` = Year
- `01` = Month
- `27` = Day
- `05` = Hour
- `49` = Minute
- `26` = Second
- `72` = Microsecond suffix

The timestamp portion (YYYYMMDDHHMMSSsuffix) represents when the file was generated. When multiple files exist for the same CallNumber, the file with the **highest timestamp** contains the most current data.

## How It Works

### 1. FilenameParser Utility

The `FilenameParser` class provides methods to:
- Parse filenames to extract call metadata
- Group files by call number
- Identify the latest version for each call
- Determine which files should be skipped

**Example Usage:**

```php
use NwsCad\FilenameParser;

// Parse a single filename
$parsed = FilenameParser::parse('591_2026012705492672.xml');
// Returns:
// [
//     'call_number' => '591',
//     'year' => '2026',
//     'month' => '01',
//     'day' => '27',
//     'hour' => '05',
//     'minute' => '49',
//     'second' => '26',
//     'suffix' => '72',
//     'timestamp' => '2026-01-27 05:49:26.72',
//     'timestamp_int' => 2026012705492672
// ]

// Get latest files from a batch
$files = [
    '232_2026012609353768.xml',
    '232_2026012609595563.xml',  // Latest
    '232_2026012609504429.xml',
];
$latest = FilenameParser::getLatestFiles($files);
// Returns: ['232_2026012609595563.xml']

// Get files to skip (older versions)
$toSkip = FilenameParser::getFilesToSkip($files);
// Returns: ['232_2026012609353768.xml', '232_2026012609504429.xml']
```

### 2. FileWatcher Integration

The `FileWatcher` service now:
1. Scans the watch folder for XML files
2. Groups files by call number
3. Identifies the latest version for each call
4. Skips processing of older versions
5. Logs detailed information about version analysis

**Example Log Output:**

```
Found 19 XML file(s) in watch folder
--- File Version Analysis ---
Unique call numbers: 1
Call 232: 19 files found, latest will be processed
Skipping 18 older versions:
  ✗ 232_2026012609353768.xml (older version)
  ✗ 232_2026012609354268.xml (older version)
  ...
--- Checking file: 232_2026012611061506.xml ---
✓ File passed checks, processing: 232_2026012611061506.xml
Summary: 1 processed, 18 skipped (18 older versions), 19 total
```

### 3. Database Tracking

The `processed_files` table now includes:
- `call_number` - Extracted from the filename
- `file_timestamp` - Timestamp as integer for version tracking

This allows for:
- Efficient queries by call number
- Version comparison
- Historical tracking of which files were processed

### 4. Upsert Logic

When processing a file:
1. The system checks if a call with the same `call_id` exists
2. If exists: **UPDATE** the main call record and **UPSERT** child records
3. If new: **INSERT** all records

This ensures:
- No duplicate call records
- Latest data is always stored
- Child records (units, narratives, etc.) are accumulated

## Database Schema Changes

### processed_files Table (Enhanced)

```sql
CREATE TABLE IF NOT EXISTS processed_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) UNIQUE NOT NULL,
    file_hash VARCHAR(64) NOT NULL,
    call_number VARCHAR(50) COMMENT 'Extracted from filename',
    file_timestamp BIGINT COMMENT 'Timestamp from filename for version tracking',
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('success', 'failed', 'partial') DEFAULT 'success',
    error_message TEXT,
    records_processed INT DEFAULT 0,
    
    INDEX idx_filename (filename),
    INDEX idx_call_number (call_number),
    INDEX idx_file_timestamp (file_timestamp),
    INDEX idx_processed_at (processed_at),
    INDEX idx_status (status)
);
```

## Migration Guide

For existing databases, run the migration script:

### MySQL
```bash
mysql -u nws_cad_user -p nws_cad < database/mysql/migration_v1.1.0.sql
```

### PostgreSQL
```bash
psql -U nws_cad_user -d nws_cad -f database/postgres/migration_v1.1.0.sql
```

## Benefits

1. **Prevents Duplicate Data**: Only the latest version of each call is processed
2. **Reduces Processing Time**: Skips unnecessary older files
3. **Maintains Data Integrity**: Ensures the most current information is stored
4. **Improves Efficiency**: Reduces database write operations
5. **Better Tracking**: Call version information is preserved for auditing

## Real-World Example

Consider Call #232 with 19 versions in the watch folder:

**Before Optimization:**
- All 19 files would be processed sequentially
- Each file would update the database
- The final state would depend on processing order
- 19x database transactions for the same call

**After Optimization:**
- Only 1 file (`232_2026012611061506.xml`) is processed
- 18 older files are automatically skipped
- Single database transaction with latest data
- 95% reduction in processing overhead

## Testing

The FilenameParser has comprehensive test coverage:

```bash
./vendor/bin/phpunit tests/Unit/FilenameParserTest.php --testdox
```

Tests include:
- Filename parsing validation
- Version comparison
- File grouping by call number
- Latest file selection
- Real sample file scenarios

## Performance Impact

**Sample Folder Analysis:**
- Total files: 89
- Unique calls: 16
- Files to process: 16 (latest versions only)
- Files skipped: 73 (older versions)
- **Efficiency gain: 82% reduction in processing**

## API Compatibility

All existing API endpoints remain unchanged. The optimization is transparent to API consumers and only affects internal file processing logic.

## Future Enhancements

Potential future improvements:
1. Archive older versions after processing latest
2. Configurable retention policy for processed files
3. API endpoint to query file version history
4. Dashboard visualization of call version timelines
