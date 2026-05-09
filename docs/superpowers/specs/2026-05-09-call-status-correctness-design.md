# Call Status Correctness — Closed Calls Showing as Active

**Date:** 2026-05-09
**Status:** Approved (brainstorming → writing-plans) — expanded 2026-05-09 to cover reopen detection and multi-agency display
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
- **Legitimately reopened calls (close + later activity) are surfaced as a distinct "Reopened" state** and included in the default open view so dispatchers can see them.
- **Multi-agency calls show all active agencies' call types**, not just the first one — eliminates the misleading "Child Offense (closed)" label when Police is still on Welfare Check.
- Multi-agency calls (e.g., Fire closes Child Offense while Police continues on Welfare Check) continue to show as **open** until all agencies close. Confirmed correct behavior.
- Parser keeps faithfully recording what each XML says for `closed_flag` and `close_datetime`; reopen state is derived once at ingest and stored in a new column.
- No backfill required for Bug A or Bug B. Existing data corrects itself on first dashboard load after deploy. Reopen detection requires one ALTER TABLE on each driver; existing rows default to `reopened_flag = 0` (correct, since none are currently flagged as reopened anyway).

## Non-goals

- Renaming `closed_flag` to something like `last_xml_closed_flag`. **Deferred** — pure cosmetic, high blast radius (parser, IntentResolver, API DTO, JS, all tests, and a migration). A code comment on the column declaration in the schema files clarifies its meaning instead.
- Modifying `IntentResolver` Closed-intent classification. Notifications keep their existing semantics — a Closed intent still fires only when `incoming.closed_flag === true`. Reopen detection is filter/UI-level, not notification-level.
- Per-unit reopen detection (e.g., highlight the specific reopened unit in the call card). The call-level signal is sufficient for the dashboard.
- A "reopen history" log. We track only the current state, not transitions over time.

## Design decisions (from brainstorming)

| Decision | Choice |
|---|---|
| Bug A approach | Filename-timestamp ordering: extract embedded timestamp from incoming filename, compare against `MAX(filename)` for the same call_number prefix in `processed_files`, skip if not strictly greater |
| Bug A storage | Use existing `processed_files.filename` — no schema change |
| Bug B approach | Filter rewrites `closed_flag` clauses to use `close_datetime IS NULL / IS NOT NULL` |
| Bug B layer | Filter only (`FilterSqlBuilder`). Parser stays untouched; `closed_flag` becomes a record-of-truth-as-CAD-said-it, not the authority for "open" |
| Multi-agency rule | Unchanged — open if any agency is open, surfaced via the root `<ClosedFlag>` and `<CloseDateTime>` semantics |
| Reopen detection signal | `assigned_units.assigned_datetime > calls.close_datetime AND assigned_units.clear_datetime IS NULL` for any unit in the incoming XML. Stored in a new `calls.reopened_flag` column, computed once at parse time. |
| Reopen filter integration | Default `status=open` includes reopened calls (`close_datetime IS NULL OR reopened_flag = 1`). New `status=reopened` filter value for drill-down. `status=closed` excludes reopened (`close_datetime IS NOT NULL AND reopened_flag = 0`). |
| Multi-CallType display | API already returns `call_types` as a deduped array (`CallsController::index` line 125). Dashboard JS currently renders `call.call_types?.[0]` only. Change: render all entries joined by " / " when more than one. No API change. |

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
            'open'     => '(calls.canceled_flag = 0 AND (calls.close_datetime IS NULL OR calls.reopened_flag = 1))',
            'closed'   => '(calls.canceled_flag = 0 AND calls.close_datetime IS NOT NULL AND calls.reopened_flag = 0)',
            'reopened' => '(calls.canceled_flag = 0 AND calls.reopened_flag = 1)',
            'canceled' => '(calls.canceled_flag = 1)',
        };
    }
    $clauses[] = '(' . implode(' OR ', $statusClauses) . ')';
}
```

`canceled` unchanged. `open` and `closed` swap from `closed_flag` to `close_datetime`. New `reopened` arm.

Also update `FilterCriteria.php`:
- Line 12: `private const VALID_STATUSES = ['open', 'closed', 'canceled', 'reopened'];`
- The validation loop already handles arbitrary additions via `in_array`.

### 3. Schema — add `calls.reopened_flag` to all three schema files

```sql
-- Append to calls table column list in:
-- database/mysql/init.sql
-- database/postgres/init.sql
-- database/schema.sql
reopened_flag BOOLEAN DEFAULT FALSE COMMENT 'Set to 1 when a closed call receives an XML with new unit activity after the close timestamp. Distinguishes legitimate reopens from CAD-source ClosedFlag inconsistency.',
```

Postgres syntax variant: `reopened_flag BOOLEAN DEFAULT FALSE` (no `COMMENT` inline; can add via `COMMENT ON COLUMN` in a follow-up). For consistency with the rest of the postgres schema, use the column-only form there.

For existing deployed databases, add a migration note:
```sql
ALTER TABLE calls ADD COLUMN reopened_flag BOOLEAN DEFAULT FALSE;
```
Documented in the implementation plan; no auto-migration framework exists in this repo.

### 4. `src/AegisXmlParser.php` — reopen detection at ingest

Extend `snapshotExisting()` (line 1170) to include `close_datetime`:
```php
'close_datetime' => $scalar("SELECT close_datetime FROM calls WHERE id = ?"),
```

In `processFile()`, after `snapshotExisting` and before `insertCall`, compute the reopen flag. Pass it into a new helper `detectReopen($xml, $existingSnapshot)`:

```php
private function detectReopen(SimpleXMLElement $xml, ?array $existingSnapshot): bool
{
    if ($existingSnapshot === null) return false;
    $existingClose = $existingSnapshot['close_datetime'] ?? '';
    if ($existingClose === '') return false; // never closed → not a reopen
    
    foreach ($xml->AssignedUnits->Unit ?? [] as $u) {
        $clear = trim((string) $u->ClearDateTime);
        $assigned = trim((string) $u->AssignedDateTime);
        if ($clear === '' && $assigned !== '' && $assigned > $existingClose) {
            return true;
        }
    }
    return false;
}
```

Wire reopen state into `insertCall` so it's written alongside other fields. The rule for `reopened_flag` is:

- If the incoming XML's `closed_flag` is `1` → set `reopened_flag = 0` (a fresh close trumps any prior reopen).
- Else if `detectReopen` returned `true` → set `reopened_flag = 1`.
- Else → preserve the existing value (don't overwrite). For new calls (INSERT path), the column defaults to `0`.

In the UPDATE statement, conditional logic via SQL:
```sql
reopened_flag = CASE
    WHEN :incoming_closed = 1 THEN 0
    WHEN :detected_reopen = 1 THEN 1
    ELSE reopened_flag
END,
```

Bind two new params: `:incoming_closed` (the parsed root ClosedFlag, 0 or 1) and `:detected_reopen` (output of `detectReopen`, 0 or 1). For the INSERT path, bind `reopened_flag = :detected_reopen` directly (existing column default handles fresh-call case).

### 5. Multi-CallType display in dashboard

In `public/assets/js/dashboard-main.js`:
- **Line 316** (desktop calls table): change `call.call_types?.[0] || call.nature_of_call || 'Unknown'` to a helper `formatCallTypes(call)` that joins all entries with ` / `.
- **Line 1356** (mobile/detail view): same change.

```js
// Add near top of the file (or in dashboard.js if shared)
Dashboard.formatCallTypes = function (call) {
    const types = call.call_types || [];
    if (types.length === 0) return call.nature_of_call || 'Unknown';
    return types.join(' / ');
};
```

`Dashboard.escapeHtml(types.join(' / '))` is safe because each `call_types[i]` is a CAD-source string already validated against the database column length cap; the join character is a literal slash.

This is a frontend-only change. The API already returns `call_types` as a deduped array (`CallsController::index` line 125 + `attachRelatedData` line 551-563), so no backend change is needed.

### 6. Tests

#### New unit test — `tests/Unit/Filtering/FilterSqlBuilderStatusTest.php`
- `status=open` → SQL contains `close_datetime IS NULL` AND `reopened_flag = 1` (in OR), does **not** mention `closed_flag` (the legacy column).
- `status=closed` → SQL contains `close_datetime IS NOT NULL` AND `reopened_flag = 0`.
- `status=reopened` → SQL contains `reopened_flag = 1` AND `canceled_flag = 0`.
- `status=canceled` → SQL contains `canceled_flag = 1`.
- `status=open,closed` → both clauses ORed.
- `status=reopened` plus `status=invalid` → throws `InvalidFilterException`.

#### New unit test — `tests/Unit/AegisXmlParserStaleFilenameTest.php`
- Insert a `processed_files` row with `163_2026050912203674.xml`, then attempt to process `163_2026050912203171.xml` → file is recorded as processed with 0 records and the call's `closed_flag` / `close_datetime` are **not** modified.
- Process `163_2026050912203700.xml` (newer than max) → proceeds normally.
- Process `unknown_format.xml` (doesn't match pattern) → falls through to existing logic (no skip).

#### New unit test — `tests/Unit/AegisXmlParserReopenDetectionTest.php`
- Existing call with `close_datetime = '2026-05-09 12:00:00'` and one cleared unit; ingest XML with `<AssignedDateTime>2026-05-09T13:00:00Z</AssignedDateTime>` and empty `<ClearDateTime>` → `reopened_flag = 1`.
- Same setup but XML's `<AssignedDateTime>` is BEFORE the close → `reopened_flag` unchanged from prior value.
- Same setup but the new XML has `<ClosedFlag>true</ClosedFlag>` → `reopened_flag = 0` regardless of unit timestamps.
- New call (no existing snapshot) → `reopened_flag = 0`.
- Existing call never closed (close_datetime IS NULL) → `reopened_flag = 0` even with active units.

#### New integration test — `tests/Integration/CallStatusCorrectnessTest.php`
- **Bug A scenario:** Ingest XML A (close, ClosedFlag=true, CloseDateTime set, filename ts T+5) for a fresh call_id → `close_datetime` populated. Ingest XML B with **older** filename timestamp T+0 and ClosedFlag=false → file recorded as skipped, `close_datetime` retained.
- Hit `/api/calls?status=open` → call does NOT appear. Hit `/api/calls?status=closed` → call DOES appear.
- **Bug B scenario:** Ingest a closed call, then a later XML with all CAD inconsistency markers (CloseDateTime set, ClosedFlag=false, all units cleared, no new assignments) → `close_datetime` populated, `reopened_flag = 0` → call shows under `status=closed`, NOT under `status=open`.
- **Reopen scenario:** Ingest a closed call, then a later XML with a NEW unit (`AssignedDateTime > close_datetime`, no ClearDateTime) → `reopened_flag = 1` → call shows under both `status=open` and `status=reopened`, NOT under `status=closed`.

#### New JS test — `tests/Unit/Frontend/formatCallTypesTest.js` (or extend existing dashboard tests)
If the project has JS test infrastructure: assert `formatCallTypes({call_types: ['Theft']})` returns `'Theft'`, `formatCallTypes({call_types: ['Child Offense', 'Welfare Check']})` returns `'Child Offense / Welfare Check'`, `formatCallTypes({call_types: [], nature_of_call: 'Suspicious'})` returns `'Suspicious'`. **If no JS test infrastructure exists, document this manually verified in the PR.**

### 7. Coverage metadata

Per `phpunit.xml`'s strict coverage rules, the new test classes need:
- `@covers \NwsCad\Api\Filtering\FilterSqlBuilder` (status test)
- `@covers \NwsCad\AegisXmlParser` (stale-filename + reopen-detection tests)
- `@uses` for `Database`, `Config`, `Logger`, `Logging\RedactingProcessor`, `Logging\SecretRegistry`, `Api\Response`, `Api\Filtering\FilterCriteria`, `Api\Filtering\FilterContext`, `Api\Filtering\SqlFragment`, `Notifications\IntentResolver`, `Notifications\Events\CallProcessedEvent`, `Notifications\Events\Intent`, `Notifications\EventDispatcher` as needed by the controllers/parsers exercised.

## Migration & rollout

- **Schema migration:** one ALTER per database (`ALTER TABLE calls ADD COLUMN reopened_flag BOOLEAN DEFAULT FALSE;`). Documented in the implementation plan; no auto-migration framework exists in this repo. Operator runs it once at deploy.
- No data backfill required:
  - The 5 existing Bug B calls (113, 620, 699, 704, 753) correct themselves under the new filter (`close_datetime IS NOT NULL AND reopened_flag = 0` → `status=closed`).
  - All existing rows default to `reopened_flag = 0`. None of the 5 Bug B calls qualify as reopens (none have units assigned after their close), so `0` is the correct initial value for them too.
- Existing client URLs with `?status=open` and `?status=closed` keep working with corrected semantics. New value `?status=reopened` is opt-in.
- Multi-CallType display change is a frontend-only deploy — no API or schema dependency.

## Risk & mitigation

| Risk | Mitigation |
|---|---|
| Reopen detection produces false positives (CAD sends a duplicate-with-old-data XML containing a stale `AssignedDateTime > close_datetime`) | The signal also requires `clear_datetime IS NULL` in the same XML. CAD-source duplicate XMLs we've seen carry the original cleared timestamps. If this becomes noisy, tighten by also requiring the unit number to be new or by adding a minimum delta between assigned and close. Monitor `reopened_flag = 1` count after deploy. |
| `processed_files` LIKE query becomes slow under volume | Bounded by `call_number` prefix (high selectivity) and runs once per file. Index on `processed_files.filename` already exists (PRIMARY KEY-equivalent). Re-evaluate if scan latency increases. |
| Filename pattern changes upstream | The regex has a defensive fallback — non-matching filenames bypass the staleness check entirely. No regression risk. |
| ALTER TABLE on a large `calls` table locks production reads (MySQL) | `ADD COLUMN ... DEFAULT FALSE` on MySQL 8 uses INSTANT ADD by default for nullable/default columns — sub-second on tables of any size. Postgres also supports fast metadata-only adds since v11. Document the expected duration as <1s in the rollout step. |
| Filter complexity grows (4 status values now, with overlapping semantics) | Status definitions live in one place (FilterSqlBuilder match arms) and are unit-tested. The match expression is exhaustive — adding a 5th value would be a compile-time error in PHP 8.x. |
| Multi-CallType strings overflow narrow table cells | Existing CSS for the type column should handle text wrap or truncate. Verify in browser; add `text-overflow: ellipsis` if needed. |

## Out of scope (future work)

- **Renaming `closed_flag`.** Pure cosmetic, big blast radius (parser, IntentResolver, API DTO, JS, all tests, migration). A column comment in the schema files clarifies meaning instead. Revisit if confusion persists.
- **Per-agency status badges in the dashboard.** "Fire (closed) / Police (active): Child Offense / Welfare Check" is a richer treatment than the joined string. Defer until requested.
- **Reopen-history audit log.** Currently we track only the current `reopened_flag` state, not transitions. Add an `audit_log` table if regulatory or operational review needs it.
- **Notification dispatch on reopen.** The IntentResolver still classifies these as `Updated` (since `closed_flag === true` is the only Closed-trigger). A future improvement: add an `Intent::Reopened` value that fans out to topics on transition. Not urgent — most reopens are local (police re-investigating), not multi-agency.

## Acceptance criteria

- All existing tests pass under `composer test` with strict coverage.
- New unit + integration + frontend tests pass.
- On a clean DB, ingest the reverse-arrival XML pair for a single call → only the newer XML's state is reflected; no Bug A regression.
- After deploy, the 5 known Bug B calls (113, 620, 699, 704, 753) no longer appear on the default dashboard view (they correctly land under `status=closed`).
- After deploy, a synthetic reopen scenario (close + later unit assignment) correctly lands the call under both `status=open` and `status=reopened`, NOT under `status=closed`.
- Call 682 (multi-agency) displays both "Child Offense" and "Welfare Check" in its row.
- `php -l src/AegisXmlParser.php` and the rest of the touched PHP files pass syntax checks.
- ALTER TABLE migration runs in <1s on production-sized data on both MySQL 8 and Postgres 16.
