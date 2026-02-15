# NWS CAD Documentation

Quick reference to all project documentation.

## Getting Started

| Document | Description |
|----------|-------------|
| [README.md](README.md) | Project overview and quick start |
| [CHANGELOG.md](CHANGELOG.md) | Version history and release notes |

## User Guides

| Document | Description |
|----------|-------------|
| [docs/API.md](docs/API.md) | REST API quick reference |
| [docs/DASHBOARD.md](docs/DASHBOARD.md) | Dashboard features and usage |
| [docs/BACKUP_GUIDE.md](docs/BACKUP_GUIDE.md) | Database backup procedures |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Common issues and solutions |

## Developer Guides

| Document | Description |
|----------|-------------|
| [docs/TESTING.md](docs/TESTING.md) | Testing infrastructure (142+ tests) |
| [.github/copilot-instructions.md](.github/copilot-instructions.md) | Development standards |

## API Documentation

- **Base URL:** `http://localhost:8080/api`
- **Format:** JSON responses with `{success, data}` structure
- **Features:** Pagination, filtering, sorting, geographic search

### Endpoint Summary

| Category | Endpoints |
|----------|-----------|
| Calls | `/calls`, `/calls/{id}`, `/calls/{id}/units`, `/calls/{id}/narratives`, `/calls/{id}/location` |
| Units | `/units`, `/units/{id}`, `/units/{id}/logs` |
| Search | `/search/calls`, `/search/location` |
| Stats | `/stats`, `/stats/calls`, `/stats/units`, `/stats/response-times` |

## Database

- **Supported:** MySQL 8.0, PostgreSQL 16
- **Schema:** 13 normalized tables
- **Tables:** calls, agency_contexts, locations, incidents, units, unit_personnel, unit_logs, unit_dispositions, narratives, call_dispositions, persons, vehicles, processed_files

## Quick Commands

```bash
# Start services
docker-compose up -d

# View logs
docker-compose logs -f app

# Run tests
composer test

# Access database (MySQL)
docker-compose exec mysql mysql -u nws_user -p nws_cad
```

---

**Version:** 1.1.0 | **Last Updated:** 2026-02-15
