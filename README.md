# NWS CAD System

![Tests](https://github.com/k9barry/nws-cad/actions/workflows/tests.yml/badge.svg)

A PHP 8.3 system for monitoring, parsing, and storing NWS Aegis CAD (Computer-Aided Dispatch) XML data with multi-database support, REST API, and real-time web dashboard.

**Version:** 1.1.0 | **[📋 Changelog](CHANGELOG.md)** | **[📚 Documentation](docs/)**

## Features

| Category | Features |
|----------|----------|
| **Core** | 🐳 Docker deployment, 🔄 MySQL/PostgreSQL support, 📁 Automatic XML file monitoring |
| **API** | 🌐 19 REST endpoints, 📊 Pagination/filtering/sorting, 🔍 Geographic search |
| **Dashboard** | 🎨 Real-time monitoring, 🗺️ Interactive maps, 📈 Analytics charts |
| **Mobile** | 📱 Auto-detection, 👆 Touch-optimized UI, ⬇️ Pull-to-refresh |
| **Security** | 🔒 XSS/SQL injection/XXE prevention, 🛡️ Rate limiting, 🔐 Security headers |
| **Testing** | 🧪 142+ automated tests, 📊 80% coverage, 🚀 CI/CD pipeline |

## Quick Start

```bash
# 1. Clone and configure
git clone https://github.com/k9barry/nws-cad.git && cd nws-cad
cp .env.example .env  # Edit with your settings

# 2. Start services
docker-compose up -d

# 3. Access
# Dashboard: http://localhost:80
# API: http://localhost:8080/api/
# Database Manager: http://localhost:8978
```

## Architecture

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   File Watcher  │────▶│    Database     │◀────│    REST API     │
│   (XML Parser)  │     │  (MySQL/PgSQL)  │     │   (19 endpoints)│
└─────────────────┘     └─────────────────┘     └─────────────────┘
                               ▲
                               │
                        ┌──────┴──────┐
                        │  Dashboard  │
                        │ (Web + Mobile)│
                        └─────────────┘
```

### Components

| Component | Description | Port |
|-----------|-------------|------|
| **File Watcher** | Monitors `watch/` for XML files, parses and stores data | - |
| **REST API** | 19 endpoints for calls, units, search, statistics | 8080 |
| **Dashboard** | Real-time monitoring with maps and charts | 80 |
| **Mobile UI** | Touch-optimized interface with auto-detection | 80 |
| **Database** | MySQL 8.0 or PostgreSQL 16 (configurable) | 3306/5432 |
| **DBeaver** | Web-based database manager (CloudBeaver) | 8978 |

### Directory Structure

```
nws-cad/
├── src/                        # PHP source code
│   ├── Api/                   # REST API (Router, Request, Response, Controllers)
│   ├── Dashboard/Views/       # Dashboard templates (desktop + mobile)
│   ├── Security/              # Security (InputValidator, RateLimiter, Headers)
│   ├── Exceptions/            # Custom exception classes
│   ├── AegisXmlParser.php     # NWS Aegis XML parser
│   ├── Database.php           # Database abstraction layer
│   ├── Config.php             # Configuration manager
│   └── FileWatcher.php        # File monitoring service
├── public/                    # Web root
│   ├── assets/js/             # JavaScript (9 modules)
│   ├── assets/css/            # Stylesheets (3 files)
│   ├── index.php              # Dashboard entry point
│   └── api.php                # API entry point
├── database/                  # Schema files (MySQL + PostgreSQL)
├── tests/                     # PHPUnit tests (4 suites)
├── docs/                      # Documentation
├── watch/                     # XML input folder
├── logs/                      # Application logs
└── samples/                   # 89 sample XML files
```

## API Reference

### Endpoints

| Endpoint | Description |
|----------|-------------|
| `GET /api/calls` | List calls (paginated, filterable) |
| `GET /api/calls/{id}` | Get call details with related data |
| `GET /api/calls/{id}/units` | Get units assigned to call |
| `GET /api/calls/{id}/narratives` | Get call narrative timeline |
| `GET /api/calls/{id}/location` | Get call location details |
| `GET /api/units` | List all units |
| `GET /api/units/{id}` | Get unit details |
| `GET /api/units/{id}/logs` | Get unit status history |
| `GET /api/search/calls` | Advanced call search |
| `GET /api/search/location` | Geographic radius search |
| `GET /api/stats` | Aggregated statistics |
| `GET /api/stats/calls` | Call statistics |
| `GET /api/stats/units` | Unit statistics |
| `GET /api/stats/response-times` | Response time analytics |

### Query Parameters

```bash
# Pagination
?page=1&per_page=30

# Filtering
?date_from=2026-01-01&date_to=2026-12-31
?closed_flag=false              # Active calls only
?jurisdiction=MADISON

# Sorting
?sort=create_datetime&order=desc
```

### Response Format

```json
{
  "success": true,
  "data": {
    "items": [...],
    "pagination": {
      "total": 150,
      "per_page": 30,
      "current_page": 1,
      "total_pages": 5
    }
  }
}
```

## Database Schema

13 normalized tables for complete CAD data:

| Table | Description |
|-------|-------------|
| `calls` | Main call/incident records |
| `agency_contexts` | Agency-specific details (Police, Fire, EMS) |
| `locations` | Address and geographic coordinates |
| `incidents` | Incident/case numbers |
| `units` | Dispatched units with lifecycle tracking |
| `unit_personnel` | Personnel assigned to units |
| `unit_logs` | Unit status history |
| `unit_dispositions` | Unit-specific outcomes |
| `narratives` | Chronological call notes |
| `call_dispositions` | Call outcomes |
| `persons` | People involved |
| `vehicles` | Vehicles involved |
| `processed_files` | File processing history |

## Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `DB_TYPE` | Database type (`mysql` or `pgsql`) | `mysql` |
| `MYSQL_HOST` | MySQL hostname | `mysql` |
| `MYSQL_DATABASE` | Database name | `nws_cad` |
| `MYSQL_USER` | Database user | `nws_user` |
| `MYSQL_PASSWORD` | Database password | - |
| `API_PORT` | API server port | `8080` |
| `WATCHER_INTERVAL` | File check interval (seconds) | `5` |
| `LOG_LEVEL` | Logging level | `debug` |
| `APP_ENV` | Environment (`production`/`development`) | `development` |

## Testing

```bash
# Run all tests
composer test

# Run specific suites
composer test:unit           # Unit tests (7 files, 69+ tests)
composer test:integration    # Integration tests (4 files, 25+ tests)
composer test:performance    # Performance tests (2 files, 14+ tests)
composer test:security       # Security tests (3 files, 34+ tests)

# Generate coverage report
composer test:coverage       # 80% minimum required
```

## Security

### Built-in Protections

- **XSS Prevention**: All output HTML-escaped via `Dashboard.escapeHtml()`
- **SQL Injection**: Prepared statements + identifier validation
- **XXE Prevention**: External entities disabled in XML parsing
- **CORS**: Origin validation with whitelist
- **Rate Limiting**: API request throttling
- **Security Headers**: CSP, HSTS, X-Frame-Options, X-Content-Type-Options
- **Input Validation**: Comprehensive validation on all inputs

### Security Hardening (v1.1.0)

- Log viewer disabled by default in production
- Coordinate range validation (lat ±90, lng ±180)
- LIKE pattern injection prevention
- JSON parsing with proper error handling

## Documentation

| Document | Description |
|----------|-------------|
| [docs/API.md](docs/API.md) | API quick reference |
| [docs/DASHBOARD.md](docs/DASHBOARD.md) | Dashboard user guide |
| [docs/TESTING.md](docs/TESTING.md) | Testing infrastructure |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Common issues and solutions |
| [docs/BACKUP_GUIDE.md](docs/BACKUP_GUIDE.md) | Database backup procedures |
| [CHANGELOG.md](CHANGELOG.md) | Version history |

## License

See [LICENSE](LICENSE) file for details.
