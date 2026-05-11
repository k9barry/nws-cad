# Stale-open call guardrail (72h)

**Date:** 2026-05-11
**Status:** Approved (design)

## Problem

The CAD source is not guaranteed to emit a closing XML for every call. When it doesn't, the call sits in our database with `close_datetime = NULL` and `canceled_flag = 0` forever, so our SQL-derived "open" semantics — `canceled_flag = 0 AND (close_datetime IS NULL OR reopened_flag = 1)` — keep treating it as active indefinitely. Stale calls pile up in dashboards, status counts, and filter results.

## Goal

Treat any call as **closed** if it has been "open" for more than a configurable threshold (default **72 hours**) since `create_datetime`. The guardrail is SQL-only — no data mutation, no background job.

## Non-goals

- Mutating `close_datetime` on stale rows. Open/closed is a derived view; the raw row stays as the CAD source left it.
- Reclassifying based on last activity (e.g., max `unit_logs.log_datetime`). The clock starts at `create_datetime` and runs continuously. A reopen does **not** reset the clock.
- Touching the notifications pipeline. `IntentResolver` uses each incoming XML's `closed_flag` to decide intent for the in-process event — it never consults our SQL open/closed semantics, so it is unaffected.

## Configuration

Add one env var:

| Var | Default | Purpose |
|---|---|---|
| `STALE_OPEN_CALL_HOURS` | `72` | Hours since `create_datetime` after which an otherwise-open call is treated as closed. |

Wired into `Config` as `calls.stale_open_hours` (int). Read once per request via `Config::getInstance()->get('calls.stale_open_hours')`.

## Cutoff computation

At query time, compute a single cutoff timestamp in PHP:

```php
$hours  = (int) Config::getInstance()->get('calls.stale_open_hours');
$cutoff = (new DateTimeImmutable("-{$hours} hours"))->format('Y-m-d H:i:s');
```

Bind as a PDO param `:stale_cutoff`. ISO `Y-m-d H:i:s` parses cleanly in both MySQL and Postgres, so we sidestep the `INTERVAL` syntax difference between drivers without touching `DbHelper`.

## SQL semantics changes

**Stale predicate:** `calls.create_datetime < :stale_cutoff`

| Status | Current | New |
|---|---|---|
| `open` | `canceled_flag = 0 AND (close_datetime IS NULL OR reopened_flag = 1)` | `canceled_flag = 0 AND (close_datetime IS NULL OR reopened_flag = 1) AND create_datetime >= :stale_cutoff` |
| `closed` | `canceled_flag = 0 AND close_datetime IS NOT NULL AND reopened_flag = 0` | `canceled_flag = 0 AND ((close_datetime IS NOT NULL AND reopened_flag = 0) OR create_datetime < :stale_cutoff)` |
| `reopened` | `canceled_flag = 0 AND reopened_flag = 1` | `canceled_flag = 0 AND reopened_flag = 1 AND create_datetime >= :stale_cutoff` |
| `canceled` | `canceled_flag = 1` | unchanged |

Mutually exclusive: a row is either `open`, `closed`, `reopened`, or `canceled` (canceled wins; reopened is a subset of open that surfaces under both `open` and `reopened`). Stale calls move from `open`/`reopened` → `closed`.

## Files to change

### Server (SQL semantics)

- **`src/Config.php`** — add `calls` block:
  ```php
  'calls' => [
      'stale_open_hours' => (int) $this->env('STALE_OPEN_CALL_HOURS', '72'),
  ],
  ```
- **`src/Api/Filtering/FilterSqlBuilder.php`** (around L159-170) — the `status` match arm. Compute cutoff once per `applyFilters()` call, add `:stale_cutoff` to `$params` whenever a status filter is present, and update the match arms per the table above. Update the comment block above the match.
- **`src/Api/Controllers/StatsController.php`** — three spots:
  - L107-117: `CASE WHEN ... THEN 'open'/'closed'/'reopened' ...` group expression
  - L328: another `CASE WHEN` mirroring the same (uppercase labels)
  - L446-451: closed-only `WHERE close_datetime IS NOT NULL AND reopened_flag = 0` clause

  Each spot needs the stale predicate folded in and the cutoff bound. Where these queries run via `$db->query()` (no params), switch to `prepare()/execute()`.

### Server (API response shape — for client badges)

- **`src/Api/Controllers/CallsController.php`** (around L142, L264) and **`src/Api/Controllers/SearchController.php`** (around L243) — when mapping a call row into a response object, add a derived boolean field:

  ```php
  'is_stale' => /* computed from $row['create_datetime'] vs $cutoff */,
  ```

  `is_stale` is true iff `canceled_flag = 0 AND (close_datetime IS NULL OR reopened_flag = 1) AND create_datetime < $cutoff` — i.e., the row would have been classified `open` or `reopened` without the guardrail but is now reclassified as `closed`. (A row that is already legitimately closed has `is_stale = false`; a stale reopened row has `is_stale = true`.)

  Compute the cutoff once in the controller and reuse across all mapped rows.

### Client (badges)

- **`public/assets/js/dashboard.js`** (L510-521, `deriveCallStatus`) — if `call.is_stale` is true, return `'closed'`. Document the field in the comment.
- **`public/assets/js/maps.js`** (L170) — replace `call.closed_flag ? 'Closed' : 'Active'` with logic that honors `is_stale`.
- **`public/assets/js/mobile.js`** (L268, L1298) — same treatment.

### Docs

- **`.env.example`** — add `STALE_OPEN_CALL_HOURS=72` with comment.
- **`CLAUDE.md`** — add the new var to the "Key environment variables" table.
- **`docs/DASHBOARD.md`** if it documents status semantics — add a note about the 72h guardrail (skip if not present).

## Tests

- **`tests/Unit/Api/Filtering/FilterSqlBuilderTest.php`** — add cases asserting that each status arm produces the stale-aware SQL and binds `:stale_cutoff`. Use a fixed clock or just assert the SQL substring and the presence of the param key.
- **Integration test** (new or existing controller test) — seed two open calls, one with `create_datetime` 100h ago and one fresh; assert `?status=open` returns only the fresh one, `?status=closed` returns the stale one, and the stats endpoint counts them accordingly.
- **`tests/Unit/Api/Controllers/CallsControllerTest.php`** / **`SearchControllerTest.php`** — assert `is_stale: true` appears for the seeded stale row and `false` otherwise.
- **`tests/Unit/Api/Controllers/StatsControllerTest.php`** — adjust expectations so a stale open row counts toward `closed`.

## Risk / rollback

- **Rollback:** set `STALE_OPEN_CALL_HOURS` to a huge value (e.g. `876000` = 100 years) to effectively disable the guardrail without a code change.
- **Schema-free:** no migrations. `create_datetime` already has `idx_create_datetime` and a composite `idx_calls_create_closed (create_datetime, closed_flag, canceled_flag)` so the new predicate is index-friendly.
- **Backwards compatibility:** the API response gains a new `is_stale` field. Existing JS that doesn't know about it continues to work (uses the raw fields, same as before the change). New JS reads `is_stale` for the corrected badge.
