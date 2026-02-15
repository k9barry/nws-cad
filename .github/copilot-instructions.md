# Copilot Instructions for NWS CAD Project

## Build, Test, and Lint Commands

```bash
# Run all tests
composer test

# Run specific test suite
composer test:unit
composer test:integration
composer test:security
composer test:performance

# Run single test file
./vendor/bin/phpunit tests/Unit/ConfigTest.php

# Run single test method
./vendor/bin/phpunit --filter testMethodName tests/Unit/ConfigTest.php

# Generate coverage report (80% minimum required)
composer test:coverage

# Start file watcher
composer watch

# Start services with Docker
docker-compose up -d
```

## Architecture

### Request Flow

```
HTTP Request
     │
     ├── /api/* ──▶ public/api.php ──▶ Router ──▶ Controller ──▶ Response::success()
     │
     └── /* ──▶ public/index.php ──▶ Mobile detection ──▶ View (dashboard.php or dashboard-mobile.php)
```

### Core Classes

| Class | Purpose |
|-------|---------|
| `Database` | Singleton PDO wrapper, supports MySQL/PostgreSQL via `DB_TYPE` env var |
| `Config` | Singleton config manager, reads from env vars |
| `Router` | Pattern-based routing with `{id}` parameter extraction |
| `Response` | Static methods: `success($data)`, `error($message, $code)` |
| `Request` | Static helpers: `pagination()`, `sorting()`, `filters()`, `json()` |
| `DbHelper` | Database-agnostic SQL (GROUP_CONCAT/STRING_AGG, COALESCE, date functions) |

### Controller Pattern

Controllers are instantiated per-request. All public methods return void and output via `Response::`:

```php
class ExampleController
{
    private PDO $db;
    
    public function __construct()
    {
        $this->db = Database::getConnection();
    }
    
    public function index(): void
    {
        $data = $this->db->query("...")->fetchAll();
        Response::success($data);
    }
}
```

### JavaScript Architecture

Global `Dashboard` object in `dashboard.js` provides shared utilities:
- `Dashboard.apiRequest(endpoint)` - Fetch wrapper with error handling
- `Dashboard.escapeHtml(text)` - XSS prevention (use for ALL user data in HTML)
- `Dashboard.formatTime(datetime)` - Consistent time formatting
- `Dashboard.buildQueryString(params)` - URL parameter builder

Page-specific modules (`dashboard-main.js`, `mobile.js`, etc.) use Dashboard utilities.

## Key Conventions

### PHP

- All files start with `declare(strict_types=1);`
- Namespace: `NwsCad\*` (PSR-4 autoloading from `src/`)
- Database queries always use prepared statements
- Controllers use `Response::success()` or `Response::error()` - never echo
- DbHelper validates SQL identifiers via `IDENTIFIER_PATTERN` regex before interpolation

### JavaScript

- Always escape user data: `Dashboard.escapeHtml(userInput)`
- API responses have structure: `{ success: true, data: { items: [], pagination: {} } }`
- Mobile detection via `jenssegers/agent` - serves `dashboard-mobile.php` automatically

### API Response Format

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

### Database Abstraction

Use `DbHelper` for database-agnostic SQL:

```php
// Instead of MySQL-specific GROUP_CONCAT:
DbHelper::groupConcat('column_name', ', ', true)  // Works on both MySQL and PostgreSQL

// Date formatting:
DbHelper::dateFormat('create_datetime', '%Y-%m-%d')
```

### Security Patterns

- XML parsing: XXE protection via `LIBXML_NOENT` disabled (default)
- SQL: Prepared statements only, identifier validation in DbHelper
- XSS: `Dashboard.escapeHtml()` in JS, `htmlspecialchars()` in PHP views
- CORS: Origin whitelist in `SecurityHeaders.php`
- Logs controller: Disabled by default in production

### Test Database

Tests use separate `nws_cad_test` database. Environment configured in `phpunit.xml`.

## Database Schema

13 tables with `calls` as the primary entity:

```
calls (1) ──▶ agency_contexts (N)
         ──▶ locations (1)
         ──▶ incidents (N)
         ──▶ units (N) ──▶ unit_personnel (N)
                      ──▶ unit_logs (N)
         ──▶ narratives (N)
         ──▶ persons (N)
         ──▶ vehicles (N)
```

API uses internal `id` field for endpoints (`/api/calls/{id}`), not `call_id`.
