# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

PHP 8.3 system that monitors a `watch/` folder for NWS Aegis CAD XML files, parses them into a normalized 15-table schema (MySQL or PostgreSQL via `DB_TYPE`), exposes the data through a REST API and a real-time web dashboard, and emits ntfy.sh / Pushover notifications when calls are created/updated.

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

# Notifications channel management (CLI)
php bin/notifications.php list
php bin/notifications.php enable ntfy --base-url=https://ntfy.example
php bin/notifications.php disable pushover

# Reference data seeding (filter dropdowns)
php bin/seed-reference.php                           # uses database/seeds/reference.json
```

## Architecture

Two HTTP entry points sit in `public/` and dispatch to two distinct stacks:

```
/api/*  →  public/api.php   →  Api\Router  →  Api\Controllers\*  →  Api\Response::success()/error()
/*      →  public/index.php →  Mobile detection  →  Dashboard/Views/{dashboard.php | dashboard-mobile.php | notifications.php}
```

The file watcher (`src/watcher.php` / `FileWatcher.php`) is a separate long-lived process: it ingests XML via `AegisXmlParser`, writes to the database, and — after commit — dispatches a `CallProcessedEvent` that `OutboxWriter` consumes to queue per-channel rows in `notification_outbox`. The watcher loop drives `OutboxProcessor::tick()` to drain the queue and fan out to ntfy/Pushover/webhook.

### Core classes (PSR-4 `NwsCad\*` from `src/`)

| Class | Purpose |
|-------|---------|
| `Database` | Singleton PDO wrapper. `Database::getConnection()` returns the right driver based on `DB_TYPE` env var (`mysql` or `pgsql`). |
| `Config` | Singleton; reads from environment. `secret($name)` reads required env vars and registers them with `SecretRegistry` for log scrubbing; `secretOptional()` returns null on missing. |
| `Api\Router` | Pattern routes with `{id}` parameter extraction. |
| `Api\Response` | Static `success($data)` / `error($message, $code)` — controllers must never `echo`. In tests (detected via `PHPUNIT_COMPOSER_INSTALL`), the first `Response::json()` echoes; subsequent calls in the same request silently no-op (in production, `exit;` halts the process). Tests must call `Response::resetForTesting()` in `setUp()`. |
| `Api\Request` | Static helpers: `pagination()`, `sorting()`, `filters()`, `json()`. |
| `Api\DbHelper` | Database-agnostic SQL (`GROUP_CONCAT`/`STRING_AGG`, `COALESCE`, date functions). Validates SQL identifiers against `IDENTIFIER_PATTERN` before interpolation. |
| `AegisXmlParser` | Parses `*.xml`, transactionally writes to the 13 call-related tables, then dispatches `Notifications\Events\CallProcessedEvent`. |
| `Notifications\EventDispatcher` | Tiny in-process pub/sub. `subscribe()` is called once during watcher boot; `dispatch()` is called by the parser. |
| `Notifications\Outbox\OutboxWriter` | `CallProcessedEvent` subscriber. Filters Closed + delta-time, then inserts one `notification_outbox` row per enabled channel. |
| `Notifications\Outbox\OutboxProcessor` | Driven by `FileWatcher::setOnTick`. Per tick: prunes `done` rows >7d, resets orphan `in_flight` rows, claims a batch, runs each row's channel send (intent rules via `TopicResolver`: Created → all topics; Updated with new units only → just added topics; Updated with `call_type`/`full_address`/`alarm_level` change → all topics; Closed never reaches the outbox). Backoff: `[30s, 2m, 10m, 30m, 2h]` up to `OUTBOX_MAX_ATTEMPTS`. |
| `Notifications\Outbox\OutboxRepository` | DB-access layer for `notification_outbox`: `insert`, `prune`, `resetOrphans`, `claim`, `markDone`, `markRetry`, `markFailed`. |
| `Notifications\TopicResolver` | Pure helpers: `shouldResendAll(Intent, string[] $changedFields)` and `resolveTopics(IncidentDto, bool $resendAll, string[] $addedTopics)`. Shared by writer (write-time decision) and processor (process-time topic list). |
| `Notifications\Channels\{NtfyChannel,PushoverChannel}` | Send implementations with cURL + bounded retry (3 attempts, 1s/3s/9s backoff; 4xx is permanent, 5xx and network failures are retried). Both `final`. |
| `Logging\RedactingProcessor` | Globally registered Monolog processor; scrubs values from `SecretRegistry` out of every log record's `message`/`context`/`extra`. |
| `Api\Filtering\FilterCriteria` | URL → typed filter value object. Enforces 50-value cap, 256-char cap, allowlist. |
| `Api\Filtering\FilterSqlBuilder` | FilterCriteria → parameterized WHERE/JOIN/params via `SqlFragment`. |
| `Api\Filtering\FilterRegistry` | Static per-controller allowlist (`for('calls')`, `for('units')`, `for('stats')`). |
| `Api\Filtering\FilterOptionsCache` | In-process 5-minute cache for `/api/filter-options`. Invalidated by `AegisXmlParser` after writes. |

### Controller pattern

Controllers are instantiated per-request, hold a `PDO $db = Database::getConnection()`, and every public method returns `void` and emits via `Response::`:

```php
public function index(): void {
    $data = $this->db->query("...")->fetchAll();
    Response::success($data);
}
```

### Health checks & DB profile

- `GET /api/health` — `HealthController::index()` runs `SELECT 1`. Returns `{success:true,data:{status:"ok",db:"ok",timestamp:<ISO8601>}}` on success, or 503 with `{success:false,error:"Database unreachable",errors:{db:"unreachable"}}`. The `api` compose healthcheck curls this route.
- `FileWatcher::start()` writes `logs/.watcher-heartbeat` (touch) on boot and at the top of every loop iteration. The `app` compose healthcheck flags the container unhealthy if the heartbeat mtime is older than 60 seconds. **Caveat:** if `WATCHER_INTERVAL` is set above ~30 seconds, the 60-second staleness window will produce flapping — keep `WATCHER_INTERVAL` ≤ 30s (default 5s) or widen the threshold in `docker-compose.yml`.
- `mysql` is in compose profile `mysql`; `postgres` is in compose profile `pgsql`. Set `COMPOSE_PROFILES=$DB_TYPE` (in shell or `.env`) so only the active DB starts. Running `docker compose up -d` without selecting a profile starts no database and `app`/`api` will block on `service_healthy`.

### Cross-DB SQL

Never write MySQL- or Postgres-only SQL inline. Use `DbHelper`:

```php
DbHelper::groupConcat('column', ', ', true);   // GROUP_CONCAT vs STRING_AGG
DbHelper::dateFormat('create_datetime', '%Y-%m-%d');
```

`watcher.php`'s `incidentLoader` does this for the multi-table SELECT that builds the `IncidentDto` — copy that pattern.

### API response shape

```json
{ "success": true, "data": { "items": [...], "pagination": { "total": 150, "per_page": 30, "current_page": 1, "total_pages": 5 } } }
```

Endpoints address calls by the internal `id` field (`/api/calls/{id}`), **not** `call_id`.

### Database schema

21 normalized tables: 13 anchored on `calls`, `processed_files`, 3 for the notifications module, and 5 `ref_*` reference tables.

```
calls (1) → agency_contexts (N), locations (1), incidents (N), narratives (N),
            persons (N), vehicles (N), call_dispositions (N),
            units (N) → unit_personnel (N), unit_logs (N), unit_dispositions (N)
processed_files (file processing history)
notification_channels (N) → notification_send_log (N, ON DELETE CASCADE)
notification_outbox (transactional per-channel notification queue)
ref_agencies, ref_areas, ref_beats, ref_fdids, ref_oris (filter-dropdown reference data)
```

**Schema files — three of them, must be kept in sync:**
- `database/mysql/init.sql` — used by Docker compose (`mysql:8.0` initdb).
- `database/postgres/init.sql` — used by Docker compose (`postgres:16` initdb).
- `database/schema.sql` — used by **CI** in `tests.yml` to seed `nws_cad_test`. Add new tables here too or CI will be missing them.

### JavaScript (`public/assets/js/`)

`dashboard.js` defines a global `Dashboard` object used by all page-specific modules. Always use:

- `Dashboard.apiRequest(endpoint)` — fetch wrapper with consistent error handling.
- `Dashboard.escapeHtml(text)` — XSS prevention; **must wrap any user-derived value rendered into HTML**. The `notifications.php` view builds list items via `createElement` + `textContent` since the source data (CAD field names, agency/jurisdiction strings) is untrusted.
- `Dashboard.formatTime(datetime)`, `Dashboard.buildQueryString(params)`.
- `FilterPanel` (`public/assets/js/filters/FilterPanel.js`) — universal filter component. Mount via `<div data-filter-panel data-fields="...">`. Owns URL sync, restore-banner, Choices.js + Flatpickr widgets.

## Notifications

After `AegisXmlParser::processFile()` commits, it dispatches a `CallProcessedEvent` (`Created` / `Updated` / `Closed`, plus `changedFields[]` and `addedTopics[]`) through an in-process `EventDispatcher`. `OutboxWriter` (registered in `src/watcher.php`) applies the delta-time + Closed gates and inserts one `notification_outbox` row per enabled channel. `OutboxProcessor::tick()` runs once per `FileWatcher` loop iteration to drain the outbox: it claims a batch, sends through each channel, records results in `notification_send_log` (auto-pruned to 100 rows per channel), and marks rows `done` / retries with `[30s, 2m, 10m, 30m, 2h]` backoff / `failed` after `OUTBOX_MAX_ATTEMPTS`. Secrets come from env vars via `Config::secret()`; `RedactingProcessor` scrubs them from all log output. ntfy topic strings derived from CAD data are sanitized via `TopicSanitizer` (whitelist `[A-Za-z0-9_-]`, collapse, trim, return null on empty) and additionally `rawurlencode`d before hitting the URL. See [docs/NOTIFICATIONS.md](docs/NOTIFICATIONS.md) for the operator + developer reference.

## Conventions

- All PHP files start with `declare(strict_types=1);`.
- Prepared statements only; identifier validation in `DbHelper`.
- XXE protection: do not enable `LIBXML_NOENT` when calling SimpleXML; use `LIBXML_NONET`.
- CORS origin whitelist lives in `Security/SecurityHeaders.php`.
- `LogsController` is disabled by default in production. Enabling it (`APP_LOGS_ENABLED=true`) also requires `LOGS_ADMIN_USERS` to be set to a csv allowlist of identity-header users; an empty allowlist in prod is treated as deny (fail-secure). Non-production environments remain open.
- Coordinate inputs are validated (lat ±90, lng ±180); LIKE patterns are escaped.
- Never `extract($row)` from DB rows. Use explicit DTO mapping (see `IncidentDto::fromRow()` for the pattern).
- Notification secrets are referenced by **env-var name** (`auth_token_env`) in `notification_channels.config_json`, never by literal value. Read them at send time with `Config::secret($envName)`.

## PR merge protocol

Every push to `main` triggers `.github/workflows/release.yml`, which derives the version bump from **conventional-commit subjects** since the latest `v*` tag (squash-merge PR titles become those subjects, so PR titles must be conventional):

| Commit type | Release effect |
|---|---|
| `feat:` | minor |
| `fix:` / `perf:` / `refactor:` / `revert:` | patch |
| `type!:` or `BREAKING CHANGE:` footer | major |
| `chore:` / `docs:` / `test:` / `ci:` / `style:` / `build:` | **no release** |
| non-conventional subject | patch + workflow warning |

The latest `v*` tag is the version source of truth; the `VERSION` file is derived output written by the release commit (`chore(release): vX.Y.Z [skip ci]`). Release notes and `CHANGELOG.md` entries are grouped by type. A `workflow_dispatch` run with `dry_run: true` prints the computed bump without releasing; `force_bump` overrides detection. There is still no draft stage — any releasable merge ships, so keep PRs coherent.

**Reviews run through qodo only** (Copilot review is not used):

- **qodo-code-review** — posts a walkthrough summary plus a "Code Review by Qodo" comment with a `🐞 Bugs (N)` count. `N > 0` means there are findings to address; walkthrough-only summaries with no findings are fine to skip.

After `gh pr create`, wait for qodo (typically ≤ 2 minutes), then run the qodo review loop (`qodo-skills:qodo-pr-resolver`): read the findings, fix genuine ones and push to the same branch (qodo re-reviews on new commits), reply to any false positives with justification, and only merge once qodo is clean. Do **not** request or wait for the Copilot reviewer. The wait-for-review discipline was established after v1.1.16 (PR #40) shipped a regression a reviewer had flagged but that was merged before the review appeared, forcing v1.1.17 (PR #41) the same day.

## Test conventions (load-bearing for CI)

`phpunit.xml` sets `requireCoverageMetadata="true"`, `beStrictAboutCoverageMetadata="true"`, `failOnRisky="true"`, `failOnWarning="true"`. Several gotchas follow from those:

- **Every test class needs `@covers <Class>`** (or `@coversNothing` for performance tests). Without it, every test method is risky.
- **Every class transitively executed by a test must be `@covers`'d or `@uses`'d** at the class level, *only when running with the coverage driver* (CI (`tests.yml`) uses xdebug; the local docker container installs pcov via `Dockerfile`, so strict-coverage failures reproduce there by default). For a controller test, `@uses \NwsCad\Api\Response`, `\NwsCad\Database`, `\NwsCad\Config`, `\NwsCad\Logger`, `\NwsCad\Logging\RedactingProcessor`, `\NwsCad\Logging\SecretRegistry` is the typical minimum set.
- **Tests that call a controller** must call `Response::resetForTesting()` in `setUp()` because `Response::json()` no-ops on the second call within a request in testing mode.
- **`cleanTestDatabase()` uses `DELETE FROM` + `ALTER TABLE ... AUTO_INCREMENT = 1`** — not `TRUNCATE`. Some MySQL versions reject `TRUNCATE` on a parent table referenced by a FK even with `FOREIGN_KEY_CHECKS=0`. Resetting auto-increment is required because several integration tests hard-code primary-key values.
- **Test DB user** — CI uses `test_user` / `test_pass`; local docker uses `nws_user` / the compose-supplied password. The PHPUnit `<env>` tags in `phpunit.xml` only apply when the env var isn't already set, so the docker container's pre-baked env wins inside it.

## Key environment variables

| Var | Purpose |
|---|---|
| `DB_TYPE` (`mysql`\|`pgsql`) | Database driver |
| `MYSQL_*` / `POSTGRES_*` | Connection details (host/port/database/user/password) |
| `API_PORT` (default 8080) | API server port |
| `WATCHER_INTERVAL` (seconds) | File-watcher poll interval |
| `LOG_LEVEL`, `APP_ENV` | Logging and environment selection |
| `NOTIFICATION_DELTA_SECONDS` (default 900) | Delta-time gate evaluated at outbox-write time |
| `OUTBOX_BATCH_SIZE` (default 10) | Max outbox rows claimed per FileWatcher tick |
| `OUTBOX_MAX_ATTEMPTS` (default 5) | Permanent-failure threshold for outbox rows |
| `LOGS_ADMIN_USERS` (csv, default empty) | Allowlist of identity-header users permitted to read `/api/logs` in production. Empty in prod = denied (fail-secure). Ignored outside prod. |
| `STALE_OPEN_CALL_HOURS` (default 72) | Guardrail: a call open this many hours since `create_datetime` is reclassified as closed in SQL status filters, stats counts, and dashboard badges (via `is_stale` on API rows). Raw rows are not mutated. |
| `NTFY_AUTH_TOKEN`, `NTFY_BASE_URL` | Required when an `ntfy` channel is enabled |
| `PUSHOVER_TOKEN`, `PUSHOVER_USER`, `PUSHOVER_BASE_URL` | Required when a `pushover` channel is enabled |

## Further docs

- `README.md`, `docs/README.md` (documentation index), `CHANGELOG.md`
- `docs/API.md`, `docs/DASHBOARD.md`, `docs/TESTING.md`, `docs/TROUBLESHOOTING.md`, `docs/BACKUP_GUIDE.md`, `docs/NOTIFICATIONS.md`
- `docs/superpowers/specs/2026-05-07-nws-endpoints-consolidation-design.md`, `docs/superpowers/plans/2026-05-07-nws-endpoints-consolidation.md` — design + implementation plan for the v1.2.0 notifications consolidation
- `.github/copilot-instructions.md` — overlapping conventions reference
