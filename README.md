# NWS CAD System

A Docker-based PHP system for monitoring, parsing, and storing CAD (Computer-Aided Dispatch) XML data with selectable database backend support and REST API.

## Features

- 🐳 **Docker-based deployment** - Easy setup with Docker Compose
- 🔄 **Multi-database support** - Choose between MySQL or PostgreSQL
- 📁 **Automatic file monitoring** - Watches folder for new XML files
- 📊 **XML parsing and storage** - Automatically parses and stores CAD data
- 🌐 **REST API** - Complete REST API for accessing CAD data
- 📝 **Comprehensive logging** - Detailed logs for debugging and monitoring
- 🔒 **Transaction support** - Ensures data integrity
- 🧪 **Comprehensive testing** - 142+ automated tests with 80% coverage
- 🔐 **Security testing** - SQL injection, XSS, XXE prevention
- 🚀 **CI/CD pipeline** - Automated testing and deployment
- 📈 **Performance benchmarks** - Query and API response time tracking

## Components

### 1. File Watcher Service
Monitors the `watch/` directory for new XML files and automatically processes them into the database.

### 2. REST API Service
Provides a complete REST API for accessing CAD data:
- 19 endpoints for calls, units, search, and statistics
- Pagination, filtering, and sorting support
- Geographic search with radius support
- Response time analytics

### 3. Database
Comprehensive 13-table schema for NWS Aegis CAD data:
- Calls, units, personnel, narratives, locations
- Complete unit lifecycle tracking
- Full XML preservation for auditing

### 4. Testing Infrastructure
- 142+ automated tests across 4 test suites
- 80% minimum code coverage requirement
- Automated CI/CD with GitHub Actions
- Security vulnerability scanning

## Requirements

- Docker 20.10+
- Docker Compose 2.0+

## Quick Start

### 1. Clone the Repository

```bash
git clone https://github.com/k9barry/nws-cad.git
cd nws-cad
```

### 2. Configure Environment

Copy the example environment file and customize it:

```bash
cp .env.example .env
```

Edit `.env` and configure your settings:

```bash
# Choose database type: mysql or pgsql
DB_TYPE=mysql

# Set secure passwords
MYSQL_PASSWORD=your_secure_password
POSTGRES_PASSWORD=your_secure_password

# API port (default: 8080)
API_PORT=8080
```

### 3. Start the Services

```bash
# Start all services
docker-compose up -d

# View logs
docker-compose logs -f app

# View API logs
docker-compose logs -f api
```

### 4. Test the API

```bash
# Get API info
curl http://localhost:8080/api/

# List calls
curl http://localhost:8080/api/calls

# Get call details
curl http://localhost:8080/api/calls/1

# Search calls
curl "http://localhost:8080/api/search/calls?call_number=260"
```

### 5. Add XML Files

Place your XML files in the `watch` folder:

```bash
cp your-file.xml watch/
```

The system will automatically detect, parse, and store the data.

## API Documentation

The REST API provides 19 endpoints for accessing CAD data. See [docs/API.md](docs/API.md) for complete documentation.

### Main Endpoints

**Calls:**
- `GET /api/calls` - List all calls (with pagination, filtering, sorting)
- `GET /api/calls/{id}` - Get call details with all related data
- `GET /api/calls/{id}/units` - Get units for a call
- `GET /api/calls/{id}/narratives` - Get narratives timeline
- `GET /api/calls/{id}/location` - Get location details

**Units:**
- `GET /api/units` - List all units
- `GET /api/units/{id}` - Get unit details
- `GET /api/units/{id}/logs` - Get unit status history
- `GET /api/units/{id}/personnel` - Get unit personnel

**Search:**
- `GET /api/search/calls` - Advanced call search
- `GET /api/search/location` - Geographic search with radius
- `GET /api/search/units` - Unit search

**Statistics:**
- `GET /api/stats/calls` - Call statistics and analytics
- `GET /api/stats/units` - Unit performance metrics
- `GET /api/stats/response-times` - Response time analysis

## Architecture

### Services

- **app** - PHP 8.3 application running the file watcher
- **api** - PHP 8.3 web server running the REST API (port 8080)
- **mysql** - MySQL 8.0 database (optional, based on DB_TYPE)
- **postgres** - PostgreSQL 16 database (optional, based on DB_TYPE)

### Directory Structure

```
nws-cad/
├── src/                    # Application source code
│   ├── Api/               # REST API components
│   │   ├── Controllers/  # API endpoint controllers
│   │   ├── Router.php    # Request routing
│   │   ├── Request.php   # Request parsing
│   │   └── Response.php  # Response formatting
│   ├── Config.php         # Configuration manager
│   ├── Database.php       # Database abstraction layer
│   ├── Logger.php         # Logging system
│   ├── AegisXmlParser.php # NWS Aegis CAD XML parser
│   ├── FileWatcher.php    # File monitoring service
│   └── watcher.php        # Watcher entry point
├── public/                # Public web directory
│   ├── api.php           # API entry point
│   └── .htaccess         # Apache rewrite rules
├── database/              # Database schemas
│   ├── mysql/            # MySQL initialization scripts
│   └── postgres/         # PostgreSQL initialization scripts
├── docs/                  # Documentation
│   ├── API.md            # API quick reference
│   └── IMPLEMENTATION_COMPLETE.md  # Full implementation guide
├── watch/                # Watch folder for XML files
│   ├── processed/       # Successfully processed files
│   └── failed/          # Failed processing files
├── logs/                # Application logs
├── samples/             # Sample XML files
└── docker-compose.yml   # Docker orchestration
```

## Database Selection

The system supports two database backends:

### MySQL (Default)

```bash
DB_TYPE=mysql
```

### PostgreSQL

```bash
DB_TYPE=pgsql
```

Both databases use the same schema structure, allowing you to switch between them without code changes.

## Database Schema

The system includes a placeholder schema with three main tables:

### Tables

1. **cad_events** - Stores CAD event information
   - event_id (unique identifier)
   - event_type, event_time, location
   - description, priority, status
   - xml_data (JSON/JSONB storage of full XML)

2. **processed_files** - Tracks processed XML files
   - filename, file_hash
   - processing status and error messages
   - record counts

3. **xml_metadata** - Stores XML metadata
   - key-value pairs for XML attributes
   - linked to processed files

### Customizing the Schema

To use your own database schema:

1. Edit `database/mysql/init.sql` for MySQL
2. Edit `database/postgres/init.sql` for PostgreSQL
3. Rebuild the containers: `docker-compose down -v && docker-compose up -d`

## XML Processing

### How It Works

1. **Detection** - FileWatcher monitors the `watch` folder
2. **Stability Check** - Ensures file is completely written
3. **Parsing** - XmlParser extracts data from XML
4. **Storage** - Data is stored in the selected database
5. **Archival** - File moved to `processed` or `failed` folder

### Customizing XML Parsing

The XML parser in `src/XmlParser.php` includes placeholder logic. Customize the following methods based on your XML structure:

```php
// Extract event data from your XML structure
private function extractEventData(SimpleXMLElement $event): array

// Parse specific XML elements
private function parseAndInsertEvents(SimpleXMLElement $xml, string $filePath): int
```

### Example XML Structure

The system expects XML files with event elements:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<events>
    <event>
        <id>EVENT-001</id>
        <type>emergency</type>
        <time>2026-01-25T14:00:00Z</time>
        <location>123 Main St</location>
        <description>Emergency call</description>
        <priority>high</priority>
        <status>active</status>
    </event>
</events>
```

## Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `DB_TYPE` | Database type (mysql/pgsql) | mysql |
| `MYSQL_*` | MySQL connection settings | - |
| `POSTGRES_*` | PostgreSQL connection settings | - |
| `WATCHER_INTERVAL` | Check interval in seconds | 5 |
| `WATCHER_FILE_PATTERN` | File pattern to watch | *.xml |
| `LOG_LEVEL` | Logging level | debug |
| `APP_DEBUG` | Enable debug mode | true |

## Monitoring

### View Logs

```bash
# Application logs
docker-compose logs -f app

# MySQL logs
docker-compose logs -f mysql

# PostgreSQL logs
docker-compose logs -f postgres

# All services
docker-compose logs -f
```

### Check Status

```bash
# Service status
docker-compose ps

# Processed files count
ls -l watch/processed/ | wc -l

# Failed files
ls -l watch/failed/
```

## Maintenance

### Stop Services

```bash
docker-compose down
```

### Restart Services

```bash
docker-compose restart
```

### Reset Database

```bash
# WARNING: This will delete all data
docker-compose down -v
docker-compose up -d
```

### Update Dependencies

```bash
docker-compose exec app composer update
```

## Development

### Testing Infrastructure

The project includes a comprehensive testing suite with 142+ automated tests:

#### Test Suites

- **Unit Tests** (7 files, 69+ tests) - Core class testing
- **Integration Tests** (4 files, 25+ tests) - API endpoint testing
- **Performance Tests** (2 files, 14+ tests) - Query & API benchmarks
- **Security Tests** (3 files, 34+ tests) - Vulnerability prevention

#### Running Tests

```bash
# Run all tests
composer test

# Run specific test suites
composer test:unit           # Unit tests only
composer test:integration    # Integration tests only
composer test:performance    # Performance tests
composer test:security       # Security tests

# Generate coverage report (80% minimum)
composer test:coverage
```

#### Test Requirements

Tests require a test database:
```bash
# Create test database (MySQL)
mysql -u root -p -e "CREATE DATABASE nws_cad_test"
mysql -u root -p -e "GRANT ALL ON nws_cad_test.* TO 'test_user'@'localhost' IDENTIFIED BY 'test_pass'"
mysql -u test_user -ptest_pass nws_cad_test < database/schema.sql

# Or in Docker
docker-compose exec mysql mysql -u root -proot_password -e "CREATE DATABASE nws_cad_test"
```

#### CI/CD Pipeline

Automated workflows run on every pull request:
- ✅ All test suites
- ✅ Code coverage (80% minimum)
- ✅ Security scans (CodeQL, SAST)
- ✅ Dependency vulnerability checks

See `docs/TESTING.md` for comprehensive documentation.

### Access Database

```bash
# MySQL
docker-compose exec mysql mysql -u nws_user -p nws_cad

# PostgreSQL
docker-compose exec postgres psql -U nws_user -d nws_cad
```

### Manual Processing

```bash
# Run watcher manually
docker-compose exec app php src/watcher.php
```

## Troubleshooting

### Database Connection Issues

```bash
# Check database health
docker-compose exec app php -r "require 'vendor/autoload.php'; var_dump(NwsCad\Database::testConnection());"
```

### File Not Processing

1. Check file permissions in `watch` folder
2. Verify XML is valid
3. Check logs: `docker-compose logs -f app`
4. Ensure database is running

### Performance Tuning

- Adjust `WATCHER_INTERVAL` for faster/slower checking
- Increase PHP memory limit in Dockerfile
- Optimize database queries for large datasets

## Future Enhancements

This project is designed to be extended with:

- 🌐 RESTful API for data access
- 📊 Dashboard for data visualization
- 🔔 Real-time notifications
- 📈 Analytics and reporting
- 🔐 Authentication and authorization
- 🔄 Data synchronization
- 📱 Mobile application support

## Security

- Change default passwords in `.env`
- Use environment-specific configurations
- Keep Docker images updated
- Review and audit database access
- Implement network security policies
- The system includes XXE attack protection in XML parsing
- Secure random ID generation for event records

**Note on Composer TLS**: During container build, Composer may encounter SSL certificate issues in certain environments. The docker-compose configuration includes a fallback that temporarily disables TLS verification for Composer only. This is acceptable as dependencies are installed at runtime in an isolated container environment. For production deployments, consider using a private Composer repository or pre-built images with dependencies already installed.

## License

See LICENSE file for details.

## Support

For issues and questions, please use the GitHub issue tracker.

## Contributing

Contributions are welcome! Please feel free to submit pull requests.
