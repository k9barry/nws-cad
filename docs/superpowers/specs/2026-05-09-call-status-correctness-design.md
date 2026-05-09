# Call Status Correctness — Closed Calls Showing as Active

**Date:** 2026-05-09
**Status:** Approved (brainstorming → writing-plans)
**Owner:** k9barry

## Problem

Closed calls remain visible on the dashboard's default "Today + Open" view when they should be filtered out. Two distinct root causes produce this single user-visible symptom.

### Bug A — Out-of-order XML processing

The CAD source occasionally emits two XMLs for the same call within a few seconds. They can arrive in the watch folder in **reverse** filename-timestamp order. The watcher's existing "older versions" check only compares files visible within a single scan iteration. When the older XML arrives in a later scan, it is processed normally and overwrites the newer XML's state — including flipping `calls.closed_flag` from `1` back to `0`.

Concrete evidence (call id 682, "Child Offense", 2026-05-09):

| Filename | Embedded ts | Processed at (log) | Intent dispatched |
|---|---|---|---|
| `163_2026050912203674.xml` | 12:20:**36** (newer) | 12:20:43 | Closed |
| `163_2026050912203171.xml` | 12:20:**31** (older) | 12:20:47 | Updated (clobbered closed state) |

The older XML had `<ClosedFlag>false</ClosedFlag>` at the root, so `closed_flag = 0` is what the DB now reflects. The dashboard's `status=open` filter (`closed_flag = 0 AND canceled_flag = 0`) correctly applies this, so the call shows as active.

### Bug B — CAD source data inconsistency on post-close XMLs

Five calls in production right now (ids 113, 620, 699, 704, 753) share an identical fingerprint:

| Field (latest stored XML) | Value |
|---|---|
| Root `<CloseDateTime>` | populated |
| Root `<ClosedFlag>` | `false` |
| Root `<CanceledFlag>` | `false` |
| AgencyContext `<ClosedDateTime>` | populated |
| AgencyContext `<ClosedFlag>` | `false` |
| AgencyContext `<Status>` | "In Progress" / "Routine" |
| Assigned units `<ClearDateTime>` | nearly all populated (1/1, 3/3, 9/9, 1/1; one outlier 1/2) |
| Dispositions present | yes |

These calls fired a "Closed intent" earlier in the day, then received a much-later XML (e.g., 4 hours after close) where the CAD source set `<ClosedFlag>` back to `false` while keeping `<CloseDateTime>` populated. The pattern is unmistakable: every signal points to the call being closed *except* the `ClosedFlag` itself. This is a CAD source data quality issue, not a true reopen — but `Bug A`'s filename-timestamp ordering does **not** fix it because the late XML genuinely has a later filename timestamp.

### Why these surface together

The default dashboard view is `?preset=today&status=open` (`public/assets/js/dashboard-main.js:1195-1203`), which translates to `closed_flag = 0 AND canceled_flag = 0`. Both bugs land calls in `closed_flag = 0` despite the call being closed in operational reality, so both flow through this filter.

## Goals

- Closed calls do not appear on the default dashboard view, regardless of whether the cause is out-of-order XML arrival or CAD data inconsistency.
- Multi-agency calls (e.g., Fire closes Child Offense while Police continues on Welfare Check) continue to show as **open** until all agencies close. Confirmed correct behavior.
- Minimal blast radius: parser keeps faithfully recording what each XML says; filter changes alone shift the operational definition of "open".
- No backfill required. The 5 existing Bug B calls correct themselves on next dashboard load after the filter change ships.

## Non-goals

- Detecting and surfacing legitimate CAD reopens. The user noted reopens are rare and deferred this; revisit as a separate spec if needed.
- Changing how `closed_flag` is parsed or written. The column stays as the raw record of the latest XML's value.
- Schema changes to any of `database/mysql/init.sql`, `database/postgres/init.sql`, `database/schema.sql`. Both fixes are pure code changes.
- Modifying `IntentResolver` Closed-intent classification. Notifications keep their existing semantics.

## Design decisions (from brainstorming)

| Decision | Choice |
|---|---|
| Bug A approach | Filename-timestamp ordering: extract embedded timestamp from incoming filename, compare against `MAX(filename)` for the same call_number prefix in `processed_files`, skip if not strictly greater |
| Bug A storage | Use existing `processed_files.filename` — no schema change |
| Bug B approach | Filter rewrites `closed_flag` clauses to use `close_datetime IS NULL / IS NOT NULL` |
| Bug B layer | Filter only (`FilterSqlBuilder`). Parser stays untouched; `closed_flag` becomes a record-of-truth-as-CAD-said-it, not the authority for "open" |
| Multi-agency rule | Unchanged — open if any agency is open, surfaced via the root `<ClosedFlag>` and `<CloseDateTime>` semantics |
| Reopen detection | Out of scope for this spec — flagged as future work |

## Changes

### 1. `src/AegisXmlParser.php` — filename ordering check

In `processFile()`, **after** the file-already-processed short-circuit (line ~52) and **before** loading XML (line ~58), add a call-scoped staleness check:

```php
private function isFilenameStaleForCall(string $filename): bool
{
    // Filenames are {call_number}_{YYYYMMDDhhmmss<cs>}.xml
    if (! preg_match('/^(\d+)_\d+\.xml$/', $filename, $m)) {
        return false; // unknown shape — fall through to current behavior
    }
    $prefix = $m[1] . '_';
    $stmt = $this->db->prepare(
        "SELECT MAX(filename) FROM processed_files WHERE filename LIKE ?"
    );
    $stmt->execute([$prefix . '%']);
    $max = $stmt->fetchColumn();
    if ($max === false || $max === null || $max === '') {
        return false; // no prior file for this call_number
    }
    // Lexicographic comparison: timestamp portion is fixed-width and zero-padded
    return strcmp($filename, (string) $max) <= 0;
}
```

Wired into `processFile()`:

```php
if ($this->isFileProcessed($filename, $filePath)) {
    $this->logger->info("File already processed, skipping: {$filename}");
    return true;
}

// NEW: skip if a newer (or equal) XML for this same call has already been processed
if ($this->isFilenameStaleForCall($filename)) {
    $this->logger->info("Skipping stale XML (newer version already processed): {$filename}");
    $this->markFileAsProcessed($filename, $filePath, 0);
    return true;
}
```

Notes:
- `markFileAsProcessed(..., 0)` (zero records) is used so the file is recorded but doesn't inflate the success counter. The file then gets the same disposition as a successful skip — moved out of the watch folder by the watcher's existing post-process logic.
- `<=` (not `<`) — a same-filename comparison can only occur if the file was already in `processed_files`, which is excluded one branch above. Defensive.
- Falls through (`return false`) for any filename not matching the pattern, so manually-injected files or future schema changes don't break ingest.

### 2. `src/Api/Filtering/FilterSqlBuilder.php` — filter clauses

Replace the match arms at lines 152-163:

```php
// Status: each selected value becomes a parenthesised clause; multiple OR'd
if ($f->status !== []) {
    $statusClauses = [];
    foreach ($f->status as $s) {
        $statusClauses[] = match ($s) {
            'open'     => '(calls.close_datetime IS NULL AND calls.canceled_flag = 0)',
            'closed'   => '(calls.close_datetime IS NOT NULL AND calls.canceled_flag = 0)',
            'canceled' => '(calls.canceled_flag = 1)',
        };
    }
    $clauses[] = '(' . implode(' OR ', $statusClauses) . ')';
}
```

The `canceled` arm is unchanged. Only `open` and `closed` swap from `closed_flag` to `close_datetime`.

### 3. Tests

#### New unit test — `tests/Unit/Filtering/FilterSqlBuilderStatusTest.php`
- `status=open` → SQL contains `close_datetime IS NULL` and does **not** contain `closed_flag`.
- `status=closed` → SQL contains `close_datetime IS NOT NULL` and does **not** contain `closed_flag`.
- `status=canceled` → SQL contains `canceled_flag = 1`.
- `status=open,closed` → both clauses ORed.

#### New unit test — `tests/Unit/AegisXmlParserStaleFilenameTest.php`
- Insert a `processed_files` row with `163_2026050912203674.xml`, then attempt to process `163_2026050912203171.xml` → file is recorded as processed with 0 records and the call's `closed_flag` is **not** modified.
- Process `163_2026050912203700.xml` (newer than max) → proceeds normally.
- Process `unknown_format.xml` (doesn't match pattern) → falls through to existing logic (no skip).

#### New integration test — `tests/Integration/CallStatusCorrectnessTest.php`
- Ingest XML A (close, ClosedFlag=true, CloseDateTime set) for a fresh call_id → `close_datetime` populated.
- Ingest XML B with **older** filename timestamp and ClosedFlag=false for the same call_id → `close_datetime` retained, file recorded as skipped.
- Hit `/api/calls?status=open` → call does NOT appear.
- Hit `/api/calls?status=closed` → call DOES appear.
- Repeat without the staleness fix to confirm the test catches the regression (delete the staleness check in a branch and verify failure).

### 4. Coverage metadata

Per `phpunit.xml`'s strict coverage rules, the new test classes need:
- `@covers \NwsCad\Api\Filtering\FilterSqlBuilder` (status test)
- `@covers \NwsCad\AegisXmlParser` (stale-filename test)
- `@uses` for `Database`, `Config`, `Logger`, `Logging\RedactingProcessor`, `Logging\SecretRegistry`, `Api\Response`, `Api\Filtering\FilterCriteria`, `Api\Filtering\FilterContext`, `Api\Filtering\SqlFragment` as needed by the controllers/parsers exercised.

## Migration & rollout

- No schema migration. No data migration.
- The 5 existing Bug B calls correct themselves on first dashboard load after deploy — they have `close_datetime IS NOT NULL`, so the new "open" filter excludes them.
- Existing client URLs with `?status=open` keep working — filter values are unchanged at the API surface.

## Risk & mitigation

| Risk | Mitigation |
|---|---|
| A legitimate CAD reopen surfaces as still-closed under the new filter | Acknowledged in non-goals. User flagged this as rare and acceptable. Tracked as follow-up: detect reopens via unit-activity or transition tracking. |
| `processed_files` LIKE query becomes slow under volume | The query is bounded by `call_number` prefix (high selectivity) and runs once per file. Index on `processed_files.filename` already exists (PRIMARY KEY-equivalent). Re-evaluate if scan latency increases. |
| Filename pattern changes upstream | The regex has a defensive fallback — non-matching filenames bypass the staleness check entirely. No regression risk. |
| Test fixture drift between three schema files | Tests run against `database/schema.sql` in CI. Bug fixes don't touch schema, so this is N/A here, but adding columns later would require updates to all three (per CLAUDE.md). |

## Out of scope (future work)

- **Reopen detection.** A possible follow-up: when ingesting an XML for a call whose existing `close_datetime IS NOT NULL` and the incoming `<ClosedFlag>` is `true → false`, AND any unit's `<ClearDateTime>` is null in the new XML, mark the call as reopened (new column or status enum value). Surface in the UI as a distinct state. Defer until needed.
- **Cleaning up `closed_flag` semantics.** Could eventually drop the column or rename it `last_xml_closed_flag` for clarity. Pure cleanup; defer.
- **Multi-CallType display for multi-agency calls.** Call 682 displays a single CallType (whichever agency_context was inserted first) — could be misleading. Out of scope here; address in dashboard-design follow-up if confusion is reported.

## Acceptance criteria

- All existing tests pass.
- New unit + integration tests pass.
- On a clean DB, ingest the reverse-arrival XML pair for a single call → only the newer XML's state is reflected; no Bug A regression.
- After deploy, the 5 known Bug B calls (113, 620, 699, 704, 753) no longer appear on the default dashboard view.
- `composer test` passes with strict coverage.
