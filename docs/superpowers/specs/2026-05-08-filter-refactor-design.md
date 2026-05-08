# Filter Refactor — Unified Filtering Across the UI

**Date:** 2026-05-08
**Status:** Approved (brainstorming → writing-plans)
**Owner:** k9barry

## Problem

The current codebase has two divergent filter implementations: `FilterManager` (615 lines, desktop, in `public/assets/js/filter-manager.js`) and an inline `filters` object in `public/assets/js/mobile.js`. They use different parameter names (`call_status` vs `status`, `quick_period` vs `quick_select`), different date-handling conventions (mobile appends times, desktop relies on the API to do it), and overlap on functionality without sharing code. The API layer (`Api\Request::filters()` plus per-controller WHERE-building in `CallsController` and `StatsController`) only exposes a fraction of the schema's filterable fields — `call_type`, `incident_type`, `ORI`, `FDID`, `unit_number`, beat, area, and others all exist in the database but cannot be filtered through the UI.

The user wants one consistent, powerful filter system across every list page (current dashboard plus new `/calls` and `/units` pages), with multi-select on every categorical field, a date/time range picker with presets, and URL-shareable state.

## Goals

- **One filter implementation** shared across desktop dashboard, mobile dashboard, the new `/calls` page, and the new `/units` page.
- **Full filter vocabulary**: date range + presets, call type, incident type, nature-of-call free text, agency, ORI, FDID, beat, area, city, location, call ID, unit #, and open/closed/canceled status — all multi-select where categorical.
- **URL is canonical state.** Reload, share, and bookmark all work. Back/forward navigation restores prior filter state.
- **Security-equivalent or better than current** — every filter value is parameterized through PDO, length-capped, and validated against an allowlist per controller.
- **Speed-equivalent or better** — composite indexes added for every new filter column; option lists cached server-side and client-side.
- **Legacy code is removed**, not left as a fallback.

## Non-goals

- Replacing the notifications settings page — it does not show calls and does not need filtering.
- Real-time websocket updates of filtered lists — out of scope; existing polling is preserved.
- A backend admin UI for managing reference data — seed file (`database/seeds/reference.json`) and CLI (`php bin/seed-reference.php`) only.
- Filter persistence across users — each browser keeps its own URL/localStorage state. No server-side per-user preferences.

## Design decisions (from brainstorming)

| Decision | Choice |
|---|---|
| Selection mode | Multi-select on every categorical field |
| Library appetite | Add Flatpickr (date/time + ranges) and Choices.js (multi-select chips); vendor locally, no CDN |
| State model | URL query string is canonical; localStorage stores last-state for restore-on-fresh-tab via banner |
| Option source | Hybrid — curated reference tables for stable identifiers (agency, ORI, FDID, beat, area), derived from live data for variable categories (call_type, incident_type, city, unit). Curated lists seeded from a JSON file at `database/seeds/reference.json`. |
| Page coverage | Refactor dashboard (desktop + mobile); add new `/calls` and `/units` list pages |
| `incident_type` vs `nature_of_call` | Two separate filters: `incident_type` is exact-match multi-select on `incidents.incident_type`; `nature_of_call` is a LIKE search on `agency_contexts.nature_of_call` |
| Apply mode | Live filtering, 250 ms debounce; no Apply button, only Reset |
| FDID storage | Add `agency_contexts.fdid VARCHAR(10)` column populated by `AegisXmlParser` with fallback to `ref_agencies.fdid` lookup |
| Date field default | `date_field=created` (filters `calls.create_datetime`); switchable to `closed` |

## Architecture

```
URL query string  ──►  FilterPanel.js  ──►  /api/filter-options
                            │                       │
                            └─emit filterchange─┐   ▼
                                                │  Api\Filtering\
                                                │  FilterCriteria
                                                │  FilterSqlBuilder
                                                │  FilterRegistry
                                                │
              Page controller (dashboard / calls / units)
                              │
                              ▼
                  GET /api/calls?<filters>  ──►  PDO + DbHelper  ──►  DB
```

### Five components

1. **URL query string** — single source of truth. Every navigation, reload, bookmark, and shared link reads it.
2. **`FilterPanel`** — universal JS component. One mount per page, declarative `data-fields` config.
3. **`/api/filter-options`** — new GET endpoint returning merged curated + derived option lists.
4. **`Api\Filtering\*`** — new PHP namespace (`FilterCriteria`, `FilterSqlBuilder`, `FilterContext`, `FilterRegistry`, `InvalidFilterException`).
5. **Reference tables** — `ref_agencies`, `ref_oris`, `ref_fdids`, `ref_beats`, `ref_areas`, all seeded from a checked-in JSON file.

### Two invariants

- **URL format is identical across pages.** A user can copy `?call_type=Fire&ori=IN0480000` from `/calls` to `/units` and it works.
- **A page filters only by fields its controller permits.** Backend allowlist (`FilterRegistry::for($controller)`) silently drops unsupported keys. Logs a debug-level entry; never errors. Keeps cross-page URL sharing forgiving.

## Filter contract (URL schema)

The wire format. Same on every page, every endpoint.

### Format rules

- Multi-select values are comma-separated: `?call_type=Police,Fire,EMS`
- Date and datetime use ISO 8601: `?from=2026-05-01` or `?from=2026-05-01T08:00:00`
- Identifier filters (Call ID, Unit #) accept comma-separated multi
- LIKE-style text filters use a single value
- Boolean-ish filters use canonical labels: `?status=open,closed`, never `?closed_flag=0`
- Empty/missing param = filter not applied
- `preset` (reserved) carries the date preset: `?preset=today`. When set, server resolves to `from`/`to`. If both `preset` and explicit `from`/`to` arrive, explicit dates win and `preset` is dropped on the next URL update.

### Filter vocabulary

| Param | Type | Example | Backing column(s) |
|---|---|---|---|
| `preset` | enum | `today`, `yesterday`, `last_7_days`, `last_30_days`, `this_month`, `last_month` | (resolved server-side) |
| `from` | date or datetime | `2026-05-01` | `calls.create_datetime >=` |
| `to` | date or datetime | `2026-05-08` | `calls.create_datetime <=` (end-of-day if date-only) |
| `date_field` | enum | `created` (default), `closed` | switches the column the date applies to |
| `call_type` | csv enum | `Police,Fire,EMS` | `agency_contexts.call_type` |
| `incident_type` | csv string | `Traffic Stop,Seizure` | `incidents.incident_type` |
| `nature_of_call` | string (LIKE) | `jaywalk` | `agency_contexts.nature_of_call` |
| `agency` | csv string | `Pendleton Police,Edgewood Fire` | `agency_contexts.agency_type` joined to `ref_agencies.label` |
| `ori` | csv string | `IN0480000,IN0480200` | `locations.police_ori` OR `ems_ori` OR `fire_ori` |
| `fdid` | csv string | `48002,48013` | `agency_contexts.fdid` (new column) |
| `beat` | csv string | `B1,B2` | `locations.police_beat` |
| `area` | csv string | `Quad-1,EMS-3` | `locations.fire_quadrant` OR `locations.ems_district` |
| `city` | csv string | `Pendleton,Edgewood` | `locations.city` |
| `location` | string (LIKE) | `Main St` | `locations.full_address` OR `common_name` |
| `call_id` | csv string | `2026-001234` | `calls.call_number` |
| `unit` | csv string | `41,42,P-3` | `units.unit_number` |
| `status` | csv enum | `open,closed,canceled` | derived from `closed_flag` + `canceled_flag` |
| `q` | string | `john doe` | full-text-ish across narratives + caller name + incident # |

### Status semantics

- `open` → `closed_flag = 0 AND canceled_flag = 0`
- `closed` → `closed_flag = 1 AND canceled_flag = 0`
- `canceled` → `canceled_flag = 1`
- Multiple values OR'd together. `status=open,closed` shows everything except canceled.
- Default when omitted: no status filter — all rows match regardless of status.

### Hard limits (security/abuse)

- Each csv list capped at 50 values server-side. Over → 400 with `{success:false, error:"Too many values for filter X (max 50)"}`.
- Each string value capped at 256 chars.
- Total query string capped at 4 KB.
- Identifier-style fields (`ori`, `fdid`, `beat` codes) pass `IDENTIFIER_PATTERN` check before binding.
- All values bound as PDO parameters. Zero string interpolation of user input.

### Pagination & sorting (orthogonal but ride along)

- `page=2`, `per_page=30`, `sort=create_datetime:desc` — already supported by `Request::pagination()` and `Request::sorting()`. Preserved.

## Backend

### `Api\Filtering\FilterCriteria`

Parses the URL into a typed value object. All security limits enforced here.

```php
final class FilterCriteria
{
    public function __construct(
        public readonly ?DateRange $dateRange,
        public readonly string $dateField,        // 'created' | 'closed'
        public readonly array $callType,
        public readonly array $incidentType,
        public readonly array $agency,
        public readonly array $ori,
        public readonly array $fdid,
        public readonly array $beat,
        public readonly array $area,
        public readonly array $city,
        public readonly ?string $location,
        public readonly ?string $natureOfCall,
        public readonly array $callId,
        public readonly array $unit,
        public readonly array $status,
        public readonly ?string $search,
    ) {}

    public static function fromQuery(array $query, array $allowed): self;
    public function toArray(): array;
}
```

- `fromQuery()` enforces the 50-value cap, 256-char cap, identifier charset checks. Filters not in `$allowed` are silently dropped (debug log line).
- Invalid values throw `InvalidFilterException`. Controllers catch and return 400.
- `DateRange` resolves `preset` server-side using `APP_TZ` env (default `America/Indiana/Indianapolis`). End-of-day expansion happens here, fixing the current desktop/mobile inconsistency.

### `Api\Filtering\FilterSqlBuilder`

Turns a `FilterCriteria` into SQL fragments via `DbHelper` for cross-DB compatibility.

```php
final class FilterSqlBuilder
{
    public function build(FilterCriteria $f, FilterContext $ctx): SqlFragment;
}

final class SqlFragment
{
    public string $whereClause;      // "WHERE x = :p1 AND y IN (:p2,:p3)"
    public array  $params;           // ['p1' => ..., 'p2' => ...]
    public array  $joins;            // ["JOIN locations l ON l.call_id = calls.id", ...]
}
```

- Every value bound as a PDO param.
- `FilterContext` declares which tables the controller's base query already references; the builder only emits joins it needs.
- Status decoding: `status=open` → `(closed_flag = 0 AND canceled_flag = 0)`; multi-status → OR.
- ORI multi-column: matched against `police_ori`, `ems_ori`, `fire_ori` with OR.

### `Api\Filtering\FilterRegistry`

Static per-controller allowlist.

```php
FilterRegistry::for('calls')   // ['date','call_type','incident_type','nature_of_call','agency','ori','fdid','beat','area','city','location','call_id','unit','status','q']
FilterRegistry::for('units')   // ['date','agency','unit','status','call_id']
FilterRegistry::for('stats')   // ['date','agency','ori','fdid','city','call_type']
```

Defined as a static array in `src/Api/Filtering/FilterRegistry.php`. Adding a filter to a page is a code change. Version-controlled; cheap to read.

### Controller integration pattern

```php
public function index(): void {
    try {
        $criteria = FilterCriteria::fromQuery($_GET, FilterRegistry::for('calls'));
    } catch (InvalidFilterException $e) {
        Response::error($e->getMessage(), 400);
        return;
    }
    $pagination = Request::pagination();
    $sort       = Request::sorting(['create_datetime', 'call_number', 'priority']);
    $sql        = $this->filterSqlBuilder->build($criteria, new FilterContext('calls', ['calls']));

    $stmt = $this->db->prepare(
        'SELECT ... FROM calls ' . implode(' ', $sql->joins)
        . ' ' . $sql->whereClause
        . ' ORDER BY ' . $sort['column'] . ' ' . $sort['direction']
        . ' LIMIT :limit OFFSET :offset'
    );
    $stmt->execute([...$sql->params, 'limit' => $pagination['per_page'], 'offset' => $pagination['offset']]);
    Response::success([
        'items'      => $stmt->fetchAll(),
        'pagination' => $this->countAndPaginate($criteria, $sql, $pagination),
        'filters'    => $criteria->toArray(),
    ]);
}
```

The echoed `filters` object lets the frontend confirm what actually got applied (useful when the controller dropped unsupported keys).

### `/api/filter-options` endpoint

```
GET /api/filter-options?fields=agency,ori,city,call_type
→ { success: true, data: {
      agency:    [{value:"PEN_PD", label:"Pendleton Police", ori:"IN0480000"}, ...],
      ori:       [{value:"IN0480000", label:"IN0480000 (Pendleton PD)"}, ...],
      city:      ["Pendleton", "Edgewood", ...],
      call_type: ["Police","Fire","EMS"],
    }}
```

- Curated lists pulled from `ref_*` tables; derived lists pulled with `SELECT DISTINCT ... ORDER BY` capped at 1000 distinct values.
- Server-side cache: in-process static, 5-minute TTL. Cache key includes the `fields` set.
- Cache invalidation: `AegisXmlParser` calls `FilterOptionsCache::invalidate(['call_type','incident_type','unit_number',...])` after writing rows. Curated lists never need invalidation (only change via reseed).
- Client-side cache: `Cache-Control: max-age=30, stale-while-revalidate=300`.
- Rate limit: 30 req/min per IP via in-process counter; logs warning on breach.

## Frontend

### Mount pattern

```html
<div id="filter-panel"
     data-filter-panel
     data-fields="date,call_type,incident_type,nature_of_call,agency,ori,fdid,beat,area,city,location,call_id,unit,status"
     data-compact="false"></div>
```

### `FilterPanel` API

```js
const panel = new FilterPanel({
  root: document.getElementById('filter-panel'),
  optionsEndpoint: '/api/filter-options',
  onChange: (state) => Dashboard.loadCalls(state.toQueryString()),
});
await panel.mount();
panel.getState();
panel.setState(partial);
panel.clear();
panel.destroy();
```

### File layout

```
public/assets/js/filters/
  FilterPanel.js          ← orchestrator
  FilterState.js          ← URL ⇄ state, localStorage backup
  fields/
    DateRangeField.js     ← Flatpickr range + preset buttons
    MultiSelectField.js   ← Choices.js wrapper
    TextField.js          ← debounced input
    StatusField.js        ← chip toggle
  fieldRegistry.js        ← field name → constructor
  filters.css             ← scoped to .filter-panel-*
public/assets/vendor/
  choices/                ← Choices.js vendored
  flatpickr/              ← Flatpickr vendored
```

### Data flow

1. `mount()` calls `GET /api/filter-options?fields=<declared fields>` once. Cached in localStorage keyed by field-set + ETag.
2. Reads URL → `FilterState.fromQuery(window.location.search)`. Validates against declared fields.
3. Renders one widget per declared field, pre-populated.
4. User interaction → field emits `change` → `FilterState.merge()` → 250 ms debounce → `history.replaceState()` updates URL → `onChange(state)` fires → page re-fetches.
5. Browser back/forward → `popstate` listener re-reads URL → re-renders widgets in place.

Live filtering only — no Apply button. Only Reset.

### localStorage restore

`filter-panel:last-state` written on every state change. On a fresh tab opened with no query string, the panel shows a 3-second slide-in banner offering *"Restore last filter"*. Auto-applying would be too surprising for power users opening fresh tabs.

### Mobile compact mode

`data-compact="true"` collapses to a horizontal chip strip showing only *active* filters; tap a chip → opens a bottom-sheet editor. Same `FilterPanel` class, different render path internally.

### Accessibility

- All widgets keyboard-navigable. Choices.js provides this; Flatpickr's `allowInput` mode enabled.
- `<div aria-live="polite">` announces "Filters applied: 3 active" when state settles.
- Each field has an explicit `<label for>` paired to the underlying control.

### XSS guarantees

- Choices.js configured with `allowHTML: false`.
- Every option label rendered into HTML routed through `Dashboard.escapeHtml()`.
- The `<div aria-live>` announcer uses `textContent`, never `innerHTML`.

## Database changes

### New reference tables

Added to all three schema files (`database/mysql/init.sql`, `database/postgres/init.sql`, `database/schema.sql`).

```sql
ref_agencies   (id, code, label, kind ENUM('police','fire','ems'), ori, fdid, active, sort_order)
ref_oris       (ori PRIMARY KEY, label, kind, agency_id FK)
ref_fdids      (fdid PRIMARY KEY, label, agency_id FK)
ref_beats      (id, code, label, kind, jurisdiction, active)
ref_areas      (id, code, label, kind ENUM('fire_quad','ems_district'), active)
```

Seeded from `database/seeds/reference.json` (JSON chosen because the project already uses JSON for notification channel config and it requires no new PHP extensions). New CLI: `php bin/seed-reference.php` (idempotent upsert).

### New column

`agency_contexts.fdid VARCHAR(10) NULL` — populated by `AegisXmlParser` from XML, falling back to `ref_agencies.fdid` lookup by `agency_type` if absent.

### New indexes

```sql
CREATE INDEX idx_calls_create_closed ON calls (create_datetime, closed_flag, canceled_flag);
CREATE INDEX idx_ac_call_type        ON agency_contexts (call_type);
CREATE INDEX idx_ac_agency_type      ON agency_contexts (agency_type);
CREATE INDEX idx_ac_fdid             ON agency_contexts (fdid);
CREATE INDEX idx_loc_police_ori      ON locations (police_ori);
CREATE INDEX idx_loc_ems_ori         ON locations (ems_ori);
CREATE INDEX idx_loc_fire_ori        ON locations (fire_ori);
CREATE INDEX idx_loc_police_beat     ON locations (police_beat);
CREATE INDEX idx_loc_fire_quad       ON locations (fire_quadrant);
CREATE INDEX idx_loc_ems_district    ON locations (ems_district);
CREATE INDEX idx_loc_city            ON locations (city);
CREATE INDEX idx_units_unit_number   ON units (unit_number);
CREATE INDEX idx_inc_incident_type   ON incidents (incident_type);
```

### Migration

`database/migrations/2026-05-08-filter-refactor.sql` (and `.pgsql.sql` if Postgres dialect needs separate). Idempotent SQL; documented in CHANGELOG.

## Page integration

### New pages

- `src/Dashboard/Views/calls.php` — full call-list view; route `/calls` in `public/index.php`
- `src/Dashboard/Views/units.php` — unit-centric list; route `/units`
- `src/Api/Controllers/UnitsController.php` — basic list endpoint with FilterCriteria

Mobile detection in `public/index.php` continues to switch dashboard between `dashboard.php` and `dashboard-mobile.php`. The new `/calls` and `/units` pages render one responsive template, switching the panel into compact mode under a `@media (max-width: 640px)` rule.

### Refactored files

- `src/Api/Controllers/CallsController.php` — `index()` rewired to `FilterCriteria` + `FilterSqlBuilder`; per-controller filter parsing block removed.
- `src/Api/Controllers/StatsController.php` — same rewire.
- `public/assets/js/dashboard.js` — ~100 lines of filter wiring replaced with the 5-line `FilterPanel` mount.
- `public/assets/js/dashboard-main.js` — same pattern.
- `src/Api/Request.php` — `Request::filters()` becomes a thin shim around `FilterCriteria::fromQuery()` for any controller not yet migrated. Deleted in the same change if no remaining callers.

## Legacy removal

Deleted in the same change:

- `public/assets/js/filter-manager.js` (615 lines)
- The `filters` object and `buildFilterParams()` block in `public/assets/js/mobile.js` (lines 32, 82–93, 228, 274–303)
- `src/Dashboard/Views/partials/filter-modal.php`
- `src/Dashboard/Views/partials-mobile/filters-modal.php`
- The per-controller filter parsing block in `CallsController::index()` (lines 36–124)
- The `$allowedFilters` block in `StatsController::index()` (lines 41–71)

A new shared partial `src/Dashboard/Views/partials/filter-panel.php` replaces both legacy modals.

## Security

The current pattern (PDO prepared statements, identifier validation, CORS whitelist, `RedactingProcessor`) is sound. The new code preserves it and adds:

1. **Three-stage filter value defense:** length cap (256/value, 50/multi), charset check via `IDENTIFIER_PATTERN` for strict-identifier fields, PDO param binding for everything else.
2. **`/api/filter-options` is read-only** and exposes only data the dashboard already shows. No new sensitive surface.
3. **Rate limit** on `/api/filter-options`: 30 req/min per IP via in-process counter; Monolog warning on breach.
4. **Choices.js `allowHTML: false`**; every label rendered into HTML routed through `Dashboard.escapeHtml()`; `aria-live` announcer uses `textContent`.
5. **Filter values logged at DEBUG only**, never INFO — they may contain free-text search with operationally sensitive details. `RedactingProcessor` continues to scrub registered secrets.
6. **CSRF n/a** — these are GET endpoints, no state change.

## Testing

Mirrors existing convention: PHPUnit suites, strict coverage, `@covers`/`@uses` on every test class.

### Unit (`tests/Unit/Filtering/`)

- `FilterCriteriaTest` — every filter parses correctly, security limits enforced, invalid inputs throw, allowlist drops unsupported keys, preset → date-range resolution per timezone.
- `FilterSqlBuilderTest` — every filter produces correct WHERE/JOIN, params bound (snapshot-style asserts), status decoding, multi-status OR, cross-DB SQL via `DbHelper`.
- `FilterRegistryTest` — each registered controller has a non-empty allowlist; no typos in field names.

### Integration (`tests/Integration/`)

- `CallsControllerFilterTest` — every filter returns the right rows from a seeded test DB. One test per filter + a combo test.
- `UnitsControllerFilterTest` — same for units.
- `FilterOptionsEndpointTest` — curated + derived merged correctly, cache hit/miss behavior, invalidation after `AegisXmlParser` ingest.

### Performance (`tests/Performance/`)

- `FilterPerformanceTest` — seed 100k calls, assert each filter completes <100 ms with the new indexes in place.

### Frontend

Manual test matrix in the spec covers: every field, URL round-trip, back/forward navigation, mobile compact mode, restore-last-filter banner, accessibility (keyboard navigation, screen-reader announcer).

### Coverage

New code held to 80% line coverage minimum (matches `phpunit.xml`). All new test classes get `@covers`/`@uses` on the relevant new and touched classes.

## Open questions / risks

- **FDID source verification.** I assumed FDID is either present in the Aegis XML or derivable from `ref_agencies.fdid` keyed by `agency_type`. Implementation should verify the actual XML schema and confirm the population path before writing parser code.
- **Choices.js + Flatpickr versions.** Vendoring means we own updates. Pick LTS-style stable versions (Choices.js 10.x, Flatpickr 4.x) and document them in CHANGELOG so future maintenance is explicit.

## Deliverables checklist

- [ ] New PHP namespace `src/Api/Filtering/` (FilterCriteria, FilterSqlBuilder, FilterRegistry, FilterContext, SqlFragment, InvalidFilterException, DateRange, FilterOptionsCache)
- [ ] New endpoint controller for `/api/filter-options`
- [ ] New `UnitsController`
- [ ] Refactored `CallsController`, `StatsController`
- [ ] New `public/assets/js/filters/` (FilterPanel, FilterState, field components, fieldRegistry, filters.css)
- [ ] Vendored Choices.js + Flatpickr under `public/assets/vendor/`
- [ ] New shared partial `partials/filter-panel.php`
- [ ] New views `calls.php`, `units.php` and routes in `public/index.php`
- [ ] Refactored dashboard JS (desktop + mobile) to mount `FilterPanel`
- [ ] Schema migration applied to all three SQL files + dialect-specific migration files
- [ ] New `agency_contexts.fdid` column populated in `AegisXmlParser`
- [ ] Reference seed JSON file and `php bin/seed-reference.php` CLI
- [ ] Cache invalidation hook in `AegisXmlParser`
- [ ] Tests: unit, integration, performance per the strategy above
- [ ] Legacy files deleted (filter-manager.js, mobile filter logic, two legacy filter-modal partials, per-controller filter parsing blocks)
- [ ] CHANGELOG and CLAUDE.md updated

## Out of scope (future work)

- Server-side per-user filter preferences
- WebSocket / SSE live updates of filtered list
- Admin UI for managing reference data
- Saved filter presets ("My open Pendleton fires") — possible follow-up after the base refactor lands
