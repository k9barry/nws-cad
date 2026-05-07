# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

PHP 8.3 system that monitors a `watch/` folder for NWS Aegis CAD XML files, parses them into a normalized 13-table schema (MySQL or PostgreSQL via `DB_TYPE`), and exposes the data through a REST API and a real-time web dashboard (with a mobile variant served via `jenssegers/agent` detection).

## Common commands

```bash
# Tests
composer test                                        # full suite (4 testsuites)
composer test:unit | test:integration | test:performance | test:security
./vendor/bin/phpunit tests/Unit/ConfigTest.php       # single file
./vendor/bin/phpunit --filter testMethodName tests/Unit/ConfigTest.php
composer test:coverage                               # HTML coverage; 80% minimum

# Run the file-watcher daemon locally
composer watch       # = php src/watcher.php

# Docker stack (file watcher + API + MySQL + Postgres + CloudBeaver)
docker-compose up -d
docker-compose logs -f app
docker-compose exec mysql mysql -u nws_user -p nws_cad
```

Tests use a separate `nws_cad_test` database; environment is configured in `phpunit.xml`.

## Architecture

Two HTTP entry points sit in `public/` and dispatch to two distinct stacks:

```
/api/*  →  public/api.php   →  Api\Router  →  Api\Controllers\*  →  Api\Response::success()/error()
/*      →  public/index.php →  Mobile detection  →  Dashboard/Views/{dashboard.php | dashboard-mobile.php}
```

The file watcher (`src/watcher.php` / `FileWatcher.php`) runs as a separate long-lived process that ingests XML via `AegisXmlParser` and writes to the database.

### Core classes (PSR-4 `NwsCad\*` from `src/`)

| Class | Purpose |
|-------|---------|
| `Database` | Singleton PDO wrapper. `Database::getConnection()` returns the right driver based on `DB_TYPE` env var (`mysql` or `pgsql`). |
| `Config` | Singleton; reads from environment. |
| `Api\Router` | Pattern routes with `{id}` parameter extraction. |
| `Api\Response` | Static `success($data)` / `error($message, $code)` — controllers must never `echo`. |
| `Api\Request` | Static helpers: `pagination()`, `sorting()`, `filters()`, `json()`. |
| `Api\DbHelper` | Database-agnostic SQL (`GROUP_CONCAT`/`STRING_AGG`, `COALESCE`, date functions). Validates SQL identifiers against `IDENTIFIER_PATTERN` before interpolation. |

### Controller pattern

Controllers are instantiated per-request, hold a `PDO $db = Database::getConnection()`, and every public method returns `void` and emits via `Response::`:

```php
public function index(): void {
    $data = $this->db->query("...")->fetchAll();
    Response::success($data);
}
```

### Cross-DB SQL

Never write MySQL- or Postgres-only SQL inline. Use `DbHelper`:

```php
DbHelper::groupConcat('column', ', ', true);   // GROUP_CONCAT vs STRING_AGG
DbHelper::dateFormat('create_datetime', '%Y-%m-%d');
```

### API response shape

```json
{ "success": true, "data": { "items": [...], "pagination": { "total": 150, "per_page": 30, "current_page": 1, "total_pages": 5 } } }
```

Endpoints address calls by the internal `id` field (`/api/calls/{id}`), **not** `call_id`.

### Database schema

13 normalized tables anchored on `calls`:

```
calls (1) → agency_contexts (N), locations (1), incidents (N), narratives (N),
            persons (N), vehicles (N), call_dispositions (N),
            units (N) → unit_personnel (N), unit_logs (N), unit_dispositions (N)
processed_files (file processing history)
```

### JavaScript (`public/assets/js/`)

`dashboard.js` defines a global `Dashboard` object used by all page-specific modules. Always use:

- `Dashboard.apiRequest(endpoint)` — fetch wrapper with consistent error handling
- `Dashboard.escapeHtml(text)` — XSS prevention; **must wrap any user-derived value rendered into HTML**
- `Dashboard.formatTime(datetime)`, `Dashboard.buildQueryString(params)`

## Conventions

- All PHP files start with `declare(strict_types=1);`.
- Prepared statements only; identifier validation in `DbHelper`.
- XXE protection: do not enable `LIBXML_NOENT` when calling SimpleXML.
- CORS origin whitelist lives in `Security/SecurityHeaders.php`.
- `LogsController` is disabled by default in production — keep it that way unless explicitly toggling.
- Coordinate inputs are validated (lat ±90, lng ±180); LIKE patterns are escaped.

## Key environment variables

`DB_TYPE` (`mysql`|`pgsql`), `MYSQL_*` / `POSTGRES_*` connection vars, `API_PORT` (default 8080), `WATCHER_INTERVAL` (seconds), `LOG_LEVEL`, `APP_ENV` (`production`/`development`).

## Further docs

- `README.md`, `DOCUMENTATION.md`, `CHANGELOG.md`
- `docs/API.md`, `docs/DASHBOARD.md`, `docs/TESTING.md`, `docs/TROUBLESHOOTING.md`, `docs/BACKUP_GUIDE.md`
- `.github/copilot-instructions.md` — overlapping conventions reference
