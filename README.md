# NWS CAD System

![Tests](https://github.com/k9barry/nws-cad/actions/workflows/tests.yml/badge.svg)

A Docker-based PHP system for monitoring, parsing, and storing CAD (Computer-Aided Dispatch) XML data with selectable database backend support and REST API.

**Current Version:** 1.1.0 | **[📚 Complete Documentation Index](DOCUMENTATION.md)** | **[📋 Changelog](CHANGELOG.md)**

## Features

- 🐳 **Docker-based deployment** - Easy setup with Docker Compose
- 🔄 **Multi-database support** - Choose between MySQL or PostgreSQL
- 📁 **Automatic file monitoring** - Watches folder for new XML files
- 📊 **XML parsing and storage** - Automatically parses and stores CAD data with BOM handling
- 🌐 **REST API** - Complete REST API for accessing CAD data
- 🎨 **Web Dashboard** - Visual interface with maps and charts
- 📱 **Mobile-Friendly** - Responsive mobile interface with touch-optimized controls
- 🗄️ **DBeaver Integration** - Web-based database manager for both databases
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

### 4. Web Dashboard
Interactive web interface for visualizing CAD data:
- Real-time call monitoring
- Interactive maps with Leaflet
- Analytics charts and graphs
- Mobile-optimized responsive design
  - Automatic device detection
  - Touch-friendly interface
  - Bottom navigation for quick access
  - Pull-to-refresh functionality
  - Full-screen modals for filters and details
- Direct access to DBeaver database manager

### 5. DBeaver (CloudBeaver)
Web-based database management tool:
- Connects to both MySQL and PostgreSQL databases
- SQL query editor with syntax highlighting
- Visual data browsing and editing
- ER diagrams and database structure visualization
- Accessible from dashboard navigation

### 6. Testing Infrastructure
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

### 4. Access the Dashboard

Open your browser and navigate to:
- **Dashboard**: http://localhost:80
- **DBeaver (Database Manager)**: http://localhost:8978

**Mobile Access:**
- The dashboard automatically detects mobile devices and serves a mobile-optimized interface
- Access from any mobile device or tablet for a touch-friendly experience
- Features include:
  - Bottom navigation bar for quick access
  - Horizontal scrollable stats cards
  - Full-screen modals for filters and call details
  - Pull-to-refresh for manual updates
  - Touch-optimized map view

For DBeaver:
1. First login uses default credentials: `admin` / check `.env` for `DBEAVER_ADMIN_PASSWORD`
2. After login, you can configure connections to both MySQL and PostgreSQL databases
3. Connection details are in your `.env` file

### 5. Test the API

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

### 6. Add XML Files

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
- **dbeaver** - CloudBeaver web-based database manager (port 8978)

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
│   ├── README.md         # Documentation index
│   ├── API.md            # API quick reference
│   ├── DASHBOARD.md      # Dashboard guide
│   ├── TESTING.md        # Testing guide
│   └── TROUBLESHOOTING.md # Common issues and solutions
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

The system includes a comprehensive 13-table normalized schema for NWS Aegis CAD data:

### Core Tables

1. **calls** - Main CAD call/incident records
2. **agency_contexts** - Agency-specific details (Police, Fire, EMS)
3. **locations** - Complete address and geographic information
4. **incidents** - Incident/case numbers and types
5. **units** - Dispatched units with complete lifecycle tracking
6. **unit_personnel** - Personnel assigned to units
7. **unit_logs** - Complete unit status history
8. **unit_dispositions** - Unit-specific outcomes
9. **narratives** - Chronological call notes/comments
10. **call_dispositions** - Overall call outcomes
11. **persons** - People involved in calls
12. **vehicles** - Vehicles involved
13. **processed_files** - File processing history

For complete schema details, see [Database Schema Documentation](database/SCHEMA.md).

## XML Processing

### How It Works

## XML Processing

### How It Works

1. **Detection** - FileWatcher monitors the `watch` folder
2. **Version Detection** - Intelligently identifies and processes only the latest version of each call (v1.1.0+)
3. **Stability Check** - Ensures file is completely written
4. **Parsing** - AegisXmlParser extracts data from NWS Aegis CAD XML
5. **Storage** - Data is stored in the selected database with full transaction support
6. **Archival** - File moved to `processed` or `failed` folder

### Performance Optimization

The system automatically detects and skips older versions of the same call:
- Analyzes filenames to group calls by call number
- Processes only the latest version for each call
- **82% reduction** in processing overhead (tested with 89 sample files)
- Example: 19 versions of call #232 → only 1 file processed

### Supported XML Format

The system is designed for **NWS Aegis CAD XML exports**:
- Complete support for all Aegis CAD data elements
- Handles units, personnel, narratives, locations, incidents
- Preserves full XML in database for auditing
- Automatic BOM (Byte Order Mark) handling

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

**Note on BOM (Byte Order Mark)**: The system automatically handles XML files with UTF-8, UTF-16 BE, and UTF-16 LE byte order marks. NWS CAD XML exports commonly include UTF-8 BOM (`EF BB BF`), which is automatically stripped during parsing to ensure compatibility.

### Performance Tuning

- Adjust `WATCHER_INTERVAL` for faster/slower checking
- Increase PHP memory limit in Dockerfile
- Optimize database queries for large datasets

## Future Enhancements

## Future Enhancements

Potential areas for expansion:

- 🔔 Real-time WebSocket notifications for call updates
- 🔐 Authentication and role-based access control
- 🔄 Multi-server data synchronization
- 📱 Mobile application support
- 🤖 Automated alert rules and workflows
- 📧 Email/SMS notifications
- 🎯 Predictive analytics and ML insights

## Documentation

Complete documentation is available in the [`docs/`](docs/) directory:

- **[Documentation Index](docs/README.md)** - Master index of all documentation
- **[API Reference](docs/API.md)** - Quick API reference
- **[API Controllers](src/Api/Controllers/README.md)** - Detailed API endpoint documentation
- **[Database Schema](database/SCHEMA.md)** - Complete schema documentation
- **[Dashboard Guide](docs/DASHBOARD.md)** - Web dashboard usage
- **[Testing Guide](docs/TESTING.md)** - Running and writing tests
- **[Troubleshooting](docs/TROUBLESHOOTING.md)** - Common issues and solutions

## Security

## Security

### Best Practices
- Change default passwords in `.env`
- Use environment-specific configurations
- Keep Docker images updated
- Review and audit database access
- Implement network security policies

### Built-in Security Features
- **XXE Protection** - XML External Entity attack prevention in XML parsing
- **SQL Injection Prevention** - All queries use prepared statements
- **XSS Prevention** - Output sanitization in dashboard
- **Input Validation** - Comprehensive validation for all inputs
- **Security Headers** - CSP, HSTS, X-Frame-Options
- **Rate Limiting** - API request rate limiting
- **Transaction Support** - Atomic database operations with rollback

### Security Testing
The system includes comprehensive security tests:
- SQL injection prevention testing
- XSS vulnerability testing  
- XXE attack prevention testing
- Daily automated security scans via GitHub Actions

See [Testing Guide](docs/TESTING.md) for details.

## Version

**Current Version:** 1.1.0

See [CHANGELOG.md](CHANGELOG.md) for version history and release notes.

## Support

- **Documentation:** See the [`docs/`](docs/) directory for comprehensive guides
- **Issues:** Use the [GitHub issue tracker](https://github.com/k9barry/nws-cad/issues)
- **Troubleshooting:** Check [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) for common issues
- **API Questions:** See [API documentation](src/Api/Controllers/README.md)

## Contributing

Contributions are welcome! Please:
1. Fork the repository
2. Create a feature branch
3. Write tests for new features
4. Ensure all tests pass (`composer test`)
5. Update documentation as needed
6. Submit a pull request

See [Testing Guide](docs/TESTING.md) for running tests.

## License

See [LICENSE](LICENSE) file for details.
