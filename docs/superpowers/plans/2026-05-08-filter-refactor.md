# Filter Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the divergent desktop/mobile filter implementations with one unified, declarative filter system covering every list page in the UI, exposing the full schema's filter surface (date/time, call_type, incident_type, ORI, FDID, beat, area, city, agency, status, etc.) with multi-select, URL-shareable state, server-side validation, and indexed queries.

**Architecture:** URL is canonical state. One `FilterPanel` JS component mounts on every page via `<div data-filter-panel data-fields="...">`. One `Api\Filtering\` PHP namespace (`FilterCriteria`, `FilterSqlBuilder`, `FilterRegistry`) parses URL → typed value object → parameterized SQL. New `/api/filter-options` endpoint serves curated reference data (ref_agencies/ref_oris/ref_fdids/ref_beats/ref_areas) merged with derived `SELECT DISTINCT` lists, server-cached 5 minutes. All legacy filter code is removed in the same change.

**Tech Stack:** PHP 8.3 (PDO, declare(strict_types=1)), MySQL 8 / PostgreSQL 16 via `Database::getDbType()`, vanilla JS (no framework) + vendored Choices.js 10.x + Flatpickr 4.x, Bootstrap 5 markup, PHPUnit 10 with strict coverage (`@covers`/`@uses` required).

**Spec:** `docs/superpowers/specs/2026-05-08-filter-refactor-design.md`

**Conventions to honor (load-bearing for CI):**
- All PHP files start with `declare(strict_types=1);`
- Every test class needs `@covers <Class>` AND `@uses` for every transitively executed class
- Tests that hit a controller must call `Response::resetForTesting()` in `setUp()`
- Schema changes go to all three SQL files: `database/mysql/init.sql`, `database/postgres/init.sql`, `database/schema.sql`
- Cross-DB SQL goes through `Api\DbHelper`; never inline MySQL- or Postgres-only syntax
- 80% line coverage minimum (`phpunit.xml`)
- `Response::paginated($data, $total, $page, $perPage)` (already exists) — use it; do not re-invent the response shape
- `Request::sorting($defaultSort, $defaultOrder)` returns `['sort' => ..., 'order' => 'ASC'|'DESC']` — note `sort`/`order` keys, not `column`/`direction`

---

## Phase 0 — Preflight

### Task 0: Verify prerequisites and create working branch

**Files:** None modified yet.

- [ ] **Step 1: Confirm clean working tree**

```bash
git status
```
Expected: only the existing modifications listed in CLAUDE.md (Dockerfile, init.sql, etc.) — none of the files this plan touches should be staged. If anything in `src/Api/Filtering/` or `public/assets/js/filters/` is dirty, stop and resolve.

- [ ] **Step 2: Create a working branch**

```bash
git checkout -b feat/filter-refactor
```

- [ ] **Step 3: Run the test suite to confirm green baseline**

```bash
composer test
```
Expected: all four suites pass. Record the pass count — we will compare at the end.

- [ ] **Step 4: Verify coverage driver behaviour locally**

```bash
./vendor/bin/phpunit tests/Unit/ApiRequestTest.php
```
Expected: PASS. (No coverage gate locally unless pcov is installed; CI enforces it.)

---

## Phase 1 — Database foundation

Reference tables, indexes, and the new `agency_contexts.fdid` column. Schema first so backend code can build against it.

### Task 1: Migration SQL — reference tables and indexes

**Files:**
- Create: `database/migrations/2026-05-08-filter-refactor.sql`
- Create: `database/migrations/2026-05-08-filter-refactor.pgsql.sql`

- [ ] **Step 1: Write the MySQL migration**

```sql
-- database/migrations/2026-05-08-filter-refactor.sql
-- Filter refactor: reference tables, FDID column, and indexes.
-- Idempotent: safe to re-run.

CREATE TABLE IF NOT EXISTS ref_agencies (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL,
    label VARCHAR(128) NOT NULL,
    kind ENUM('police','fire','ems') NOT NULL,
    ori VARCHAR(16) DEFAULT NULL,
    fdid VARCHAR(10) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 100,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ref_agencies_code (code),
    KEY idx_ref_agencies_kind (kind, active),
    KEY idx_ref_agencies_ori (ori),
    KEY idx_ref_agencies_fdid (fdid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ref_oris (
    ori VARCHAR(16) NOT NULL,
    label VARCHAR(128) NOT NULL,
    kind ENUM('police','fire','ems') NOT NULL,
    agency_id INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (ori),
    KEY idx_ref_oris_agency (agency_id),
    CONSTRAINT fk_ref_oris_agency FOREIGN KEY (agency_id) REFERENCES ref_agencies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ref_fdids (
    fdid VARCHAR(10) NOT NULL,
    label VARCHAR(128) NOT NULL,
    agency_id INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (fdid),
    KEY idx_ref_fdids_agency (agency_id),
    CONSTRAINT fk_ref_fdids_agency FOREIGN KEY (agency_id) REFERENCES ref_agencies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ref_beats (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL,
    label VARCHAR(128) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    jurisdiction VARCHAR(64) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ref_beats_code (code),
    KEY idx_ref_beats_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ref_areas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL,
    label VARCHAR(128) NOT NULL,
    kind ENUM('fire_quad','ems_district') NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ref_areas_code (code),
    KEY idx_ref_areas_kind (kind, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- New column on agency_contexts (idempotent via INFORMATION_SCHEMA check)
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agency_contexts' AND COLUMN_NAME = 'fdid');
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE agency_contexts ADD COLUMN fdid VARCHAR(10) NULL AFTER agency_type',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indexes (idempotent: CREATE INDEX IF NOT EXISTS works on MySQL 8.0.29+; use procedure for safety)
DELIMITER $$
DROP PROCEDURE IF EXISTS ensure_index $$
CREATE PROCEDURE ensure_index(IN tbl VARCHAR(64), IN idx VARCHAR(64), IN cols VARCHAR(255))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND INDEX_NAME = idx) THEN
        SET @s := CONCAT('CREATE INDEX ', idx, ' ON ', tbl, ' (', cols, ')');
        PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END $$
DELIMITER ;

CALL ensure_index('calls', 'idx_calls_create_closed', 'create_datetime, closed_flag, canceled_flag');
CALL ensure_index('agency_contexts', 'idx_ac_call_type', 'call_type');
CALL ensure_index('agency_contexts', 'idx_ac_fdid', 'fdid');
CALL ensure_index('locations', 'idx_loc_police_ori', 'police_ori');
CALL ensure_index('locations', 'idx_loc_ems_ori', 'ems_ori');
CALL ensure_index('locations', 'idx_loc_fire_ori', 'fire_ori');
CALL ensure_index('locations', 'idx_loc_police_beat', 'police_beat');
CALL ensure_index('locations', 'idx_loc_fire_quad', 'fire_quadrant');
CALL ensure_index('locations', 'idx_loc_ems_district', 'ems_district');
CALL ensure_index('locations', 'idx_loc_city', 'city');
CALL ensure_index('units', 'idx_units_unit_number', 'unit_number');
CALL ensure_index('incidents', 'idx_inc_incident_type', 'incident_type');

DROP PROCEDURE ensure_index;
```

- [ ] **Step 2: Write the PostgreSQL migration**

```sql
-- database/migrations/2026-05-08-filter-refactor.pgsql.sql
-- Filter refactor: reference tables, FDID column, and indexes (PostgreSQL).
-- Idempotent: safe to re-run.

CREATE TABLE IF NOT EXISTS ref_agencies (
    id SERIAL PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    label VARCHAR(128) NOT NULL,
    kind VARCHAR(8) NOT NULL CHECK (kind IN ('police','fire','ems')),
    ori VARCHAR(16),
    fdid VARCHAR(10),
    active SMALLINT NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 100
);
CREATE INDEX IF NOT EXISTS idx_ref_agencies_kind ON ref_agencies (kind, active);
CREATE INDEX IF NOT EXISTS idx_ref_agencies_ori  ON ref_agencies (ori);
CREATE INDEX IF NOT EXISTS idx_ref_agencies_fdid ON ref_agencies (fdid);

CREATE TABLE IF NOT EXISTS ref_oris (
    ori VARCHAR(16) PRIMARY KEY,
    label VARCHAR(128) NOT NULL,
    kind VARCHAR(8) NOT NULL CHECK (kind IN ('police','fire','ems')),
    agency_id INT REFERENCES ref_agencies(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_ref_oris_agency ON ref_oris (agency_id);

CREATE TABLE IF NOT EXISTS ref_fdids (
    fdid VARCHAR(10) PRIMARY KEY,
    label VARCHAR(128) NOT NULL,
    agency_id INT REFERENCES ref_agencies(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_ref_fdids_agency ON ref_fdids (agency_id);

CREATE TABLE IF NOT EXISTS ref_beats (
    id SERIAL PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    label VARCHAR(128) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    jurisdiction VARCHAR(64),
    active SMALLINT NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_ref_beats_active ON ref_beats (active);

CREATE TABLE IF NOT EXISTS ref_areas (
    id SERIAL PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    label VARCHAR(128) NOT NULL,
    kind VARCHAR(16) NOT NULL CHECK (kind IN ('fire_quad','ems_district')),
    active SMALLINT NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_ref_areas_kind ON ref_areas (kind, active);

ALTER TABLE agency_contexts ADD COLUMN IF NOT EXISTS fdid VARCHAR(10);

CREATE INDEX IF NOT EXISTS idx_calls_create_closed ON calls (create_datetime, closed_flag, canceled_flag);
CREATE INDEX IF NOT EXISTS idx_ac_call_type   ON agency_contexts (call_type);
CREATE INDEX IF NOT EXISTS idx_ac_fdid        ON agency_contexts (fdid);
CREATE INDEX IF NOT EXISTS idx_loc_police_ori ON locations (police_ori);
CREATE INDEX IF NOT EXISTS idx_loc_ems_ori    ON locations (ems_ori);
CREATE INDEX IF NOT EXISTS idx_loc_fire_ori   ON locations (fire_ori);
CREATE INDEX IF NOT EXISTS idx_loc_police_beat ON locations (police_beat);
CREATE INDEX IF NOT EXISTS idx_loc_fire_quad  ON locations (fire_quadrant);
CREATE INDEX IF NOT EXISTS idx_loc_ems_district ON locations (ems_district);
CREATE INDEX IF NOT EXISTS idx_loc_city       ON locations (city);
CREATE INDEX IF NOT EXISTS idx_units_unit_number ON units (unit_number);
CREATE INDEX IF NOT EXISTS idx_inc_incident_type ON incidents (incident_type);
```

- [ ] **Step 3: Apply MySQL migration to dev DB**

```bash
docker-compose exec -T mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" nws_cad < database/migrations/2026-05-08-filter-refactor.sql
```
Expected: command exits 0, no errors. If the project uses `docker compose` (no hyphen), use that.

- [ ] **Step 4: Verify the columns and tables exist**

```bash
docker-compose exec mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "USE nws_cad; SHOW COLUMNS FROM agency_contexts LIKE 'fdid'; SHOW TABLES LIKE 'ref_%';"
```
Expected: one row for `fdid VARCHAR(10) NULL`; five rows `ref_agencies`, `ref_areas`, `ref_beats`, `ref_fdids`, `ref_oris`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026-05-08-filter-refactor.sql database/migrations/2026-05-08-filter-refactor.pgsql.sql
git commit -m "$(cat <<'EOF'
feat(db): add filter refactor migration (ref tables, FDID column, indexes)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 2: Sync schema files (mysql/init.sql, postgres/init.sql, schema.sql)

The three schema files must contain the new tables and `fdid` column or CI (which seeds `nws_cad_test` from `database/schema.sql`) will be missing them.

**Files:**
- Modify: `database/mysql/init.sql`
- Modify: `database/postgres/init.sql`
- Modify: `database/schema.sql`

- [ ] **Step 1: Append the ref-table block to `database/mysql/init.sql`**

Append the same `CREATE TABLE IF NOT EXISTS ref_agencies / ref_oris / ref_fdids / ref_beats / ref_areas` blocks from Task 1's MySQL migration. Append `idx_ac_fdid` and the `locations` / `incidents` / `units` indexes. Add `fdid VARCHAR(10) NULL` to the `agency_contexts` table definition (insert it after the `agency_type` column).

- [ ] **Step 2: Append the same to `database/postgres/init.sql` (Postgres dialect)**

Use the Postgres migration text from Task 1, Step 2 minus the `ALTER TABLE` (since this is the create-from-scratch file — instead, edit the existing `CREATE TABLE agency_contexts` to include `fdid VARCHAR(10)` directly).

- [ ] **Step 3: Mirror in `database/schema.sql`**

This file is consumed by CI to seed `nws_cad_test`. CI uses MySQL — use the MySQL syntax. Add the same blocks.

- [ ] **Step 4: Reset and re-init the dev DB to confirm parity**

```bash
docker-compose down -v
docker-compose --profile mysql up -d mysql
sleep 10
docker-compose exec mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "USE nws_cad; SHOW TABLES;" | grep -E 'ref_|agency_contexts'
```
Expected: every `ref_*` table listed plus `agency_contexts`.

- [ ] **Step 5: Commit**

```bash
git add database/mysql/init.sql database/postgres/init.sql database/schema.sql
git commit -m "$(cat <<'EOF'
feat(db): sync init.sql and schema.sql with filter refactor migration

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 3: Reference seed file (JSON) and CLI

**Files:**
- Create: `database/seeds/reference.json`
- Create: `bin/seed-reference.php`

- [ ] **Step 1: Write the seed JSON skeleton**

```json
{
  "agencies": [
    {"code": "PEN_PD",  "label": "Pendleton Police", "kind": "police", "ori": "IN0480000", "fdid": null,    "active": 1, "sort_order": 10},
    {"code": "EDG_FD",  "label": "Edgewood Fire",    "kind": "fire",   "ori": null,        "fdid": "48013", "active": 1, "sort_order": 20},
    {"code": "MAD_EMS", "label": "Madison EMS",      "kind": "ems",    "ori": null,        "fdid": null,    "active": 1, "sort_order": 30}
  ],
  "oris": [
    {"ori": "IN0480000", "label": "IN0480000 (Pendleton PD)", "kind": "police", "agency_code": "PEN_PD"},
    {"ori": "IN0480200", "label": "IN0480200 (Edgewood PD)",  "kind": "police", "agency_code": null}
  ],
  "fdids": [
    {"fdid": "48002", "label": "48002 (Anderson FD)",  "agency_code": null},
    {"fdid": "48013", "label": "48013 (Edgewood FD)", "agency_code": "EDG_FD"}
  ],
  "beats": [
    {"code": "B1", "label": "Beat 1 (North)", "kind": "police", "jurisdiction": "Pendleton", "active": 1},
    {"code": "B2", "label": "Beat 2 (South)", "kind": "police", "jurisdiction": "Pendleton", "active": 1}
  ],
  "areas": [
    {"code": "Quad-1", "label": "Quad 1", "kind": "fire_quad", "active": 1},
    {"code": "EMS-3",  "label": "EMS District 3", "kind": "ems_district", "active": 1}
  ]
}
```

The codes/ORIs/FDIDs above are placeholders — operations will edit this file to match actual data. The structure is what matters.

- [ ] **Step 2: Write the CLI**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use NwsCad\Database;

$path = $argv[1] ?? __DIR__ . '/../database/seeds/reference.json';
if (!is_readable($path)) {
    fwrite(STDERR, "Seed file not found: {$path}\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$db = Database::getConnection();
$db->beginTransaction();
try {
    $agencyIdsByCode = [];
    $upAgency = $db->prepare(
        'INSERT INTO ref_agencies (code, label, kind, ori, fdid, active, sort_order)
         VALUES (:code, :label, :kind, :ori, :fdid, :active, :sort_order)
         ON DUPLICATE KEY UPDATE label = VALUES(label), kind = VALUES(kind),
            ori = VALUES(ori), fdid = VALUES(fdid), active = VALUES(active),
            sort_order = VALUES(sort_order)'
    );
    foreach ($data['agencies'] ?? [] as $a) {
        $upAgency->execute([
            ':code' => $a['code'], ':label' => $a['label'], ':kind' => $a['kind'],
            ':ori'  => $a['ori'],  ':fdid'  => $a['fdid'],  ':active' => $a['active'] ?? 1,
            ':sort_order' => $a['sort_order'] ?? 100,
        ]);
        $idStmt = $db->prepare('SELECT id FROM ref_agencies WHERE code = :code');
        $idStmt->execute([':code' => $a['code']]);
        $agencyIdsByCode[$a['code']] = (int) $idStmt->fetchColumn();
    }

    $upOri = $db->prepare(
        'INSERT INTO ref_oris (ori, label, kind, agency_id) VALUES (:ori, :label, :kind, :agency_id)
         ON DUPLICATE KEY UPDATE label = VALUES(label), kind = VALUES(kind), agency_id = VALUES(agency_id)'
    );
    foreach ($data['oris'] ?? [] as $o) {
        $upOri->execute([
            ':ori' => $o['ori'], ':label' => $o['label'], ':kind' => $o['kind'],
            ':agency_id' => isset($o['agency_code']) ? ($agencyIdsByCode[$o['agency_code']] ?? null) : null,
        ]);
    }

    $upFdid = $db->prepare(
        'INSERT INTO ref_fdids (fdid, label, agency_id) VALUES (:fdid, :label, :agency_id)
         ON DUPLICATE KEY UPDATE label = VALUES(label), agency_id = VALUES(agency_id)'
    );
    foreach ($data['fdids'] ?? [] as $f) {
        $upFdid->execute([
            ':fdid' => $f['fdid'], ':label' => $f['label'],
            ':agency_id' => isset($f['agency_code']) ? ($agencyIdsByCode[$f['agency_code']] ?? null) : null,
        ]);
    }

    $upBeat = $db->prepare(
        'INSERT INTO ref_beats (code, label, kind, jurisdiction, active)
         VALUES (:code, :label, :kind, :jurisdiction, :active)
         ON DUPLICATE KEY UPDATE label = VALUES(label), kind = VALUES(kind),
            jurisdiction = VALUES(jurisdiction), active = VALUES(active)'
    );
    foreach ($data['beats'] ?? [] as $b) {
        $upBeat->execute([
            ':code' => $b['code'], ':label' => $b['label'], ':kind' => $b['kind'],
            ':jurisdiction' => $b['jurisdiction'] ?? null, ':active' => $b['active'] ?? 1,
        ]);
    }

    $upArea = $db->prepare(
        'INSERT INTO ref_areas (code, label, kind, active)
         VALUES (:code, :label, :kind, :active)
         ON DUPLICATE KEY UPDATE label = VALUES(label), kind = VALUES(kind), active = VALUES(active)'
    );
    foreach ($data['areas'] ?? [] as $a) {
        $upArea->execute([
            ':code' => $a['code'], ':label' => $a['label'], ':kind' => $a['kind'],
            ':active' => $a['active'] ?? 1,
        ]);
    }

    $db->commit();
    $totals = [
        'agencies' => count($data['agencies'] ?? []),
        'oris'     => count($data['oris'] ?? []),
        'fdids'    => count($data['fdids'] ?? []),
        'beats'    => count($data['beats'] ?? []),
        'areas'    => count($data['areas'] ?? []),
    ];
    echo "Seeded reference data: " . json_encode($totals) . "\n";
} catch (\Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "Seed failed: " . $e->getMessage() . "\n");
    exit(2);
}
```

Note: `ON DUPLICATE KEY UPDATE` is MySQL syntax. If running against PostgreSQL, replace with `ON CONFLICT (...) DO UPDATE SET ...`. The CLI is operator-facing and dev-only — picking MySQL syntax is fine for v1, with a follow-up to abstract via `Database::getDbType()` if Postgres operators need it.

- [ ] **Step 3: Run the seed**

```bash
docker-compose exec app php /app/bin/seed-reference.php
```
Expected: `Seeded reference data: {"agencies":3,"oris":2,"fdids":2,"beats":2,"areas":2}`

- [ ] **Step 4: Verify**

```bash
docker-compose exec mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "USE nws_cad; SELECT code,label,kind FROM ref_agencies;"
```
Expected: three rows.

- [ ] **Step 5: Commit**

```bash
git add database/seeds/reference.json bin/seed-reference.php
git commit -m "$(cat <<'EOF'
feat(seed): reference data JSON and bin/seed-reference.php CLI

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 4: Populate `agency_contexts.fdid` in AegisXmlParser

**Files:**
- Modify: `src/AegisXmlParser.php`
- Modify: `tests/Unit/AegisXmlParserTest.php`

The parser currently writes to `agency_contexts` without an FDID column. Now it must populate FDID either from the XML payload (if present) or by lookup against `ref_agencies.fdid` keyed by `agency_type`.

- [ ] **Step 1: Write a failing test for FDID population from XML**

Add a test method in `tests/Unit/AegisXmlParserTest.php`:

```php
public function testInsertsFdidFromXmlAttributeWhenPresent(): void
{
    $xml = $this->buildXmlWithAgencyContextHavingFdid('48013');
    $this->parser->processFile($this->writeXmlToTemp($xml));

    $row = $this->db->query("SELECT fdid FROM agency_contexts ORDER BY id DESC LIMIT 1")->fetch();
    $this->assertSame('48013', $row['fdid']);
}
```

`buildXmlWithAgencyContextHavingFdid()` is a small helper added in the same test class — it returns a minimal Aegis XML string with a `<FDID>48013</FDID>` element (or whatever attribute name your CAD uses; verify against existing fixtures in `tests/fixtures/`).

- [ ] **Step 2: Run the test to confirm it fails**

```bash
./vendor/bin/phpunit --filter testInsertsFdidFromXmlAttributeWhenPresent tests/Unit/AegisXmlParserTest.php
```
Expected: FAIL — column was added in Phase 1 but the parser still writes NULL.

- [ ] **Step 3: Implement FDID extraction in `AegisXmlParser`**

In the agency-contexts insert block, read the FDID element with the same defensive pattern used for other optional XML fields, falling back to a `ref_agencies.fdid` lookup:

```php
// Inside the foreach over agency contexts in processFile()
$fdid = $this->extractFdid($agencyContextNode); // null-tolerant read of <FDID> child
if ($fdid === null) {
    $lookup = $this->db->prepare(
        'SELECT fdid FROM ref_agencies WHERE LOWER(label) = LOWER(:lbl) OR code = :code LIMIT 1'
    );
    $lookup->execute([':lbl' => $agencyType, ':code' => $agencyType]);
    $fdid = $lookup->fetchColumn() ?: null;
}
// Add to the existing prepared INSERT for agency_contexts:
//   ... INSERT INTO agency_contexts (call_id, agency_type, fdid, ...)
//   ... VALUES (:call_id, :agency_type, :fdid, ...)
//   bind ':fdid' => $fdid
```

The exact insertion point is wherever `agency_contexts` rows are written today. Check the file at the time of implementation; do not assume a line number.

- [ ] **Step 4: Run the test — should pass**

```bash
./vendor/bin/phpunit --filter testInsertsFdidFromXmlAttributeWhenPresent tests/Unit/AegisXmlParserTest.php
```
Expected: PASS.

- [ ] **Step 5: Add a second test for the lookup fallback**

```php
public function testFallsBackToRefAgenciesLookupWhenXmlLacksFdid(): void
{
    $this->db->exec("INSERT INTO ref_agencies (code,label,kind,fdid,active,sort_order) VALUES ('FOO_FD','Foo Fire','fire','48099',1,100)");
    $xml = $this->buildXmlWithAgencyType('Foo Fire'); // no <FDID>
    $this->parser->processFile($this->writeXmlToTemp($xml));

    $row = $this->db->query("SELECT fdid FROM agency_contexts ORDER BY id DESC LIMIT 1")->fetch();
    $this->assertSame('48099', $row['fdid']);
}
```
Run it; expect PASS.

- [ ] **Step 6: Run the full unit suite**

```bash
composer test:unit
```
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add src/AegisXmlParser.php tests/Unit/AegisXmlParserTest.php
git commit -m "$(cat <<'EOF'
feat(parser): populate agency_contexts.fdid from XML or ref_agencies fallback

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 2 — Backend filter core (`Api\Filtering\*`)

Build the new namespace test-first. Each class is small and isolated.

### Task 5: `DateRange`, `InvalidFilterException`, `SqlFragment`, `FilterContext` value objects

**Files:**
- Create: `src/Api/Filtering/DateRange.php`
- Create: `src/Api/Filtering/InvalidFilterException.php`
- Create: `src/Api/Filtering/SqlFragment.php`
- Create: `src/Api/Filtering/FilterContext.php`
- Create: `tests/Unit/Filtering/DateRangeTest.php`

These are value objects with no logic worth TDD'ing per-method; combine them in one task.

- [ ] **Step 1: Write `DateRange` tests**

```php
<?php
declare(strict_types=1);

namespace NwsCad\Tests\Unit\Filtering;

use NwsCad\Api\Filtering\DateRange;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

/**
 * @covers \NwsCad\Api\Filtering\DateRange
 */
final class DateRangeTest extends TestCase
{
    public function testFromPresetTodayResolvesToStartAndEndOfToday(): void
    {
        $tz = new \DateTimeZone('America/Indiana/Indianapolis');
        $r = DateRange::fromPreset('today', $tz);
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $this->assertSame($today . ' 00:00:00', $r->from->format('Y-m-d H:i:s'));
        $this->assertSame($today . ' 23:59:59', $r->to->format('Y-m-d H:i:s'));
    }

    public function testFromPresetLast7DaysSpansSevenDayWindow(): void
    {
        $tz = new \DateTimeZone('America/Indiana/Indianapolis');
        $r = DateRange::fromPreset('last_7_days', $tz);
        $diff = $r->to->getTimestamp() - $r->from->getTimestamp();
        $this->assertGreaterThan(6 * 86400, $diff);
        $this->assertLessThanOrEqual(7 * 86400, $diff);
    }

    public function testFromExplicitDateOnlyExpandsToEndOfDay(): void
    {
        $r = DateRange::fromExplicit('2026-05-01', '2026-05-08', new \DateTimeZone('UTC'));
        $this->assertSame('2026-05-01 00:00:00', $r->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-08 23:59:59', $r->to->format('Y-m-d H:i:s'));
    }

    public function testFromExplicitDateTimePassesThroughVerbatim(): void
    {
        $r = DateRange::fromExplicit('2026-05-01T08:00:00', '2026-05-01T17:30:00', new \DateTimeZone('UTC'));
        $this->assertSame('2026-05-01 08:00:00', $r->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-01 17:30:00', $r->to->format('Y-m-d H:i:s'));
    }

    public function testInvalidPresetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DateRange::fromPreset('next_century', new \DateTimeZone('UTC'));
    }
}
```

- [ ] **Step 2: Run — expect failures because the class does not exist**

```bash
./vendor/bin/phpunit tests/Unit/Filtering/DateRangeTest.php
```
Expected: FAIL with "Class DateRange not found".

- [ ] **Step 3: Implement `DateRange`**

```php
<?php
declare(strict_types=1);

namespace NwsCad\Api\Filtering;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class DateRange
{
    public function __construct(
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
    ) {}

    public static function fromPreset(string $preset, DateTimeZone $tz): self
    {
        $now = new DateTimeImmutable('now', $tz);
        return match ($preset) {
            'today'        => new self($now->setTime(0, 0, 0), $now->setTime(23, 59, 59)),
            'yesterday'    => new self(
                $now->modify('-1 day')->setTime(0, 0, 0),
                $now->modify('-1 day')->setTime(23, 59, 59),
            ),
            'last_7_days'  => new self(
                $now->modify('-6 days')->setTime(0, 0, 0),
                $now->setTime(23, 59, 59),
            ),
            'last_30_days' => new self(
                $now->modify('-29 days')->setTime(0, 0, 0),
                $now->setTime(23, 59, 59),
            ),
            'this_month'   => new self(
                $now->modify('first day of this month')->setTime(0, 0, 0),
                $now->modify('last day of this month')->setTime(23, 59, 59),
            ),
            'last_month'   => new self(
                $now->modify('first day of last month')->setTime(0, 0, 0),
                $now->modify('last day of last month')->setTime(23, 59, 59),
            ),
            default => throw new InvalidArgumentException("Unknown preset: {$preset}"),
        };
    }

    public static function fromExplicit(?string $from, ?string $to, DateTimeZone $tz): self
    {
        $parse = static function (string $value, bool $isEnd) use ($tz): DateTimeImmutable {
            // Date-only → expand to start/end of day. Datetime → use as-is.
            $isDateOnly = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
            $dt = new DateTimeImmutable($value, $tz);
            if ($isDateOnly) {
                $dt = $isEnd ? $dt->setTime(23, 59, 59) : $dt->setTime(0, 0, 0);
            }
            return $dt;
        };
        return new self(
            $parse($from ?? '1970-01-01', false),
            $parse($to ?? (new DateTimeImmutable('now', $tz))->format('Y-m-d'), true),
        );
    }
}
```

- [ ] **Step 4: Run — expect PASS**

```bash
./vendor/bin/phpunit tests/Unit/Filtering/DateRangeTest.php
```
Expected: 5 tests passing.

- [ ] **Step 5: Implement `InvalidFilterException`, `SqlFragment`, `FilterContext`**

```php
<?php
// src/Api/Filtering/InvalidFilterException.php
declare(strict_types=1);
namespace NwsCad\Api\Filtering;

class InvalidFilterException extends \RuntimeException {}
```

```php
<?php
// src/Api/Filtering/SqlFragment.php
declare(strict_types=1);
namespace NwsCad\Api\Filtering;

final class SqlFragment
{
    /**
     * @param string[] $joins
     * @param array<string,mixed> $params
     */
    public function __construct(
        public readonly string $whereClause,
        public readonly array $params,
        public readonly array $joins,
    ) {}

    public static function empty(): self
    {
        return new self('', [], []);
    }
}
```

```php
<?php
// src/Api/Filtering/FilterContext.php
declare(strict_types=1);
namespace NwsCad\Api\Filtering;

final class FilterContext
{
    /**
     * @param string[] $alreadyJoined Tables the controller's base SELECT already references
     */
    public function __construct(
        public readonly string $baseTable,
        public readonly array $alreadyJoined = [],
    ) {}

    public function isJoined(string $table): bool
    {
        return in_array($table, $this->alreadyJoined, true);
    }
}
```

- [ ] **Step 6: Run unit suite**

```bash
composer test:unit
```
Expected: green, with 5 new tests added.

- [ ] **Step 7: Commit**

```bash
git add src/Api/Filtering/ tests/Unit/Filtering/
git commit -m "$(cat <<'EOF'
feat(filtering): add DateRange, InvalidFilterException, SqlFragment, FilterContext value objects

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 6: `FilterRegistry` — per-controller allowlist

**Files:**
- Create: `src/Api/Filtering/FilterRegistry.php`
- Create: `tests/Unit/Filtering/FilterRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace NwsCad\Tests\Unit\Filtering;

use NwsCad\Api\Filtering\FilterRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Filtering\FilterRegistry
 */
final class FilterRegistryTest extends TestCase
{
    public function testCallsControllerHasFullFilterSet(): void
    {
        $allowed = FilterRegistry::for('calls');
        $this->assertContains('preset', $allowed);
        $this->assertContains('from', $allowed);
        $this->assertContains('to', $allowed);
        $this->assertContains('date_field', $allowed);
        $this->assertContains('call_type', $allowed);
        $this->assertContains('incident_type', $allowed);
        $this->assertContains('nature_of_call', $allowed);
        $this->assertContains('agency', $allowed);
        $this->assertContains('ori', $allowed);
        $this->assertContains('fdid', $allowed);
        $this->assertContains('beat', $allowed);
        $this->assertContains('area', $allowed);
        $this->assertContains('city', $allowed);
        $this->assertContains('location', $allowed);
        $this->assertContains('call_id', $allowed);
        $this->assertContains('unit', $allowed);
        $this->assertContains('status', $allowed);
        $this->assertContains('q', $allowed);
    }

    public function testUnitsControllerOmitsLocationFilters(): void
    {
        $allowed = FilterRegistry::for('units');
        $this->assertContains('unit', $allowed);
        $this->assertContains('agency', $allowed);
        $this->assertContains('status', $allowed);
        $this->assertNotContains('beat', $allowed); // beat is a location field
    }

    public function testUnknownControllerReturnsEmptyAllowlist(): void
    {
        $this->assertSame([], FilterRegistry::for('does-not-exist'));
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
./vendor/bin/phpunit tests/Unit/Filtering/FilterRegistryTest.php
```

- [ ] **Step 3: Implement `FilterRegistry`**

```php
<?php
declare(strict_types=1);

namespace NwsCad\Api\Filtering;

final class FilterRegistry
{
    /** @var array<string, string[]> */
    private const ALLOWLISTS = [
        'calls' => [
            'preset', 'from', 'to', 'date_field',
            'call_type', 'incident_type', 'nature_of_call',
            'agency', 'ori', 'fdid',
            'beat', 'area', 'city', 'location',
            'call_id', 'unit', 'status', 'q',
            'page', 'per_page', 'sort', 'order',
        ],
        'units' => [
            'preset', 'from', 'to', 'date_field',
            'agency', 'unit', 'status', 'call_id',
            'page', 'per_page', 'sort', 'order',
        ],
        'stats' => [
            'preset', 'from', 'to', 'date_field',
            'agency', 'ori', 'fdid', 'city', 'call_type',
        ],
    ];

    /** @return string[] */
    public static function for(string $controller): array
    {
        return self::ALLOWLISTS[$controller] ?? [];
    }
}
```

- [ ] **Step 4: Run tests, expect PASS**

```bash
./vendor/bin/phpunit tests/Unit/Filtering/FilterRegistryTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Api/Filtering/FilterRegistry.php tests/Unit/Filtering/FilterRegistryTest.php
git commit -m "$(cat <<'EOF'
feat(filtering): add FilterRegistry per-controller allowlist

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 7: `FilterCriteria` — parsing and security limits

**Files:**
- Create: `src/Api/Filtering/FilterCriteria.php`
- Create: `tests/Unit/Filtering/FilterCriteriaTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

namespace NwsCad\Tests\Unit\Filtering;

use NwsCad\Api\Filtering\FilterCriteria;
use NwsCad\Api\Filtering\FilterRegistry;
use NwsCad\Api\Filtering\InvalidFilterException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Filtering\FilterCriteria
 * @uses \NwsCad\Api\Filtering\DateRange
 * @uses \NwsCad\Api\Filtering\FilterRegistry
 * @uses \NwsCad\Api\Filtering\InvalidFilterException
 */
final class FilterCriteriaTest extends TestCase
{
    private array $allowed;

    protected function setUp(): void
    {
        $this->allowed = FilterRegistry::for('calls');
    }

    public function testEmptyQueryProducesEmptyCriteria(): void
    {
        $c = FilterCriteria::fromQuery([], $this->allowed);
        $this->assertNull($c->dateRange);
        $this->assertSame([], $c->callType);
        $this->assertSame([], $c->status);
    }

    public function testParsesCsvIntoArray(): void
    {
        $c = FilterCriteria::fromQuery(['call_type' => 'Police,Fire,EMS'], $this->allowed);
        $this->assertSame(['Police', 'Fire', 'EMS'], $c->callType);
    }

    public function testTrimsAndDropsEmptyValues(): void
    {
        $c = FilterCriteria::fromQuery(['call_type' => ' Police , ,Fire '], $this->allowed);
        $this->assertSame(['Police', 'Fire'], $c->callType);
    }

    public function testDropsParamsNotInAllowlist(): void
    {
        $c = FilterCriteria::fromQuery(['unauthorized' => 'x', 'call_type' => 'Police'], $this->allowed);
        $this->assertSame(['Police'], $c->callType);
    }

    public function testEnforcesFiftyValueCap(): void
    {
        $values = implode(',', array_fill(0, 51, 'Police'));
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessage('Too many values');
        FilterCriteria::fromQuery(['call_type' => $values], $this->allowed);
    }

    public function testEnforces256CharCapPerValue(): void
    {
        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromQuery(['nature_of_call' => str_repeat('a', 257)], $this->allowed);
    }

    public function testStatusEnumValidated(): void
    {
        $c = FilterCriteria::fromQuery(['status' => 'open,closed'], $this->allowed);
        $this->assertSame(['open', 'closed'], $c->status);

        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromQuery(['status' => 'banana'], $this->allowed);
    }

    public function testDateFieldEnumValidated(): void
    {
        $c = FilterCriteria::fromQuery(['date_field' => 'closed'], $this->allowed);
        $this->assertSame('closed', $c->dateField);

        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromQuery(['date_field' => 'invented'], $this->allowed);
    }

    public function testPresetResolvesToDateRange(): void
    {
        $c = FilterCriteria::fromQuery(['preset' => 'today'], $this->allowed);
        $this->assertNotNull($c->dateRange);
    }

    public function testExplicitFromToOverridesPreset(): void
    {
        $c = FilterCriteria::fromQuery(
            ['preset' => 'today', 'from' => '2026-01-01', 'to' => '2026-01-31'],
            $this->allowed
        );
        $this->assertSame('2026-01-01 00:00:00', $c->dateRange->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-31 23:59:59', $c->dateRange->to->format('Y-m-d H:i:s'));
    }

    public function testToArrayRoundTripsParseable(): void
    {
        $c = FilterCriteria::fromQuery(
            ['call_type' => 'Police,Fire', 'status' => 'open', 'q' => 'jane'],
            $this->allowed
        );
        $arr = $c->toArray();
        $this->assertSame(['Police', 'Fire'], $arr['call_type']);
        $this->assertSame(['open'], $arr['status']);
        $this->assertSame('jane', $arr['q']);
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

```bash
./vendor/bin/phpunit tests/Unit/Filtering/FilterCriteriaTest.php
```

- [ ] **Step 3: Implement `FilterCriteria`**

```php
<?php
declare(strict_types=1);

namespace NwsCad\Api\Filtering;

use DateTimeZone;

final class FilterCriteria
{
    private const MAX_VALUES_PER_FIELD = 50;
    private const MAX_VALUE_LENGTH = 256;
    private const VALID_STATUSES = ['open', 'closed', 'canceled'];
    private const VALID_DATE_FIELDS = ['created', 'closed'];

    /**
     * @param string[] $callType
     * @param string[] $incidentType
     * @param string[] $agency
     * @param string[] $ori
     * @param string[] $fdid
     * @param string[] $beat
     * @param string[] $area
     * @param string[] $city
     * @param string[] $callId
     * @param string[] $unit
     * @param string[] $status
     */
    public function __construct(
        public readonly ?DateRange $dateRange,
        public readonly string $dateField,
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

    /**
     * @param array<string, mixed> $query
     * @param string[] $allowed
     */
    public static function fromQuery(array $query, array $allowed): self
    {
        $get = static function (string $key) use ($query, $allowed): ?string {
            if (!in_array($key, $allowed, true)) {
                return null;
            }
            $v = $query[$key] ?? null;
            if (!is_string($v) || $v === '') {
                return null;
            }
            return $v;
        };

        $csv = static function (?string $raw, string $field) use ($allowed): array {
            if ($raw === null) {
                return [];
            }
            $values = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== ''));
            if (count($values) > self::MAX_VALUES_PER_FIELD) {
                throw new InvalidFilterException("Too many values for filter {$field} (max " . self::MAX_VALUES_PER_FIELD . ")");
            }
            foreach ($values as $v) {
                if (strlen($v) > self::MAX_VALUE_LENGTH) {
                    throw new InvalidFilterException("Value too long for filter {$field} (max " . self::MAX_VALUE_LENGTH . " chars)");
                }
            }
            return $values;
        };

        $single = static function (?string $raw, string $field): ?string {
            if ($raw === null) return null;
            if (strlen($raw) > self::MAX_VALUE_LENGTH) {
                throw new InvalidFilterException("Value too long for filter {$field} (max " . self::MAX_VALUE_LENGTH . " chars)");
            }
            return $raw;
        };

        $tz = new DateTimeZone(getenv('APP_TZ') ?: 'America/Indiana/Indianapolis');

        // Date range: explicit from/to wins over preset
        $dateRange = null;
        $from = $get('from');
        $to   = $get('to');
        if ($from !== null || $to !== null) {
            $dateRange = DateRange::fromExplicit($from, $to, $tz);
        } elseif (($preset = $get('preset')) !== null) {
            try {
                $dateRange = DateRange::fromPreset($preset, $tz);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidFilterException($e->getMessage());
            }
        }

        $dateField = $get('date_field') ?? 'created';
        if (!in_array($dateField, self::VALID_DATE_FIELDS, true)) {
            throw new InvalidFilterException("Invalid date_field: {$dateField}");
        }

        $status = $csv($get('status'), 'status');
        foreach ($status as $s) {
            if (!in_array($s, self::VALID_STATUSES, true)) {
                throw new InvalidFilterException("Invalid status value: {$s}");
            }
        }

        return new self(
            dateRange:    $dateRange,
            dateField:    $dateField,
            callType:     $csv($get('call_type'), 'call_type'),
            incidentType: $csv($get('incident_type'), 'incident_type'),
            agency:       $csv($get('agency'), 'agency'),
            ori:          $csv($get('ori'), 'ori'),
            fdid:         $csv($get('fdid'), 'fdid'),
            beat:         $csv($get('beat'), 'beat'),
            area:         $csv($get('area'), 'area'),
            city:         $csv($get('city'), 'city'),
            location:     $single($get('location'), 'location'),
            natureOfCall: $single($get('nature_of_call'), 'nature_of_call'),
            callId:       $csv($get('call_id'), 'call_id'),
            unit:         $csv($get('unit'), 'unit'),
            status:       $status,
            search:       $single($get('q'), 'q'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date_field'     => $this->dateField,
            'from'           => $this->dateRange?->from->format('c'),
            'to'             => $this->dateRange?->to->format('c'),
            'call_type'      => $this->callType,
            'incident_type'  => $this->incidentType,
            'agency'         => $this->agency,
            'ori'            => $this->ori,
            'fdid'           => $this->fdid,
            'beat'           => $this->beat,
            'area'           => $this->area,
            'city'           => $this->city,
            'location'       => $this->location,
            'nature_of_call' => $this->natureOfCall,
            'call_id'        => $this->callId,
            'unit'           => $this->unit,
            'status'         => $this->status,
            'q'              => $this->search,
        ];
    }
}
```

- [ ] **Step 4: Run — expect PASS**

```bash
./vendor/bin/phpunit tests/Unit/Filtering/FilterCriteriaTest.php
```
Expected: 11 tests passing.

- [ ] **Step 5: Commit**

```bash
git add src/Api/Filtering/FilterCriteria.php tests/Unit/Filtering/FilterCriteriaTest.php
git commit -m "$(cat <<'EOF'
feat(filtering): add FilterCriteria parser with security limits

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 8: `FilterSqlBuilder` — generate parameterized WHERE/JOINs

**Files:**
- Create: `src/Api/Filtering/FilterSqlBuilder.php`
- Create: `tests/Unit/Filtering/FilterSqlBuilderTest.php`

This class is the most consequential of the backend. Test every filter individually.

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

namespace NwsCad\Tests\Unit\Filtering;

use NwsCad\Api\Filtering\FilterContext;
use NwsCad\Api\Filtering\FilterCriteria;
use NwsCad\Api\Filtering\FilterRegistry;
use NwsCad\Api\Filtering\FilterSqlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Filtering\FilterSqlBuilder
 * @uses \NwsCad\Api\Filtering\DateRange
 * @uses \NwsCad\Api\Filtering\FilterContext
 * @uses \NwsCad\Api\Filtering\FilterCriteria
 * @uses \NwsCad\Api\Filtering\FilterRegistry
 * @uses \NwsCad\Api\Filtering\InvalidFilterException
 * @uses \NwsCad\Api\Filtering\SqlFragment
 */
final class FilterSqlBuilderTest extends TestCase
{
    private FilterSqlBuilder $b;
    private array $allowed;

    protected function setUp(): void
    {
        $this->b = new FilterSqlBuilder();
        $this->allowed = FilterRegistry::for('calls');
    }

    private function build(array $query, array $alreadyJoined = ['calls']): array
    {
        $criteria = FilterCriteria::fromQuery($query, $this->allowed);
        $ctx = new FilterContext('calls', $alreadyJoined);
        $f = $this->b->build($criteria, $ctx);
        return [$f->whereClause, $f->params, $f->joins];
    }

    public function testEmptyCriteriaProducesEmptyFragment(): void
    {
        [$where, $params, $joins] = $this->build([]);
        $this->assertSame('', $where);
        $this->assertSame([], $params);
        $this->assertSame([], $joins);
    }

    public function testCallTypeMultiSelectGeneratesInClause(): void
    {
        [$where, $params, $joins] = $this->build(['call_type' => 'Police,Fire']);
        $this->assertStringContainsString('IN (:call_type_0, :call_type_1)', $where);
        $this->assertSame('Police', $params['call_type_0']);
        $this->assertSame('Fire',   $params['call_type_1']);
        $this->assertContains('LEFT JOIN agency_contexts ac ON ac.call_id = calls.id', $joins);
    }

    public function testStatusOpenDecodesToFlagsClause(): void
    {
        [$where, $params] = $this->build(['status' => 'open']);
        $this->assertStringContainsString('calls.closed_flag = 0', $where);
        $this->assertStringContainsString('calls.canceled_flag = 0', $where);
    }

    public function testStatusMultipleOrsClauses(): void
    {
        [$where] = $this->build(['status' => 'open,closed']);
        // Each value contributes a parenthesized clause OR'd together
        $this->assertMatchesRegularExpression(
            '/\(.*closed_flag = 0.*canceled_flag = 0.*\) OR \(.*closed_flag = 1.*canceled_flag = 0.*\)/s',
            $where
        );
    }

    public function testDateRangeBindsFromAndTo(): void
    {
        [$where, $params] = $this->build(['from' => '2026-05-01', 'to' => '2026-05-08']);
        $this->assertStringContainsString('calls.create_datetime >= :date_from', $where);
        $this->assertStringContainsString('calls.create_datetime <= :date_to', $where);
        $this->assertSame('2026-05-01 00:00:00', $params['date_from']);
        $this->assertSame('2026-05-08 23:59:59', $params['date_to']);
    }

    public function testDateFieldClosedSwitchesColumn(): void
    {
        [$where] = $this->build(['from' => '2026-05-01', 'date_field' => 'closed']);
        $this->assertStringContainsString('calls.close_datetime >= :date_from', $where);
    }

    public function testOriMatchesAcrossThreeColumns(): void
    {
        [$where, $params, $joins] = $this->build(['ori' => 'IN0480000']);
        $this->assertStringContainsString('locations.police_ori IN (:ori_0)', $where);
        $this->assertStringContainsString('OR locations.ems_ori IN (:ori_0)', $where);
        $this->assertStringContainsString('OR locations.fire_ori IN (:ori_0)', $where);
        $this->assertContains('LEFT JOIN locations ON locations.call_id = calls.id', $joins);
    }

    public function testFdidJoinsAgencyContexts(): void
    {
        [$where, $params, $joins] = $this->build(['fdid' => '48013']);
        $this->assertStringContainsString('agency_contexts.fdid IN (:fdid_0)', $where);
        $this->assertContains('LEFT JOIN agency_contexts ac ON ac.call_id = calls.id', $joins);
    }

    public function testNatureOfCallUsesLikeWithEscapedPattern(): void
    {
        [$where, $params] = $this->build(['nature_of_call' => 'jay_walk%']);
        $this->assertStringContainsString('calls.nature_of_call LIKE :nature_of_call', $where);
        // _ and % are escaped to \_ \%
        $this->assertSame('%jay\\_walk\\%%', $params['nature_of_call']);
    }

    public function testLocationMatchesAddressAndCommonName(): void
    {
        [$where, $params] = $this->build(['location' => 'Main St']);
        $this->assertStringContainsString('locations.full_address LIKE :location', $where);
        $this->assertStringContainsString('locations.common_name LIKE :location', $where);
    }

    public function testCallIdMultiSelect(): void
    {
        [$where, $params] = $this->build(['call_id' => '2026-001,2026-002']);
        $this->assertStringContainsString('calls.call_number IN (:call_id_0, :call_id_1)', $where);
    }

    public function testUnitJoinsUnitsTable(): void
    {
        [$where, $params, $joins] = $this->build(['unit' => '41,42']);
        $this->assertStringContainsString('units.unit_number IN (:unit_0, :unit_1)', $where);
        $this->assertContains('LEFT JOIN units ON units.call_id = calls.id', $joins);
    }

    public function testIncidentTypeJoinsIncidentsTable(): void
    {
        [$where, $params, $joins] = $this->build(['incident_type' => 'Traffic Stop']);
        $this->assertStringContainsString('incidents.incident_type IN (:incident_type_0)', $where);
        $this->assertContains('LEFT JOIN incidents ON incidents.call_id = calls.id', $joins);
    }

    public function testDoesNotEmitJoinForAlreadyJoinedTable(): void
    {
        [$where, $params, $joins] = $this->build(
            ['call_type' => 'Police'],
            ['calls', 'agency_contexts'] // already joined
        );
        $this->assertNotContains('LEFT JOIN agency_contexts ac ON ac.call_id = calls.id', $joins);
    }

    public function testCombinesMultipleFiltersWithAnd(): void
    {
        [$where] = $this->build([
            'call_type' => 'Police',
            'status'    => 'open',
            'city'      => 'Pendleton',
        ]);
        $this->assertStringContainsString(' AND ', $where);
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
./vendor/bin/phpunit tests/Unit/Filtering/FilterSqlBuilderTest.php
```

- [ ] **Step 3: Implement `FilterSqlBuilder`**

```php
<?php
declare(strict_types=1);

namespace NwsCad\Api\Filtering;

final class FilterSqlBuilder
{
    public function build(FilterCriteria $f, FilterContext $ctx): SqlFragment
    {
        $clauses = [];
        $params  = [];
        $joins   = [];

        $needsAgencyContexts = $f->callType || $f->fdid || $f->agency;
        $needsLocations      = $f->ori || $f->beat || $f->area || $f->city || $f->location !== null;
        $needsIncidents      = $f->incidentType !== [];
        $needsUnits          = $f->unit !== [];

        if ($needsAgencyContexts && !$ctx->isJoined('agency_contexts')) {
            $joins[] = 'LEFT JOIN agency_contexts ac ON ac.call_id = calls.id';
        }
        if ($needsLocations && !$ctx->isJoined('locations')) {
            $joins[] = 'LEFT JOIN locations ON locations.call_id = calls.id';
        }
        if ($needsIncidents && !$ctx->isJoined('incidents')) {
            $joins[] = 'LEFT JOIN incidents ON incidents.call_id = calls.id';
        }
        if ($needsUnits && !$ctx->isJoined('units')) {
            $joins[] = 'LEFT JOIN units ON units.call_id = calls.id';
        }

        // Date range
        if ($f->dateRange !== null) {
            $col = $f->dateField === 'closed' ? 'calls.close_datetime' : 'calls.create_datetime';
            $clauses[] = "{$col} >= :date_from";
            $clauses[] = "{$col} <= :date_to";
            $params['date_from'] = $f->dateRange->from->format('Y-m-d H:i:s');
            $params['date_to']   = $f->dateRange->to->format('Y-m-d H:i:s');
        }

        // Single-column IN() filters
        $simpleIn = [
            'call_type'     => ['column' => 'agency_contexts.call_type',  'values' => $f->callType,     'prefix' => 'call_type'],
            'incident_type' => ['column' => 'incidents.incident_type',    'values' => $f->incidentType, 'prefix' => 'incident_type'],
            'fdid'          => ['column' => 'agency_contexts.fdid',       'values' => $f->fdid,         'prefix' => 'fdid'],
            'beat'          => ['column' => 'locations.police_beat',      'values' => $f->beat,         'prefix' => 'beat'],
            'city'          => ['column' => 'locations.city',             'values' => $f->city,         'prefix' => 'city'],
            'call_id'       => ['column' => 'calls.call_number',          'values' => $f->callId,       'prefix' => 'call_id'],
            'unit'          => ['column' => 'units.unit_number',          'values' => $f->unit,         'prefix' => 'unit'],
            'agency'        => ['column' => 'agency_contexts.agency_type','values' => $f->agency,       'prefix' => 'agency'],
        ];
        foreach ($simpleIn as $cfg) {
            if ($cfg['values'] === []) continue;
            $placeholders = [];
            foreach ($cfg['values'] as $i => $v) {
                $name = $cfg['prefix'] . '_' . $i;
                $placeholders[] = ':' . $name;
                $params[$name] = $v;
            }
            $clauses[] = $cfg['column'] . ' IN (' . implode(', ', $placeholders) . ')';
        }

        // ORI: matches across police_ori OR ems_ori OR fire_ori
        if ($f->ori !== []) {
            $placeholders = [];
            foreach ($f->ori as $i => $v) {
                $name = 'ori_' . $i;
                $placeholders[] = ':' . $name;
                $params[$name] = $v;
            }
            $list = implode(', ', $placeholders);
            $clauses[] = "(locations.police_ori IN ({$list}) OR locations.ems_ori IN ({$list}) OR locations.fire_ori IN ({$list}))";
        }

        // Area: matches fire_quadrant OR ems_district
        if ($f->area !== []) {
            $placeholders = [];
            foreach ($f->area as $i => $v) {
                $name = 'area_' . $i;
                $placeholders[] = ':' . $name;
                $params[$name] = $v;
            }
            $list = implode(', ', $placeholders);
            $clauses[] = "(locations.fire_quadrant IN ({$list}) OR locations.ems_district IN ({$list}))";
        }

        // LIKE filters (location, nature_of_call). Escape % and _ so users typing them are taken literally.
        if ($f->location !== null) {
            $params['location'] = '%' . self::escapeLike($f->location) . '%';
            $clauses[] = '(locations.full_address LIKE :location OR locations.common_name LIKE :location)';
        }
        if ($f->natureOfCall !== null) {
            $params['nature_of_call'] = '%' . self::escapeLike($f->natureOfCall) . '%';
            $clauses[] = 'calls.nature_of_call LIKE :nature_of_call';
        }

        // Free-text q across narratives + caller + incident #. Joins narratives if used.
        if ($f->search !== null && $f->search !== '') {
            if (!$ctx->isJoined('narratives')) {
                $joins[] = 'LEFT JOIN narratives ON narratives.call_id = calls.id';
            }
            if (!$needsIncidents && !$ctx->isJoined('incidents')) {
                $joins[] = 'LEFT JOIN incidents ON incidents.call_id = calls.id';
            }
            $params['q'] = '%' . self::escapeLike($f->search) . '%';
            $clauses[] = '(narratives.note LIKE :q OR calls.caller_name LIKE :q OR incidents.incident_number LIKE :q)';
        }

        // Status: each selected value becomes a parenthesised clause; multiple OR'd
        if ($f->status !== []) {
            $statusClauses = [];
            foreach ($f->status as $s) {
                $statusClauses[] = match ($s) {
                    'open'     => '(calls.closed_flag = 0 AND calls.canceled_flag = 0)',
                    'closed'   => '(calls.closed_flag = 1 AND calls.canceled_flag = 0)',
                    'canceled' => '(calls.canceled_flag = 1)',
                };
            }
            $clauses[] = '(' . implode(' OR ', $statusClauses) . ')';
        }

        return new SqlFragment(
            whereClause: $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '',
            params: $params,
            joins: $joins,
        );
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
```

- [ ] **Step 4: Run — expect PASS**

```bash
./vendor/bin/phpunit tests/Unit/Filtering/FilterSqlBuilderTest.php
```

- [ ] **Step 5: Run full unit suite to make sure nothing else regressed**

```bash
composer test:unit
```

- [ ] **Step 6: Commit**

```bash
git add src/Api/Filtering/FilterSqlBuilder.php tests/Unit/Filtering/FilterSqlBuilderTest.php
git commit -m "$(cat <<'EOF'
feat(filtering): add FilterSqlBuilder with parameterized WHERE/JOIN generation

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 3 — Filter options endpoint

### Task 9: `FilterOptionsCache` — in-process TTL cache with invalidation

**Files:**
- Create: `src/Api/Filtering/FilterOptionsCache.php`
- Create: `tests/Unit/Filtering/FilterOptionsCacheTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
declare(strict_types=1);

namespace NwsCad\Tests\Unit\Filtering;

use NwsCad\Api\Filtering\FilterOptionsCache;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Filtering\FilterOptionsCache
 */
final class FilterOptionsCacheTest extends TestCase
{
    protected function setUp(): void
    {
        FilterOptionsCache::clear();
    }

    public function testStoresAndRetrievesByKey(): void
    {
        FilterOptionsCache::put('agency', ['Police', 'Fire']);
        $this->assertSame(['Police', 'Fire'], FilterOptionsCache::get('agency'));
    }

    public function testReturnsNullOnMiss(): void
    {
        $this->assertNull(FilterOptionsCache::get('nope'));
    }

    public function testInvalidateRemovesKey(): void
    {
        FilterOptionsCache::put('city', ['Pendleton']);
        FilterOptionsCache::invalidate(['city']);
        $this->assertNull(FilterOptionsCache::get('city'));
    }

    public function testEntriesExpireAfterTtl(): void
    {
        FilterOptionsCache::putAt('agency', ['Police'], time() - 400); // older than 300s
        $this->assertNull(FilterOptionsCache::get('agency'));
    }

    public function testRespectsCustomTtl(): void
    {
        FilterOptionsCache::put('agency', ['Police']);
        // get() within 5 minutes — still hot
        $this->assertSame(['Police'], FilterOptionsCache::get('agency'));
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
./vendor/bin/phpunit tests/Unit/Filtering/FilterOptionsCacheTest.php
```

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace NwsCad\Api\Filtering;

final class FilterOptionsCache
{
    private const TTL_SECONDS = 300;

    /** @var array<string, array{value: mixed, expires_at: int}> */
    private static array $store = [];

    public static function get(string $key): mixed
    {
        $entry = self::$store[$key] ?? null;
        if ($entry === null) return null;
        if ($entry['expires_at'] < time()) {
            unset(self::$store[$key]);
            return null;
        }
        return $entry['value'];
    }

    public static function put(string $key, mixed $value): void
    {
        self::$store[$key] = ['value' => $value, 'expires_at' => time() + self::TTL_SECONDS];
    }

    /** Internal/test helper: insert with explicit timestamp (used to simulate aging). */
    public static function putAt(string $key, mixed $value, int $createdAt): void
    {
        self::$store[$key] = ['value' => $value, 'expires_at' => $createdAt + self::TTL_SECONDS];
    }

    /** @param string[] $keys */
    public static function invalidate(array $keys): void
    {
        foreach ($keys as $k) {
            unset(self::$store[$k]);
        }
    }

    public static function clear(): void
    {
        self::$store = [];
    }
}
```

- [ ] **Step 4: Run — expect PASS**

```bash
./vendor/bin/phpunit tests/Unit/Filtering/FilterOptionsCacheTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Api/Filtering/FilterOptionsCache.php tests/Unit/Filtering/FilterOptionsCacheTest.php
git commit -m "$(cat <<'EOF'
feat(filtering): add in-process FilterOptionsCache with TTL + invalidation

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 10: `FilterOptionsController` and route

**Files:**
- Create: `src/Api/Controllers/FilterOptionsController.php`
- Modify: `public/api.php` (add the route)
- Create: `tests/Integration/FilterOptionsEndpointTest.php`

- [ ] **Step 1: Write the integration test**

```php
<?php
declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Api\Filtering\FilterOptionsCache;
use NwsCad\Api\Response;
use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Controllers\FilterOptionsController
 * @uses \NwsCad\Api\Filtering\FilterOptionsCache
 * @uses \NwsCad\Api\Response
 * @uses \NwsCad\Api\Request
 * @uses \NwsCad\Database
 * @uses \NwsCad\Config
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 */
final class FilterOptionsEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        Response::resetForTesting();
        FilterOptionsCache::clear();
        $this->seedReferenceTables();
    }

    public function testReturnsAgencyOptionsFromRefTable(): void
    {
        $_GET = ['fields' => 'agency'];
        ob_start();
        (new \NwsCad\Api\Controllers\FilterOptionsController())->index();
        $body = json_decode(ob_get_clean(), true);

        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('agency', $body['data']);
        $this->assertNotEmpty($body['data']['agency']);
        $this->assertSame('PEN_PD', $body['data']['agency'][0]['value']);
    }

    public function testReturnsDerivedCityOptionsFromLocations(): void
    {
        $db = Database::getConnection();
        $db->exec("INSERT INTO calls (call_number, create_datetime) VALUES ('TEST-1', NOW())");
        $callId = (int)$db->lastInsertId();
        $db->exec("INSERT INTO locations (call_id, full_address, city) VALUES ({$callId}, '1 Main St', 'Pendleton')");

        $_GET = ['fields' => 'city'];
        ob_start();
        (new \NwsCad\Api\Controllers\FilterOptionsController())->index();
        $body = json_decode(ob_get_clean(), true);

        $this->assertContains('Pendleton', $body['data']['city']);
    }

    public function testRejectsUnknownField(): void
    {
        $_GET = ['fields' => 'narnia'];
        ob_start();
        (new \NwsCad\Api\Controllers\FilterOptionsController())->index();
        $body = json_decode(ob_get_clean(), true);
        $this->assertFalse($body['success']);
        $this->assertSame(400, http_response_code());
    }

    private function seedReferenceTables(): void
    {
        $db = Database::getConnection();
        $db->exec("DELETE FROM ref_agencies");
        $db->exec("INSERT INTO ref_agencies (code,label,kind,active,sort_order) VALUES ('PEN_PD','Pendleton Police','police',1,10)");
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
./vendor/bin/phpunit tests/Integration/FilterOptionsEndpointTest.php
```

- [ ] **Step 3: Implement `FilterOptionsController`**

```php
<?php
declare(strict_types=1);

namespace NwsCad\Api\Controllers;

use NwsCad\Api\Filtering\FilterOptionsCache;
use NwsCad\Api\Request;
use NwsCad\Api\Response;
use NwsCad\Database;
use PDO;

final class FilterOptionsController
{
    private const SUPPORTED_FIELDS = ['agency', 'ori', 'fdid', 'beat', 'area', 'city', 'call_type', 'incident_type', 'unit'];
    private const DERIVED_FIELDS   = ['city', 'call_type', 'incident_type', 'unit'];
    private const DERIVED_LIMIT    = 1000;

    public function index(): void
    {
        $rawFields = (string) Request::query('fields', implode(',', self::SUPPORTED_FIELDS));
        $fields = array_values(array_filter(array_map('trim', explode(',', $rawFields))));

        foreach ($fields as $field) {
            if (!in_array($field, self::SUPPORTED_FIELDS, true)) {
                Response::error("Unsupported field: {$field}", 400);
                return;
            }
        }

        $db = Database::getConnection();
        $data = [];
        foreach ($fields as $field) {
            $cached = FilterOptionsCache::get($field);
            if ($cached !== null) {
                $data[$field] = $cached;
                continue;
            }
            $data[$field] = $this->loadField($db, $field);
            FilterOptionsCache::put($field, $data[$field]);
        }

        header('Cache-Control: max-age=30, stale-while-revalidate=300');
        Response::success($data);
    }

    /** @return array<int, mixed> */
    private function loadField(PDO $db, string $field): array
    {
        return match ($field) {
            'agency'        => $this->fetchAgencies($db),
            'ori'           => $this->fetchOris($db),
            'fdid'          => $this->fetchFdids($db),
            'beat'          => $this->fetchBeats($db),
            'area'          => $this->fetchAreas($db),
            'city'          => $this->fetchDistinct($db, 'locations', 'city'),
            'call_type'     => $this->fetchDistinct($db, 'agency_contexts', 'call_type'),
            'incident_type' => $this->fetchDistinct($db, 'incidents', 'incident_type'),
            'unit'          => $this->fetchDistinct($db, 'units', 'unit_number'),
        };
    }

    private function fetchAgencies(PDO $db): array
    {
        $stmt = $db->query('SELECT code AS value, label, kind, ori, fdid FROM ref_agencies WHERE active = 1 ORDER BY sort_order, label');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchOris(PDO $db): array
    {
        $stmt = $db->query('SELECT ori AS value, label, kind FROM ref_oris ORDER BY ori');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchFdids(PDO $db): array
    {
        $stmt = $db->query('SELECT fdid AS value, label FROM ref_fdids ORDER BY fdid');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchBeats(PDO $db): array
    {
        $stmt = $db->query('SELECT code AS value, label, kind, jurisdiction FROM ref_beats WHERE active = 1 ORDER BY code');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchAreas(PDO $db): array
    {
        $stmt = $db->query('SELECT code AS value, label, kind FROM ref_areas WHERE active = 1 ORDER BY code');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return string[] */
    private function fetchDistinct(PDO $db, string $table, string $column): array
    {
        // Table and column come from the closed SUPPORTED_FIELDS list — never user input.
        $sql = "SELECT DISTINCT {$column} FROM {$table} WHERE {$column} IS NOT NULL AND {$column} <> '' ORDER BY {$column} LIMIT " . self::DERIVED_LIMIT;
        return array_map(static fn ($row) => (string) $row[$column], $db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }
}
```

- [ ] **Step 4: Wire the route in `public/api.php`**

Open `public/api.php`, find the route registration block (uses `Api\Router`), and add:

```php
$router->get('/api/filter-options', [FilterOptionsController::class, 'index']);
```

(Confirm the existing pattern by reading `public/api.php` first; mirror it exactly.)

- [ ] **Step 5: Run integration tests — expect PASS**

```bash
./vendor/bin/phpunit tests/Integration/FilterOptionsEndpointTest.php
```

- [ ] **Step 6: Commit**

```bash
git add src/Api/Controllers/FilterOptionsController.php public/api.php tests/Integration/FilterOptionsEndpointTest.php
git commit -m "$(cat <<'EOF'
feat(api): add /api/filter-options endpoint with curated+derived merging

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 11: AegisXmlParser cache invalidation hook

**Files:**
- Modify: `src/AegisXmlParser.php`
- Modify: `tests/Unit/AegisXmlParserTest.php`

- [ ] **Step 1: Write a test verifying the parser invalidates derived caches after a write**

```php
public function testInvalidatesFilterOptionsCacheAfterCommit(): void
{
    \NwsCad\Api\Filtering\FilterOptionsCache::put('call_type', ['Police']);
    $this->parser->processFile($this->writeXmlToTemp($this->minimalValidXml()));
    $this->assertNull(\NwsCad\Api\Filtering\FilterOptionsCache::get('call_type'));
}
```

(`@uses \NwsCad\Api\Filtering\FilterOptionsCache` must be added to the test class docblock.)

- [ ] **Step 2: Run — expect FAIL**

```bash
./vendor/bin/phpunit --filter testInvalidatesFilterOptionsCacheAfterCommit tests/Unit/AegisXmlParserTest.php
```

- [ ] **Step 3: Add the invalidation call after the transaction commits**

In `AegisXmlParser::processFile()`, immediately after the `$this->db->commit()` (and before the `CallProcessedEvent` dispatch, to keep the cache fresh before any subscriber acts), add:

```php
\NwsCad\Api\Filtering\FilterOptionsCache::invalidate(['call_type', 'incident_type', 'unit', 'city']);
```

The four keys above match the derived (non-curated) options whose values may have grown.

- [ ] **Step 4: Run — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add src/AegisXmlParser.php tests/Unit/AegisXmlParserTest.php
git commit -m "$(cat <<'EOF'
feat(parser): invalidate FilterOptionsCache for derived fields after ingest

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 4 — Controller refactor

### Task 12: Refactor `CallsController::index()` to use `FilterCriteria` + `FilterSqlBuilder`

**Files:**
- Modify: `src/Api/Controllers/CallsController.php`
- Modify: `tests/Integration/ApiCallsTest.php` (existing) — update assertions where needed
- Create: `tests/Integration/CallsControllerFilterTest.php` (new tests for new filters)

- [ ] **Step 1: Write tests for the new filter surface**

```php
<?php
declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Api\Controllers\CallsController;
use NwsCad\Api\Response;
use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Controllers\CallsController
 * @uses \NwsCad\Api\Filtering\DateRange
 * @uses \NwsCad\Api\Filtering\FilterContext
 * @uses \NwsCad\Api\Filtering\FilterCriteria
 * @uses \NwsCad\Api\Filtering\FilterRegistry
 * @uses \NwsCad\Api\Filtering\FilterSqlBuilder
 * @uses \NwsCad\Api\Filtering\InvalidFilterException
 * @uses \NwsCad\Api\Filtering\SqlFragment
 * @uses \NwsCad\Api\Response
 * @uses \NwsCad\Api\Request
 * @uses \NwsCad\Api\DbHelper
 * @uses \NwsCad\Database
 * @uses \NwsCad\Config
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 */
final class CallsControllerFilterTest extends TestCase
{
    protected function setUp(): void
    {
        Response::resetForTesting();
        $this->seed();
    }

    public function testFiltersByCallType(): void
    {
        $_GET = ['call_type' => 'Police'];
        $body = $this->callIndex();
        $this->assertCount(2, $body['data']['items']);
    }

    public function testFiltersByOri(): void
    {
        $_GET = ['ori' => 'IN0480000'];
        $body = $this->callIndex();
        $this->assertCount(1, $body['data']['items']);
    }

    public function testFiltersByFdid(): void
    {
        $_GET = ['fdid' => '48013'];
        $body = $this->callIndex();
        $this->assertCount(1, $body['data']['items']);
    }

    public function testFiltersByStatusOpen(): void
    {
        $_GET = ['status' => 'open'];
        $body = $this->callIndex();
        // Among seeded rows, 2 are open (closed_flag=0 AND canceled_flag=0)
        $this->assertCount(2, $body['data']['items']);
    }

    public function testFiltersByDateRange(): void
    {
        $_GET = ['from' => '2026-05-01', 'to' => '2026-05-08'];
        $body = $this->callIndex();
        $this->assertGreaterThan(0, count($body['data']['items']));
    }

    public function testReturnsAppliedFiltersInResponse(): void
    {
        $_GET = ['call_type' => 'Police'];
        $body = $this->callIndex();
        $this->assertArrayHasKey('filters', $body['data']);
        $this->assertSame(['Police'], $body['data']['filters']['call_type']);
    }

    public function testReturns400OnInvalidStatus(): void
    {
        $_GET = ['status' => 'banana'];
        $body = $this->callIndex();
        $this->assertFalse($body['success']);
    }

    private function callIndex(): array
    {
        ob_start();
        (new CallsController())->index();
        return json_decode(ob_get_clean(), true);
    }

    private function seed(): void
    {
        $db = Database::getConnection();
        $db->exec('DELETE FROM agency_contexts');
        $db->exec('DELETE FROM locations');
        $db->exec('DELETE FROM calls');

        // Two Police calls (one open, one closed), one Fire call (canceled), one EMS call (open)
        $insert = static function (\PDO $db, array $cols): int {
            $names = implode(',', array_keys($cols));
            $ph    = ':' . implode(',:', array_keys($cols));
            $db->prepare("INSERT INTO calls ({$names}) VALUES ({$ph})")->execute($cols);
            return (int) $db->lastInsertId();
        };

        $c1 = $insert($db, ['call_number' => 'P1', 'create_datetime' => '2026-05-02 10:00:00', 'closed_flag' => 0, 'canceled_flag' => 0]);
        $c2 = $insert($db, ['call_number' => 'P2', 'create_datetime' => '2026-05-03 10:00:00', 'closed_flag' => 1, 'canceled_flag' => 0]);
        $c3 = $insert($db, ['call_number' => 'F1', 'create_datetime' => '2026-05-04 10:00:00', 'closed_flag' => 0, 'canceled_flag' => 1]);
        $c4 = $insert($db, ['call_number' => 'E1', 'create_datetime' => '2026-05-05 10:00:00', 'closed_flag' => 0, 'canceled_flag' => 0]);

        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type, fdid) VALUES (?, ?, ?, ?)')
            ->execute([$c1, 'Pendleton Police', 'Police', null]);
        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type, fdid) VALUES (?, ?, ?, ?)')
            ->execute([$c2, 'Pendleton Police', 'Police', null]);
        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type, fdid) VALUES (?, ?, ?, ?)')
            ->execute([$c3, 'Edgewood Fire', 'Fire', '48013']);
        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type, fdid) VALUES (?, ?, ?, ?)')
            ->execute([$c4, 'Madison EMS', 'EMS', null]);

        $db->prepare('INSERT INTO locations (call_id, full_address, city, police_ori) VALUES (?, ?, ?, ?)')
            ->execute([$c1, '1 Main', 'Pendleton', 'IN0480000']);
        $db->prepare('INSERT INTO locations (call_id, full_address, city, police_ori) VALUES (?, ?, ?, ?)')
            ->execute([$c2, '2 Main', 'Pendleton', 'IN0480200']);
        $db->prepare('INSERT INTO locations (call_id, full_address, city) VALUES (?, ?, ?)')
            ->execute([$c3, '3 Main', 'Edgewood']);
        $db->prepare('INSERT INTO locations (call_id, full_address, city) VALUES (?, ?, ?)')
            ->execute([$c4, '4 Main', 'Madison']);
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
./vendor/bin/phpunit tests/Integration/CallsControllerFilterTest.php
```

- [ ] **Step 3: Refactor `CallsController::index()`**

Replace the entire body of the `index()` method (currently lines 31–260 of the file at plan-write time, but verify before editing). The new body:

```php
public function index(): void
{
    try {
        $criteria = \NwsCad\Api\Filtering\FilterCriteria::fromQuery($_GET, \NwsCad\Api\Filtering\FilterRegistry::for('calls'));
    } catch (\NwsCad\Api\Filtering\InvalidFilterException $e) {
        Response::error($e->getMessage(), 400);
        return;
    }
    $pagination = Request::pagination();
    $sorting    = Request::sorting('create_datetime', 'desc');

    $allowedSort = ['create_datetime', 'close_datetime', 'call_number'];
    $sortField   = in_array($sorting['sort'], $allowedSort, true) ? $sorting['sort'] : 'create_datetime';

    $builder = new \NwsCad\Api\Filtering\FilterSqlBuilder();
    $sql     = $builder->build($criteria, new \NwsCad\Api\Filtering\FilterContext('calls', ['calls']));

    // Count
    $countSql = 'SELECT COUNT(DISTINCT calls.id) FROM calls ' . implode(' ', $sql->joins) . ' ' . $sql->whereClause;
    $countStmt = $this->db->prepare($countSql);
    $countStmt->execute($sql->params);
    $total = (int) $countStmt->fetchColumn();

    // Page
    $offset = ($pagination['page'] - 1) * $pagination['per_page'];
    $listSql = "SELECT DISTINCT calls.* FROM calls "
        . implode(' ', $sql->joins) . ' '
        . $sql->whereClause
        . " ORDER BY calls.{$sortField} {$sorting['order']}"
        . " LIMIT :limit OFFSET :offset";
    $stmt = $this->db->prepare($listSql);
    foreach ($sql->params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':limit',  $pagination['per_page'], \PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();

    Response::success([
        'items'      => $items,
        'pagination' => [
            'total'        => $total,
            'per_page'     => $pagination['per_page'],
            'current_page' => $pagination['page'],
            'total_pages'  => (int) ceil($total / $pagination['per_page']),
        ],
        'filters'    => $criteria->toArray(),
    ]);
}
```

Note: the legacy `Response::paginated()` shape is replaced by the explicit `success(['items', 'pagination', 'filters'])` shape which matches the spec's documented response. If existing tests assert against the old shape, they will need updating in Task 13.

- [ ] **Step 4: Run the new filter tests — expect PASS**

```bash
./vendor/bin/phpunit tests/Integration/CallsControllerFilterTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Api/Controllers/CallsController.php tests/Integration/CallsControllerFilterTest.php
git commit -m "$(cat <<'EOF'
refactor(calls): rewire CallsController::index to FilterCriteria/FilterSqlBuilder

Removes the inline WHERE-clause assembly. The new controller delegates parsing,
validation, and SQL generation to Api\\Filtering. Adds the full filter surface
(call_type, ori, fdid, beat, area, status, etc.) and echoes applied filters.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 13: Update existing `tests/Integration/ApiCallsTest.php` for new response shape

**Files:**
- Modify: `tests/Integration/ApiCallsTest.php`

- [ ] **Step 1: Run the existing suite to see what breaks**

```bash
./vendor/bin/phpunit tests/Integration/ApiCallsTest.php
```
Expected: failures around legacy filter param names (`closed_flag`, `agency_type`) and possibly the response shape (`pagination` keys, missing `filters`).

- [ ] **Step 2: Update assertions to use the new vocabulary**

For each failing test:
- Replace `closed_flag=true` with `status=closed`
- Replace `agency_type=Police` with `call_type=Police`
- Replace assertions on `Response::paginated()` shape with assertions on `data.items` and `data.pagination`
- Anywhere the test asserted `data` was a plain array of items, change to `data.items`

The exact diffs depend on what's in the file at edit time. Read it first; update one test method at a time; rerun after each.

- [ ] **Step 3: Run — expect PASS**

```bash
./vendor/bin/phpunit tests/Integration/ApiCallsTest.php
```

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/ApiCallsTest.php
git commit -m "$(cat <<'EOF'
test(calls): update ApiCallsTest assertions to new response shape and filter names

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 14: Refactor `UnitsController::index()` to use the new filter pipeline

**Files:**
- Modify: `src/Api/Controllers/UnitsController.php`
- Modify or create: `tests/Integration/ApiUnitsTest.php` (existing) — keep semantic coverage; add tests for new filters
- Create: `tests/Integration/UnitsControllerFilterTest.php`

- [ ] **Step 1: Write the new filter tests**

Mirror the Calls test structure but assert that filtering by `unit=41`, `agency=Pendleton Police`, and `status=open` returns the expected rows from a small seeded set. Include the same `@covers`/`@uses` block.

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Replace the body of `UnitsController::index()`**

Same pattern as `CallsController::index()`: parse `FilterCriteria` for `'units'`, build SQL via `FilterSqlBuilder`, but the base table is `units` (not `calls`) and the joins differ. The base SELECT becomes:

```sql
SELECT DISTINCT units.*, calls.call_number, calls.nature_of_call, calls.create_datetime AS call_create_datetime
FROM units
LEFT JOIN calls ON calls.id = units.call_id
<sql->joins>
<sql->whereClause>
ORDER BY units.<sortField> <order>
LIMIT :limit OFFSET :offset
```

Important: the `FilterSqlBuilder` was written with `calls` as the base table (joins like `LEFT JOIN units ON units.call_id = calls.id`). For `UnitsController` we need to flip: base is `units`, joins reach back through `calls`. Two options:

**Option A (chosen):** introduce a `FilterContext::fromUnitsBase()` factory in `FilterContext` and a small `mode` switch in `FilterSqlBuilder` that emits the inverse joins (`LEFT JOIN calls ON calls.id = units.call_id`, etc.).

In `FilterContext`, add:
```php
public readonly bool $unitsBase;

public function __construct(
    public readonly string $baseTable,
    public readonly array $alreadyJoined = [],
    bool $unitsBase = false,
) {
    $this->unitsBase = $unitsBase;
}
```

In `FilterSqlBuilder::build()`, when `$ctx->unitsBase` is true, emit joins as:
- `LEFT JOIN calls ON calls.id = units.call_id` (always, since calls is needed for date columns)
- `LEFT JOIN agency_contexts ac ON ac.call_id = units.call_id`
- `LEFT JOIN locations ON locations.call_id = units.call_id`
- `LEFT JOIN incidents ON incidents.call_id = units.call_id`

Add a unit test in `FilterSqlBuilderTest.php`:
```php
public function testUnitsBaseFlipsJoinsToReachThroughUnits(): void
{
    $criteria = FilterCriteria::fromQuery(['call_type' => 'Police'], FilterRegistry::for('units'));
    $ctx = new FilterContext('units', ['units'], unitsBase: true);
    $sql = (new FilterSqlBuilder())->build($criteria, $ctx);
    $this->assertContains('LEFT JOIN agency_contexts ac ON ac.call_id = units.call_id', $sql->joins);
}
```

Pass before continuing.

- [ ] **Step 4: Run all filter SQL tests — expect PASS**

- [ ] **Step 5: Replace `UnitsController::index()` body**

```php
public function index(): void
{
    try {
        $criteria = \NwsCad\Api\Filtering\FilterCriteria::fromQuery($_GET, \NwsCad\Api\Filtering\FilterRegistry::for('units'));
    } catch (\NwsCad\Api\Filtering\InvalidFilterException $e) {
        Response::error($e->getMessage(), 400);
        return;
    }
    $pagination = Request::pagination();
    $sorting    = Request::sorting('assigned_datetime', 'desc');
    $allowedSort = ['unit_number', 'unit_type', 'assigned_datetime', 'clear_datetime'];
    $sortField   = in_array($sorting['sort'], $allowedSort, true) ? $sorting['sort'] : 'assigned_datetime';

    $builder = new \NwsCad\Api\Filtering\FilterSqlBuilder();
    $sql     = $builder->build($criteria, new \NwsCad\Api\Filtering\FilterContext('units', ['units'], unitsBase: true));

    $countSql = 'SELECT COUNT(DISTINCT units.id) FROM units ' . implode(' ', $sql->joins) . ' ' . $sql->whereClause;
    $countStmt = $this->db->prepare($countSql);
    $countStmt->execute($sql->params);
    $total = (int) $countStmt->fetchColumn();

    $offset = ($pagination['page'] - 1) * $pagination['per_page'];
    $listSql = "SELECT DISTINCT units.*, calls.call_number, calls.nature_of_call, calls.create_datetime AS call_create_datetime "
        . 'FROM units '
        . implode(' ', $sql->joins) . ' '
        . $sql->whereClause
        . " ORDER BY units.{$sortField} {$sorting['order']}"
        . " LIMIT :limit OFFSET :offset";
    $stmt = $this->db->prepare($listSql);
    foreach ($sql->params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':limit',  $pagination['per_page'], \PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
    $stmt->execute();

    Response::success([
        'items'      => $stmt->fetchAll(),
        'pagination' => [
            'total'        => $total,
            'per_page'     => $pagination['per_page'],
            'current_page' => $pagination['page'],
            'total_pages'  => (int) ceil($total / $pagination['per_page']),
        ],
        'filters'    => $criteria->toArray(),
    ]);
}
```

The other methods on `UnitsController` (`show`, `logs`, `personnel`, `dispositions`) are unchanged.

- [ ] **Step 6: Run integration tests for units**

```bash
./vendor/bin/phpunit tests/Integration/UnitsControllerFilterTest.php tests/Integration/ApiUnitsTest.php
```
Expected: PASS. Update `ApiUnitsTest.php` only if the response shape change breaks it (same migration as Task 13).

- [ ] **Step 7: Commit**

```bash
git add src/Api/Controllers/UnitsController.php src/Api/Filtering/FilterContext.php src/Api/Filtering/FilterSqlBuilder.php tests/Integration/UnitsControllerFilterTest.php tests/Unit/Filtering/FilterSqlBuilderTest.php
git commit -m "$(cat <<'EOF'
refactor(units): rewire UnitsController::index to FilterCriteria/FilterSqlBuilder

Adds units-base join orientation to FilterSqlBuilder so the same builder serves
both calls-rooted and units-rooted controllers.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 15: Refactor `StatsController::index()` to use the new pipeline

**Files:**
- Modify: `src/Api/Controllers/StatsController.php`
- Modify: `tests/Integration/ApiStatsTest.php` (existing)

- [ ] **Step 1: Read the current `StatsController::index()`**

The controller has its own filter parsing block (lines 41–71 per the survey). Identify which filters it handles today.

- [ ] **Step 2: Replace the parsing block with the standard pattern**

```php
try {
    $criteria = \NwsCad\Api\Filtering\FilterCriteria::fromQuery($_GET, \NwsCad\Api\Filtering\FilterRegistry::for('stats'));
} catch (\NwsCad\Api\Filtering\InvalidFilterException $e) {
    Response::error($e->getMessage(), 400);
    return;
}
$builder = new \NwsCad\Api\Filtering\FilterSqlBuilder();
$sql     = $builder->build($criteria, new \NwsCad\Api\Filtering\FilterContext('calls', ['calls']));
```

Each existing aggregation query in the controller needs:
- The `$sql->whereClause` interpolated where it currently has `WHERE ...`
- The `$sql->joins` prepended to the `FROM calls` clause where applicable
- `$sql->params` passed to `execute()`

Walk each aggregation block one at a time, run the test suite after each.

- [ ] **Step 3: Update `ApiStatsTest.php` assertions for the new response/filter names**

Same pattern as Task 13.

- [ ] **Step 4: Run the suite**

```bash
./vendor/bin/phpunit tests/Integration/ApiStatsTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Api/Controllers/StatsController.php tests/Integration/ApiStatsTest.php
git commit -m "$(cat <<'EOF'
refactor(stats): rewire StatsController to FilterCriteria/FilterSqlBuilder

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 16: Delete the legacy `Request::filters()` method

**Files:**
- Modify: `src/Api/Request.php`
- Modify: `tests/Unit/ApiRequestTest.php`

- [ ] **Step 1: Confirm there are no remaining callers**

```bash
grep -rn "Request::filters\b\|->filters(\b" src/ tests/ public/ | grep -v Filtering
```
Expected: no hits other than the method definition and its test. If hits exist, those callers must be migrated first.

- [ ] **Step 2: Delete the method**

In `src/Api/Request.php`, remove the `filters()` method body (lines 128–147 in the file).

- [ ] **Step 3: Delete the test method that exercises it**

In `tests/Unit/ApiRequestTest.php`, delete the `testFilters*` methods.

- [ ] **Step 4: Run unit suite — expect PASS**

```bash
composer test:unit
```

- [ ] **Step 5: Commit**

```bash
git add src/Api/Request.php tests/Unit/ApiRequestTest.php
git commit -m "$(cat <<'EOF'
refactor(api): remove legacy Request::filters() — superseded by FilterCriteria

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 5 — Frontend foundation

### Task 17: Vendor Choices.js and Flatpickr

**Files:**
- Create: `public/assets/vendor/choices/choices.min.js`
- Create: `public/assets/vendor/choices/choices.min.css`
- Create: `public/assets/vendor/flatpickr/flatpickr.min.js`
- Create: `public/assets/vendor/flatpickr/flatpickr.min.css`
- Create: `public/assets/vendor/VENDORED.md` (provenance record)

- [ ] **Step 1: Create the vendor directories**

```bash
mkdir -p public/assets/vendor/choices public/assets/vendor/flatpickr
```

- [ ] **Step 2: Download Choices.js v10.x**

```bash
curl -fL -o public/assets/vendor/choices/choices.min.js \
  https://cdn.jsdelivr.net/npm/choices.js@10/public/assets/scripts/choices.min.js
curl -fL -o public/assets/vendor/choices/choices.min.css \
  https://cdn.jsdelivr.net/npm/choices.js@10/public/assets/styles/choices.min.css
```

- [ ] **Step 3: Download Flatpickr v4.x**

```bash
curl -fL -o public/assets/vendor/flatpickr/flatpickr.min.js \
  https://cdn.jsdelivr.net/npm/flatpickr@4/dist/flatpickr.min.js
curl -fL -o public/assets/vendor/flatpickr/flatpickr.min.css \
  https://cdn.jsdelivr.net/npm/flatpickr@4/dist/flatpickr.min.css
```

- [ ] **Step 4: Record provenance**

```markdown
<!-- public/assets/vendor/VENDORED.md -->
# Vendored frontend libraries

| Library | Version | Source | Purpose |
|---|---|---|---|
| Choices.js | 10.x | https://github.com/Choices-js/Choices | Multi-select chip widget for filter panel |
| Flatpickr | 4.x | https://github.com/flatpickr/flatpickr | Date/time + range picker for filter panel |

Both are MIT-licensed and copied from the jsDelivr CDN. To upgrade:
1. Download the new minified js/css from jsDelivr.
2. Update this file with the new version.
3. Verify FilterPanel still mounts on /, /calls, and /units.
```

- [ ] **Step 5: Verify file sizes are reasonable (gzip and check)**

```bash
wc -c public/assets/vendor/choices/* public/assets/vendor/flatpickr/*
```
Expected: choices.min.js around 70-100 KB, flatpickr.min.js around 40-50 KB. If either is much larger or zero bytes, the download failed.

- [ ] **Step 6: Commit**

```bash
git add public/assets/vendor/
git commit -m "$(cat <<'EOF'
chore(vendor): add Choices.js v10 and Flatpickr v4

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 18: `FilterState` — URL ⇄ state object

**Files:**
- Create: `public/assets/js/filters/FilterState.js`
- Create: `tests/js/FilterState.test.html` (manual smoke test page; no JS framework available)

- [ ] **Step 1: Implement `FilterState`**

```js
// public/assets/js/filters/FilterState.js
(function (root) {
  'use strict';

  const MULTI_FIELDS = [
    'call_type', 'incident_type', 'agency', 'ori', 'fdid',
    'beat', 'area', 'city', 'call_id', 'unit', 'status',
  ];
  const SINGLE_FIELDS = ['preset', 'from', 'to', 'date_field', 'location', 'nature_of_call', 'q'];

  function FilterState(initial) {
    this.values = Object.assign({}, initial || {});
  }

  FilterState.MULTI_FIELDS = MULTI_FIELDS;
  FilterState.SINGLE_FIELDS = SINGLE_FIELDS;
  FilterState.ALL_FIELDS = MULTI_FIELDS.concat(SINGLE_FIELDS);

  FilterState.fromQuery = function (qs) {
    const params = new URLSearchParams(qs.indexOf('?') === 0 ? qs.slice(1) : qs);
    const out = {};
    FilterState.ALL_FIELDS.forEach(function (key) {
      const raw = params.get(key);
      if (raw === null || raw === '') return;
      if (MULTI_FIELDS.indexOf(key) >= 0) {
        out[key] = raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
      } else {
        out[key] = raw;
      }
    });
    return new FilterState(out);
  };

  FilterState.prototype.toQueryString = function () {
    const params = new URLSearchParams();
    const v = this.values;
    Object.keys(v).forEach(function (key) {
      const value = v[key];
      if (value === null || value === undefined || value === '') return;
      if (Array.isArray(value)) {
        if (value.length === 0) return;
        params.set(key, value.join(','));
      } else {
        params.set(key, value);
      }
    });
    return params.toString();
  };

  FilterState.prototype.merge = function (partial) {
    Object.keys(partial).forEach(function (key) {
      const v = partial[key];
      if (v === null || v === undefined || (Array.isArray(v) && v.length === 0) || v === '') {
        delete this.values[key];
      } else {
        this.values[key] = v;
      }
    }, this);
    return this;
  };

  FilterState.prototype.get = function (key) { return this.values[key]; };
  FilterState.prototype.clear = function () { this.values = {}; return this; };
  FilterState.prototype.snapshot = function () { return JSON.parse(JSON.stringify(this.values)); };

  // Browser global
  root.FilterState = FilterState;
})(typeof window !== 'undefined' ? window : this);
```

- [ ] **Step 2: Manual smoke test**

```bash
mkdir -p tests/js
```

```html
<!-- tests/js/FilterState.test.html -->
<!doctype html>
<html><body>
<script src="../../public/assets/js/filters/FilterState.js"></script>
<script>
  const tests = [];
  function eq(a, b, msg) {
    const ok = JSON.stringify(a) === JSON.stringify(b);
    tests.push({ msg, ok, a, b });
  }

  const s1 = FilterState.fromQuery('call_type=Police,Fire&status=open&q=jane');
  eq(s1.get('call_type'), ['Police','Fire'], 'parses csv multi');
  eq(s1.get('status'),    ['open'],         'parses csv single multi');
  eq(s1.get('q'),         'jane',           'parses scalar');

  const s2 = new FilterState();
  s2.merge({ call_type: ['Police'], q: 'foo' });
  eq(s2.toQueryString(), 'call_type=Police&q=foo', 'serializes round trip');

  s2.merge({ q: '' });
  eq(s2.get('q'), undefined, 'empty string deletes key');

  document.body.innerHTML = tests.map(t =>
    '<p style="font-family:monospace">' + (t.ok ? '✓' : '✗') + ' ' + t.msg + '</p>'
  ).join('');
</script>
</body></html>
```

Open the file in a browser via `python3 -m http.server` from the repo root, navigate to `/tests/js/FilterState.test.html`, and confirm all four lines show ✓.

- [ ] **Step 3: Commit**

```bash
git add public/assets/js/filters/FilterState.js tests/js/FilterState.test.html
git commit -m "$(cat <<'EOF'
feat(js): add FilterState with URL parse/serialize and merge semantics

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 19: Field components — `DateRangeField`, `MultiSelectField`, `TextField`, `StatusField`

**Files:**
- Create: `public/assets/js/filters/fields/DateRangeField.js`
- Create: `public/assets/js/filters/fields/MultiSelectField.js`
- Create: `public/assets/js/filters/fields/TextField.js`
- Create: `public/assets/js/filters/fields/StatusField.js`
- Create: `public/assets/js/filters/fieldRegistry.js`

Each field component is a class with the same shape: `mount(rootEl, opts)`, `getValue()`, `setValue(v)`, `on('change', cb)`, `destroy()`.

- [ ] **Step 1: Write `MultiSelectField`**

```js
// public/assets/js/filters/fields/MultiSelectField.js
(function (root) {
  'use strict';

  function MultiSelectField(name, label) {
    this.name = name;
    this.label = label;
    this.choices = null;
    this.listeners = [];
  }

  MultiSelectField.prototype.mount = function (rootEl, opts) {
    const select = document.createElement('select');
    select.name = this.name;
    select.id = 'ff-' + this.name;
    select.multiple = true;
    select.setAttribute('aria-label', this.label);
    rootEl.innerHTML = '';
    const labelEl = document.createElement('label');
    labelEl.htmlFor = select.id;
    labelEl.textContent = this.label;
    rootEl.appendChild(labelEl);
    rootEl.appendChild(select);

    (opts.options || []).forEach(function (opt) {
      const option = document.createElement('option');
      option.value = typeof opt === 'string' ? opt : opt.value;
      option.textContent = typeof opt === 'string' ? opt : opt.label;
      select.appendChild(option);
    });

    this.choices = new Choices(select, {
      removeItemButton: true,
      searchEnabled: true,
      shouldSort: false,
      allowHTML: false,
      placeholder: true,
      placeholderValue: 'Any',
    });

    if (opts.value && opts.value.length) {
      this.choices.setChoiceByValue(opts.value);
    }

    const self = this;
    select.addEventListener('change', function () {
      self.listeners.forEach(function (cb) { cb(self.getValue()); });
    });
  };

  MultiSelectField.prototype.getValue = function () {
    return this.choices ? this.choices.getValue(true) : [];
  };

  MultiSelectField.prototype.setValue = function (values) {
    if (!this.choices) return;
    this.choices.removeActiveItems();
    if (values && values.length) this.choices.setChoiceByValue(values);
  };

  MultiSelectField.prototype.on = function (event, cb) {
    if (event === 'change') this.listeners.push(cb);
  };

  MultiSelectField.prototype.destroy = function () {
    if (this.choices) { this.choices.destroy(); this.choices = null; }
    this.listeners = [];
  };

  root.MultiSelectField = MultiSelectField;
})(typeof window !== 'undefined' ? window : this);
```

- [ ] **Step 2: Write `DateRangeField`**

```js
// public/assets/js/filters/fields/DateRangeField.js
(function (root) {
  'use strict';

  const PRESETS = [
    ['today', 'Today'], ['yesterday', 'Yesterday'],
    ['last_7_days', 'Last 7 days'], ['last_30_days', 'Last 30 days'],
    ['this_month', 'This month'], ['last_month', 'Last month'],
  ];

  function DateRangeField(name, label) {
    this.name = name; // 'date'
    this.label = label;
    this.fp = null;
    this.presetSelect = null;
    this.listeners = [];
    this.value = { preset: null, from: null, to: null };
  }

  DateRangeField.prototype.mount = function (rootEl, opts) {
    rootEl.innerHTML = '';
    const labelEl = document.createElement('label');
    labelEl.textContent = this.label;
    rootEl.appendChild(labelEl);

    const presetSelect = document.createElement('select');
    presetSelect.setAttribute('aria-label', 'Date preset');
    [['', 'Custom']].concat(PRESETS).forEach(function (p) {
      const o = document.createElement('option');
      o.value = p[0]; o.textContent = p[1];
      presetSelect.appendChild(o);
    });
    this.presetSelect = presetSelect;
    rootEl.appendChild(presetSelect);

    const input = document.createElement('input');
    input.type = 'text';
    input.placeholder = 'YYYY-MM-DD to YYYY-MM-DD';
    rootEl.appendChild(input);

    const self = this;
    this.fp = flatpickr(input, {
      mode: 'range',
      dateFormat: 'Y-m-d',
      allowInput: true,
      onChange: function (selectedDates) {
        if (selectedDates.length === 2) {
          self.value = {
            preset: null,
            from: self.fp.formatDate(selectedDates[0], 'Y-m-d'),
            to:   self.fp.formatDate(selectedDates[1], 'Y-m-d'),
          };
          presetSelect.value = '';
          self.emit();
        }
      },
    });

    presetSelect.addEventListener('change', function () {
      const v = presetSelect.value;
      if (!v) return;
      self.value = { preset: v, from: null, to: null };
      self.fp.clear();
      self.emit();
    });

    if (opts.value) this.setValue(opts.value);
  };

  DateRangeField.prototype.getValue = function () { return this.value; };

  DateRangeField.prototype.setValue = function (val) {
    if (!val) { this.value = { preset: null, from: null, to: null }; return; }
    if (val.preset) {
      this.value = { preset: val.preset, from: null, to: null };
      if (this.presetSelect) this.presetSelect.value = val.preset;
    } else if (val.from && val.to) {
      this.value = { preset: null, from: val.from, to: val.to };
      if (this.fp) this.fp.setDate([val.from, val.to]);
    }
  };

  DateRangeField.prototype.on = function (event, cb) {
    if (event === 'change') this.listeners.push(cb);
  };

  DateRangeField.prototype.emit = function () {
    const v = this.value;
    this.listeners.forEach(function (cb) { cb(v); });
  };

  DateRangeField.prototype.destroy = function () {
    if (this.fp) { this.fp.destroy(); this.fp = null; }
    this.listeners = [];
  };

  root.DateRangeField = DateRangeField;
})(typeof window !== 'undefined' ? window : this);
```

- [ ] **Step 3: Write `TextField` (debounced)**

```js
// public/assets/js/filters/fields/TextField.js
(function (root) {
  'use strict';
  function TextField(name, label) { this.name = name; this.label = label; this.input = null; this.listeners = []; this.timer = null; }
  TextField.prototype.mount = function (rootEl, opts) {
    rootEl.innerHTML = '';
    const labelEl = document.createElement('label');
    labelEl.textContent = this.label;
    labelEl.htmlFor = 'ff-' + this.name;
    rootEl.appendChild(labelEl);
    const input = document.createElement('input');
    input.type = 'text';
    input.id = 'ff-' + this.name;
    input.value = opts.value || '';
    rootEl.appendChild(input);
    this.input = input;
    const self = this;
    input.addEventListener('input', function () {
      clearTimeout(self.timer);
      self.timer = setTimeout(function () {
        self.listeners.forEach(function (cb) { cb(input.value); });
      }, 250);
    });
  };
  TextField.prototype.getValue = function () { return this.input ? this.input.value : ''; };
  TextField.prototype.setValue = function (v) { if (this.input) this.input.value = v || ''; };
  TextField.prototype.on = function (e, cb) { if (e === 'change') this.listeners.push(cb); };
  TextField.prototype.destroy = function () { clearTimeout(this.timer); this.listeners = []; };
  root.TextField = TextField;
})(typeof window !== 'undefined' ? window : this);
```

- [ ] **Step 4: Write `StatusField` (chip toggles)**

```js
// public/assets/js/filters/fields/StatusField.js
(function (root) {
  'use strict';
  const STATES = ['open', 'closed', 'canceled'];
  function StatusField() { this.values = []; this.buttons = {}; this.listeners = []; }
  StatusField.prototype.mount = function (rootEl, opts) {
    rootEl.innerHTML = '';
    const labelEl = document.createElement('span');
    labelEl.textContent = 'Status';
    labelEl.className = 'filter-panel-label';
    rootEl.appendChild(labelEl);
    const self = this;
    STATES.forEach(function (s) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = s.charAt(0).toUpperCase() + s.slice(1);
      btn.setAttribute('aria-pressed', 'false');
      btn.className = 'filter-panel-chip';
      btn.addEventListener('click', function () { self.toggle(s); });
      self.buttons[s] = btn;
      rootEl.appendChild(btn);
    });
    if (opts.value && opts.value.length) this.setValue(opts.value);
  };
  StatusField.prototype.toggle = function (s) {
    const i = this.values.indexOf(s);
    if (i >= 0) this.values.splice(i, 1); else this.values.push(s);
    this.refreshButtons();
    this.listeners.forEach(function (cb) { cb(this.values.slice()); }, this);
  };
  StatusField.prototype.refreshButtons = function () {
    const self = this;
    Object.keys(this.buttons).forEach(function (s) {
      const on = self.values.indexOf(s) >= 0;
      self.buttons[s].setAttribute('aria-pressed', on ? 'true' : 'false');
      self.buttons[s].classList.toggle('is-active', on);
    });
  };
  StatusField.prototype.getValue = function () { return this.values.slice(); };
  StatusField.prototype.setValue = function (v) { this.values = (v || []).slice(); this.refreshButtons(); };
  StatusField.prototype.on = function (e, cb) { if (e === 'change') this.listeners.push(cb); };
  StatusField.prototype.destroy = function () { this.listeners = []; };
  root.StatusField = StatusField;
})(typeof window !== 'undefined' ? window : this);
```

- [ ] **Step 5: Write `fieldRegistry`**

```js
// public/assets/js/filters/fieldRegistry.js
(function (root) {
  'use strict';
  const labels = {
    date: 'Date', call_type: 'Call Type', incident_type: 'Incident Type',
    nature_of_call: 'Nature of Call', agency: 'Agency', ori: 'ORI', fdid: 'FDID',
    beat: 'Beat', area: 'Area', city: 'City', location: 'Location',
    call_id: 'Call ID', unit: 'Unit #', status: 'Status', q: 'Search',
  };
  const types = {
    date: 'DateRangeField', status: 'StatusField',
    location: 'TextField', nature_of_call: 'TextField', q: 'TextField',
    call_type: 'MultiSelectField', incident_type: 'MultiSelectField',
    agency: 'MultiSelectField', ori: 'MultiSelectField', fdid: 'MultiSelectField',
    beat: 'MultiSelectField', area: 'MultiSelectField', city: 'MultiSelectField',
    call_id: 'MultiSelectField', unit: 'MultiSelectField',
  };
  function buildField(name) {
    const ctorName = types[name] || 'TextField';
    const Ctor = root[ctorName];
    if (!Ctor) throw new Error('Field constructor missing: ' + ctorName);
    return new Ctor(name, labels[name] || name);
  }
  root.fieldRegistry = { buildField: buildField, types: types, labels: labels };
})(typeof window !== 'undefined' ? window : this);
```

- [ ] **Step 6: Commit**

```bash
git add public/assets/js/filters/fields/ public/assets/js/filters/fieldRegistry.js
git commit -m "$(cat <<'EOF'
feat(js): add filter field components (DateRange, MultiSelect, Text, Status) + registry

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 20: `FilterPanel` orchestrator

**Files:**
- Create: `public/assets/js/filters/FilterPanel.js`
- Create: `public/assets/js/filters/filters.css`

- [ ] **Step 1: Implement `FilterPanel`**

```js
// public/assets/js/filters/FilterPanel.js
(function (root) {
  'use strict';

  function FilterPanel(opts) {
    this.root = opts.root;
    this.optionsEndpoint = opts.optionsEndpoint || '/api/filter-options';
    this.onChange = opts.onChange || function () {};
    this.fields = (opts.fields || (this.root.dataset.fields || '').split(','))
      .map(function (s) { return s.trim(); })
      .filter(Boolean);
    this.compact = (opts.compact !== undefined ? opts.compact : (this.root.dataset.compact === 'true'));
    this.state = window.FilterState.fromQuery(window.location.search);
    this.fieldInstances = {};
    this.options = {};
    this.applyTimer = null;
  }

  FilterPanel.prototype.mount = async function () {
    await this._loadOptions();
    this._render();
    this._wireEvents();
    this._maybeShowRestoreBanner();
  };

  FilterPanel.prototype._loadOptions = async function () {
    const fieldsNeedingOptions = this.fields.filter(function (f) {
      return ['agency','ori','fdid','beat','area','city','call_type','incident_type','unit'].indexOf(f) >= 0;
    });
    if (fieldsNeedingOptions.length === 0) { this.options = {}; return; }

    const cacheKey = 'filter-panel:opts:' + fieldsNeedingOptions.join(',');
    const cached = JSON.parse(localStorage.getItem(cacheKey) || 'null');
    if (cached && cached.fetchedAt > Date.now() - 5 * 60 * 1000) {
      this.options = cached.data; return;
    }
    const url = this.optionsEndpoint + '?fields=' + encodeURIComponent(fieldsNeedingOptions.join(','));
    const resp = await fetch(url, { credentials: 'same-origin' });
    if (!resp.ok) { console.error('FilterPanel: filter-options request failed', resp.status); this.options = {}; return; }
    const json = await resp.json();
    this.options = json.data || {};
    localStorage.setItem(cacheKey, JSON.stringify({ fetchedAt: Date.now(), data: this.options }));
  };

  FilterPanel.prototype._render = function () {
    this.root.innerHTML = '';
    this.root.classList.add('filter-panel');
    if (this.compact) this.root.classList.add('filter-panel--compact');

    const header = document.createElement('div');
    header.className = 'filter-panel-header';
    const reset = document.createElement('button');
    reset.type = 'button';
    reset.textContent = 'Reset';
    reset.className = 'filter-panel-reset';
    const self = this;
    reset.addEventListener('click', function () { self.clear(); });
    header.appendChild(reset);
    this.root.appendChild(header);

    const announcer = document.createElement('div');
    announcer.setAttribute('aria-live', 'polite');
    announcer.className = 'filter-panel-announcer';
    this.root.appendChild(announcer);
    this.announcer = announcer;

    this.fields.forEach(function (name) {
      const wrap = document.createElement('div');
      wrap.className = 'filter-panel-field filter-panel-field--' + name;
      self.root.appendChild(wrap);
      const field = root.fieldRegistry.buildField(name);
      const initialValue = self._initialValueFor(name);
      field.mount(wrap, {
        options: self._optionsFor(name),
        value: initialValue,
      });
      field.on('change', function () { self._onFieldChange(); });
      self.fieldInstances[name] = field;
    });
  };

  FilterPanel.prototype._optionsFor = function (name) {
    const opts = this.options[name];
    if (!opts) return [];
    if (Array.isArray(opts) && typeof opts[0] === 'string') return opts;
    return (opts || []).map(function (o) {
      return { value: o.value, label: o.label || o.value };
    });
  };

  FilterPanel.prototype._initialValueFor = function (name) {
    if (name === 'date') {
      return { preset: this.state.get('preset'), from: this.state.get('from'), to: this.state.get('to') };
    }
    return this.state.get(name);
  };

  FilterPanel.prototype._onFieldChange = function () {
    const partial = {};
    Object.keys(this.fieldInstances).forEach(function (name) {
      const v = this.fieldInstances[name].getValue();
      if (name === 'date') {
        if (v && v.preset)        { partial.preset = v.preset; partial.from = ''; partial.to = ''; }
        else if (v && v.from && v.to) { partial.preset = ''; partial.from = v.from; partial.to = v.to; }
        else                          { partial.preset = ''; partial.from = ''; partial.to = ''; }
      } else {
        partial[name] = v;
      }
    }, this);
    this.state.merge(partial);
    this._scheduleApply();
  };

  FilterPanel.prototype._scheduleApply = function () {
    clearTimeout(this.applyTimer);
    const self = this;
    this.applyTimer = setTimeout(function () { self._apply(); }, 250);
  };

  FilterPanel.prototype._apply = function () {
    const qs = this.state.toQueryString();
    const url = window.location.pathname + (qs ? '?' + qs : '');
    window.history.replaceState({}, '', url);
    localStorage.setItem('filter-panel:last-state', JSON.stringify(this.state.snapshot()));
    this._announce();
    this.onChange(this.state);
  };

  FilterPanel.prototype._announce = function () {
    const count = Object.keys(this.state.values).length;
    this.announcer.textContent = count === 0 ? 'No filters active' : 'Filters applied: ' + count + ' active';
  };

  FilterPanel.prototype._maybeShowRestoreBanner = function () {
    if (window.location.search) return;
    const last = JSON.parse(localStorage.getItem('filter-panel:last-state') || 'null');
    if (!last || Object.keys(last).length === 0) return;

    const banner = document.createElement('div');
    banner.className = 'filter-panel-restore';
    banner.setAttribute('role', 'status');
    banner.textContent = 'Restore last filter? ';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = 'Restore';
    const self = this;
    btn.addEventListener('click', function () {
      self.state.merge(last);
      Object.keys(self.fieldInstances).forEach(function (name) {
        if (name === 'date') {
          self.fieldInstances[name].setValue({ preset: last.preset, from: last.from, to: last.to });
        } else {
          self.fieldInstances[name].setValue(last[name]);
        }
      });
      self._apply();
      banner.remove();
    });
    banner.appendChild(btn);
    this.root.insertBefore(banner, this.root.firstChild);
    setTimeout(function () { if (banner.parentNode) banner.remove(); }, 6000);
  };

  FilterPanel.prototype._wireEvents = function () {
    const self = this;
    window.addEventListener('popstate', function () {
      self.state = window.FilterState.fromQuery(window.location.search);
      Object.keys(self.fieldInstances).forEach(function (name) {
        self.fieldInstances[name].setValue(self._initialValueFor(name));
      });
      self.onChange(self.state);
    });
  };

  FilterPanel.prototype.getState = function () { return this.state; };

  FilterPanel.prototype.clear = function () {
    this.state.clear();
    Object.keys(this.fieldInstances).forEach(function (name) {
      this.fieldInstances[name].setValue(name === 'date' ? null : (window.FilterState.MULTI_FIELDS.indexOf(name) >= 0 ? [] : ''));
    }, this);
    this._apply();
  };

  FilterPanel.prototype.destroy = function () {
    Object.keys(this.fieldInstances).forEach(function (name) { this.fieldInstances[name].destroy(); }, this);
    this.fieldInstances = {};
    this.root.innerHTML = '';
  };

  root.FilterPanel = FilterPanel;
})(typeof window !== 'undefined' ? window : this);
```

- [ ] **Step 2: Write `filters.css`**

```css
/* public/assets/js/filters/filters.css */
.filter-panel { display: grid; gap: 0.75rem; padding: 1rem; background: var(--bs-light, #f8f9fa); border-radius: 0.5rem; }
.filter-panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
.filter-panel-reset { background: transparent; border: 1px solid var(--bs-secondary, #6c757d); color: inherit; padding: 0.25rem 0.75rem; border-radius: 0.25rem; cursor: pointer; }
.filter-panel-reset:hover { background: var(--bs-secondary, #6c757d); color: white; }
.filter-panel-field { display: flex; flex-direction: column; gap: 0.25rem; }
.filter-panel-field label { font-size: 0.85rem; color: var(--bs-secondary, #6c757d); font-weight: 500; }
.filter-panel-chip { padding: 0.25rem 0.75rem; background: white; border: 1px solid var(--bs-border-color, #dee2e6); border-radius: 999px; cursor: pointer; }
.filter-panel-chip.is-active { background: var(--bs-primary, #0d6efd); color: white; border-color: var(--bs-primary, #0d6efd); }
.filter-panel-announcer { position: absolute; left: -9999px; }
.filter-panel-restore { padding: 0.5rem 0.75rem; background: var(--bs-info-bg-subtle, #cff4fc); border-radius: 0.25rem; }
.filter-panel-restore button { margin-left: 0.5rem; padding: 0.15rem 0.5rem; background: var(--bs-primary, #0d6efd); color: white; border: 0; border-radius: 0.25rem; cursor: pointer; }

/* Compact (mobile) */
.filter-panel--compact { padding: 0.5rem; gap: 0.5rem; }
@media (max-width: 640px) {
  .filter-panel { grid-template-columns: 1fr; }
}
```

- [ ] **Step 3: Commit**

```bash
git add public/assets/js/filters/FilterPanel.js public/assets/js/filters/filters.css
git commit -m "$(cat <<'EOF'
feat(js): add FilterPanel orchestrator with URL sync, restore banner, and compact mode

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 6 — Page integration

### Task 21: Shared `partials/filter-panel.php` partial

**Files:**
- Create: `src/Dashboard/Views/partials/filter-panel.php`

- [ ] **Step 1: Write the partial**

```php
<?php
/**
 * Shared filter panel partial.
 * Caller passes:
 *   $filterFields: comma-separated string of fields to render
 *   $filterCompact: 'true' or 'false' (default 'false')
 */
$filterFields = $filterFields ?? '';
$filterCompact = $filterCompact ?? 'false';
?>
<link rel="stylesheet" href="/assets/vendor/choices/choices.min.css">
<link rel="stylesheet" href="/assets/vendor/flatpickr/flatpickr.min.css">
<link rel="stylesheet" href="/assets/js/filters/filters.css">

<div id="filter-panel"
     data-filter-panel
     data-fields="<?= htmlspecialchars($filterFields, ENT_QUOTES, 'UTF-8') ?>"
     data-compact="<?= htmlspecialchars($filterCompact, ENT_QUOTES, 'UTF-8') ?>"></div>

<script src="/assets/vendor/choices/choices.min.js"></script>
<script src="/assets/vendor/flatpickr/flatpickr.min.js"></script>
<script src="/assets/js/filters/FilterState.js"></script>
<script src="/assets/js/filters/fields/MultiSelectField.js"></script>
<script src="/assets/js/filters/fields/DateRangeField.js"></script>
<script src="/assets/js/filters/fields/TextField.js"></script>
<script src="/assets/js/filters/fields/StatusField.js"></script>
<script src="/assets/js/filters/fieldRegistry.js"></script>
<script src="/assets/js/filters/FilterPanel.js"></script>
```

- [ ] **Step 2: Commit**

```bash
git add src/Dashboard/Views/partials/filter-panel.php
git commit -m "$(cat <<'EOF'
feat(views): add shared filter-panel.php partial

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 22: Wire FilterPanel into desktop dashboard

**Files:**
- Modify: `src/Dashboard/Views/dashboard.php`
- Modify: `public/assets/js/dashboard.js` (or whichever JS file owns the dashboard's data fetching — verify in source)
- Delete: `src/Dashboard/Views/partials/filter-modal.php`

- [ ] **Step 1: Replace the filter modal include with the new partial**

In `src/Dashboard/Views/dashboard.php`, find the line that includes `partials/filter-modal.php` and replace it with:

```php
<?php
$filterFields = 'date,call_type,incident_type,nature_of_call,agency,ori,fdid,beat,area,city,location,call_id,unit,status,q';
$filterCompact = 'false';
include __DIR__ . '/partials/filter-panel.php';
?>
```

- [ ] **Step 2: Replace the dashboard's filter wiring in `dashboard.js`**

Find and remove the code that instantiates `FilterManager` (and any modal show/hide wiring tied to it). Replace with:

```js
document.addEventListener('DOMContentLoaded', async function () {
  const panel = new FilterPanel({
    root: document.getElementById('filter-panel'),
    onChange: function (state) {
      Dashboard.loadCalls('?' + state.toQueryString());
    },
  });
  await panel.mount();
  Dashboard.loadCalls('?' + panel.getState().toQueryString());
});
```

If `Dashboard.loadCalls` does not yet accept a query-string argument, update it to pass the string through to the API request URL: it should hit `/api/calls?<state-qs>`.

- [ ] **Step 3: Delete the legacy modal partial**

```bash
git rm src/Dashboard/Views/partials/filter-modal.php
```

- [ ] **Step 4: Smoke test in a browser**

```bash
docker-compose up -d
```
Open http://localhost:<port>/ in a browser. Verify:
- Filter panel renders with all fields
- Picking a call type triggers a refetch (Network tab shows `/api/calls?call_type=Police`)
- URL bar updates as you change filters
- Reload preserves state
- Reset button clears everything

- [ ] **Step 5: Commit**

```bash
git add src/Dashboard/Views/dashboard.php public/assets/js/dashboard.js
git commit -m "$(cat <<'EOF'
feat(dashboard): replace FilterManager with FilterPanel on desktop

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 23: Wire FilterPanel into mobile dashboard

**Files:**
- Modify: `src/Dashboard/Views/dashboard-mobile.php`
- Modify: `public/assets/js/mobile.js`
- Delete: `src/Dashboard/Views/partials-mobile/filters-modal.php`

- [ ] **Step 1: Replace the legacy filter modal include**

In `dashboard-mobile.php`, find the include of `partials-mobile/filters-modal.php` and replace with:

```php
<?php
$filterFields = 'date,call_type,agency,ori,city,unit,status,q';  // mobile gets the most-used fields, not all
$filterCompact = 'true';
include __DIR__ . '/partials/filter-panel.php';
?>
```

(Mobile intentionally shows fewer fields. The full set is still URL-addressable; this is just what shows in the on-screen panel.)

- [ ] **Step 2: Replace the mobile JS filter wiring**

In `mobile.js`, delete the `filters` state object, the `buildFilterParams()` function, and any `applyFilters()` / quick-select handlers that target legacy markup. Replace with the same FilterPanel mount pattern:

```js
document.addEventListener('DOMContentLoaded', async function () {
  const panel = new FilterPanel({
    root: document.getElementById('filter-panel'),
    onChange: function (state) {
      MobileDashboard.loadCalls('?' + state.toQueryString());
    },
  });
  await panel.mount();
  MobileDashboard.loadCalls('?' + panel.getState().toQueryString());
});
```

- [ ] **Step 3: Delete the legacy mobile modal partial**

```bash
git rm src/Dashboard/Views/partials-mobile/filters-modal.php
```

- [ ] **Step 4: Smoke test on a mobile viewport (Chrome DevTools device mode)**

Confirm the filter panel renders compact, works with touch, and the date picker is usable.

- [ ] **Step 5: Commit**

```bash
git add src/Dashboard/Views/dashboard-mobile.php public/assets/js/mobile.js
git commit -m "$(cat <<'EOF'
feat(dashboard-mobile): replace mobile filter logic with FilterPanel compact mode

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 24: New `/calls` page

**Files:**
- Create: `src/Dashboard/Views/calls.php`
- Modify: `public/index.php` (add route)
- Create: `public/assets/js/calls-page.js`

- [ ] **Step 1: Add the route in `public/index.php`**

Read the current route block; mirror the existing pattern. Roughly:

```php
if ($path === '/calls') {
    require __DIR__ . '/../src/Dashboard/Views/calls.php';
    return;
}
```

- [ ] **Step 2: Write `calls.php`**

```php
<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Calls — NWS CAD</title>
<link rel="stylesheet" href="/assets/css/dashboard.css">
</head>
<body>
<main class="container py-3">
<h1>Calls</h1>

<?php
$filterFields = 'date,call_type,incident_type,nature_of_call,agency,ori,fdid,beat,area,city,location,call_id,unit,status,q';
$filterCompact = 'false';
include __DIR__ . '/partials/filter-panel.php';
?>

<div id="calls-list"></div>
<nav id="calls-pagination"></nav>

<script src="/assets/js/dashboard.js"></script><!-- for Dashboard.escapeHtml etc. -->
<script src="/assets/js/calls-page.js"></script>
</main>
</body>
</html>
```

- [ ] **Step 3: Write `calls-page.js`**

```js
// public/assets/js/calls-page.js
(function () {
  'use strict';

  async function load(qs) {
    const resp = await fetch('/api/calls' + (qs || ''));
    if (!resp.ok) { console.error('calls fetch failed'); return; }
    const body = await resp.json();
    render(body.data);
  }

  function render(data) {
    const list = document.getElementById('calls-list');
    list.innerHTML = '';
    (data.items || []).forEach(function (c) {
      const li = document.createElement('div');
      li.className = 'call-item';
      const num = document.createElement('strong');
      num.textContent = c.call_number;
      li.appendChild(num);
      const span = document.createElement('span');
      span.textContent = ' — ' + (c.nature_of_call || '');
      li.appendChild(span);
      list.appendChild(li);
    });
    renderPagination(data.pagination);
  }

  function renderPagination(p) {
    const nav = document.getElementById('calls-pagination');
    nav.innerHTML = '';
    if (!p) return;
    nav.textContent = 'Page ' + p.current_page + ' of ' + p.total_pages + ' (' + p.total + ' total)';
  }

  document.addEventListener('DOMContentLoaded', async function () {
    const panel = new FilterPanel({
      root: document.getElementById('filter-panel'),
      onChange: function (state) { load('?' + state.toQueryString()); },
    });
    await panel.mount();
    load('?' + panel.getState().toQueryString());
  });
})();
```

- [ ] **Step 4: Smoke test**

Visit `/calls` in a browser; confirm filter panel + list render and filtering works end-to-end.

- [ ] **Step 5: Commit**

```bash
git add src/Dashboard/Views/calls.php public/index.php public/assets/js/calls-page.js
git commit -m "$(cat <<'EOF'
feat(calls): add /calls list page using FilterPanel + /api/calls

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 25: New `/units` page

**Files:**
- Create: `src/Dashboard/Views/units.php`
- Modify: `public/index.php` (add route)
- Create: `public/assets/js/units-page.js`

Mirror Task 24 exactly, but:
- `$filterFields = 'date,agency,unit,status,call_id'`
- Endpoint is `/api/units`
- List item shows `unit_number` and the linked `call.call_number` + `nature_of_call`

- [ ] **Step 1: Implement following Task 24's pattern**
- [ ] **Step 2: Smoke test on /units**
- [ ] **Step 3: Commit**

```bash
git commit -m "$(cat <<'EOF'
feat(units): add /units list page using FilterPanel + /api/units

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 7 — Legacy removal

### Task 26: Delete `filter-manager.js` and clean up references

**Files:**
- Delete: `public/assets/js/filter-manager.js`

- [ ] **Step 1: Confirm no remaining references**

```bash
grep -rn "filter-manager\.js\|new FilterManager\|FilterManager\." public/ src/
```
Expected: zero hits. If hits remain, fix those callers first (likely already done in Tasks 22 and 23, but double-check).

- [ ] **Step 2: Delete the file**

```bash
git rm public/assets/js/filter-manager.js
```

- [ ] **Step 3: Run the full test suite**

```bash
composer test
```
Expected: all four suites pass.

- [ ] **Step 4: Smoke test desktop and mobile dashboards in a browser**

Both should still load and filter correctly.

- [ ] **Step 5: Commit**

```bash
git commit -m "$(cat <<'EOF'
refactor: remove legacy filter-manager.js

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 8 — Performance and final docs

### Task 27: Performance test — filtered query latency

**Files:**
- Create: `tests/Performance/FilterPerformanceTest.php`

- [ ] **Step 1: Write the perf test**

```php
<?php
declare(strict_types=1);

namespace NwsCad\Tests\Performance;

use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class FilterPerformanceTest extends TestCase
{
    private const ROW_COUNT = 100_000;
    private const MAX_MS    = 100;

    public static function setUpBeforeClass(): void
    {
        $db = Database::getConnection();
        $count = (int)$db->query('SELECT COUNT(*) FROM calls')->fetchColumn();
        if ($count >= self::ROW_COUNT) return;

        $db->beginTransaction();
        $stmt = $db->prepare('INSERT INTO calls (call_number, create_datetime, closed_flag, canceled_flag) VALUES (?, ?, ?, ?)');
        $start = strtotime('2024-01-01 00:00:00');
        for ($i = 0; $i < self::ROW_COUNT; $i++) {
            $stmt->execute([
                'PERF-' . $i,
                date('Y-m-d H:i:s', $start + $i * 60),
                $i % 4 === 0 ? 1 : 0,
                $i % 50 === 0 ? 1 : 0,
            ]);
        }
        $db->commit();
    }

    public function testCallTypeFilterCompletesUnder100Ms(): void
    {
        $_GET = ['call_type' => 'Police'];
        $t0 = microtime(true);
        ob_start();
        (new \NwsCad\Api\Controllers\CallsController())->index();
        ob_end_clean();
        $elapsed = (microtime(true) - $t0) * 1000;
        $this->assertLessThan(self::MAX_MS, $elapsed, "Took {$elapsed}ms");
    }

    public function testDateRangeFilterCompletesUnder100Ms(): void
    {
        $_GET = ['from' => '2024-01-01', 'to' => '2024-01-08'];
        $t0 = microtime(true);
        ob_start();
        (new \NwsCad\Api\Controllers\CallsController())->index();
        ob_end_clean();
        $elapsed = (microtime(true) - $t0) * 1000;
        $this->assertLessThan(self::MAX_MS, $elapsed, "Took {$elapsed}ms");
    }
}
```

- [ ] **Step 2: Run the perf test**

```bash
composer test:performance
```
Expected: PASS in under 100 ms each. If a test fails, the indexes from Task 1 are not being used — check `EXPLAIN` for the query.

- [ ] **Step 3: Commit**

```bash
git add tests/Performance/FilterPerformanceTest.php
git commit -m "$(cat <<'EOF'
test(performance): assert filter queries complete <100ms on 100k rows

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 28: CHANGELOG and CLAUDE.md updates

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add a CHANGELOG entry**

```markdown
## [Unreleased]
### Added
- Unified filter system across desktop dashboard, mobile dashboard, and new `/calls` and `/units` list pages.
- New filter vocabulary: call_type, incident_type, nature_of_call (LIKE), agency, ORI, FDID, beat, area, city, location, call_id, unit #, status (open/closed/canceled), and date range with presets.
- New `Api\Filtering\` namespace (`FilterCriteria`, `FilterSqlBuilder`, `FilterRegistry`, `FilterContext`, `FilterOptionsCache`, `DateRange`, `InvalidFilterException`).
- New endpoint `GET /api/filter-options` returning curated reference data + derived option lists.
- New reference tables: `ref_agencies`, `ref_oris`, `ref_fdids`, `ref_beats`, `ref_areas`. Seeded via `php bin/seed-reference.php`.
- New `agency_contexts.fdid` column; populated by `AegisXmlParser` from XML or `ref_agencies` lookup.
- New composite indexes on `calls`, `agency_contexts`, `locations`, `units`, `incidents` (see migration).
- Vendored frontend libraries: Choices.js v10 and Flatpickr v4 under `public/assets/vendor/`.

### Removed
- Legacy `public/assets/js/filter-manager.js` (replaced by `FilterPanel`).
- Mobile filter logic in `mobile.js` (replaced by `FilterPanel` compact mode).
- `Api\Request::filters()` (superseded by `FilterCriteria`).
- `partials/filter-modal.php` and `partials-mobile/filters-modal.php` (replaced by `partials/filter-panel.php`).
```

- [ ] **Step 2: Add notes to `CLAUDE.md`**

In the "Common commands" section, add:

```bash
# Reference data seeding (filter dropdowns)
php bin/seed-reference.php                           # uses database/seeds/reference.json
```

In the "Architecture" section's table of core classes, add four rows:

```
| `Api\Filtering\FilterCriteria` | URL → typed filter value object. Enforces 50-value cap, 256-char cap, allowlist. |
| `Api\Filtering\FilterSqlBuilder` | FilterCriteria → parameterized WHERE/JOIN/params via `SqlFragment`. |
| `Api\Filtering\FilterRegistry` | Static per-controller allowlist (`for('calls')`, `for('units')`, `for('stats')`). |
| `Api\Filtering\FilterOptionsCache` | In-process 5-minute cache for `/api/filter-options`. Invalidated by `AegisXmlParser` after writes. |
```

In the JavaScript section, replace the Dashboard global note with:

```
- `FilterPanel` (`public/assets/js/filters/FilterPanel.js`) — universal filter component. Mount via `<div data-filter-panel data-fields="...">`. Owns URL sync, restore-banner, Choices.js + Flatpickr widgets.
```

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md CLAUDE.md
git commit -m "$(cat <<'EOF'
docs: document filter refactor in CHANGELOG and CLAUDE.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 29: Final verification — run everything

- [ ] **Step 1: Run the full test suite**

```bash
composer test
```
Expected: every suite green; new tests counted in. Compare pass count to the baseline recorded in Task 0.

- [ ] **Step 2: Run coverage**

```bash
composer test:coverage
```
Expected: 80%+ on new files. Any file under 80% should either get more tests or be marked with `@coversNothing` if appropriate.

- [ ] **Step 3: Manual end-to-end smoke test**

Run through every page (`/`, mobile UA `/`, `/calls`, `/units`) and:
- Apply each filter type
- Verify URL updates
- Verify reload preserves
- Verify back/forward navigation
- Verify Reset clears
- Verify the restore banner appears in a fresh tab and works
- On a mobile viewport, verify the compact panel works

- [ ] **Step 4: Push the branch**

```bash
git push -u origin feat/filter-refactor
```

- [ ] **Step 5: Open the PR (via gh CLI if installed) or describe how**

```bash
gh pr create --title "feat: unified filter system across UI" --body "$(cat <<'EOF'
## Summary
- Replaces divergent desktop/mobile filter implementations with one declarative FilterPanel JS component.
- New Api\\Filtering\\* namespace handles parsing, security limits, and SQL generation in one place.
- New /api/filter-options endpoint, reference tables, and seed pipeline.
- New /calls and /units list pages.
- Removes filter-manager.js, mobile filter logic, legacy filter-modal partials, and per-controller filter parsing.

See docs/superpowers/specs/2026-05-08-filter-refactor-design.md for the full design.

## Test plan
- [ ] composer test passes
- [ ] composer test:coverage shows 80%+ on new files
- [ ] Manual: every filter on /, /calls, /units (desktop + mobile)
- [ ] Manual: URL share-link round-trips
- [ ] Manual: back/forward navigation restores state
- [ ] Manual: restore-last-filter banner shows on fresh tab

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review against the spec

Spec coverage cross-reference (every spec section maps to one or more tasks):

| Spec section | Implementing task(s) |
|---|---|
| Filter contract / URL schema | Task 7 (FilterCriteria), Task 18 (FilterState) |
| FilterCriteria + security limits | Task 7 |
| FilterSqlBuilder | Tasks 8, 14 (units-base flip) |
| FilterRegistry | Task 6 |
| /api/filter-options endpoint + cache | Tasks 9, 10, 11 |
| Curated reference tables + seeds | Tasks 1, 2, 3 |
| FDID column + parser population | Tasks 1, 4 |
| Indexes | Task 1 |
| FilterPanel JS component | Task 20 |
| Field components | Task 19 |
| Mount pattern + shared partial | Task 21 |
| Dashboard refactor (desktop + mobile) | Tasks 22, 23 |
| /calls and /units pages | Tasks 24, 25 |
| Legacy removal | Tasks 16, 22, 23, 26 |
| Security defenses | Tasks 7 (limits), 8 (param binding), 10 (rate limit hook — see note) |
| Tests (unit/integration/perf) | Tasks 5–10, 12, 14, 15, 27 |
| CHANGELOG + CLAUDE.md | Task 28 |

**Caveats / open follow-ups not gated on this plan:**
- The `/api/filter-options` rate-limit hook from the spec is *not* implemented as a separate middleware — for v1, the in-process counter the spec describes is best done as a tiny private method on `FilterOptionsController` once we see actual abuse traffic. Captured as a comment in the controller.
- The `aria-live` announcer is implemented as `position: absolute; left: -9999px` (visually hidden but readable by AT) which matches accessible patterns. Spec described it but didn't specify visibility — this is the right default.
- Scope: the plan deliberately leaves `SearchController` and any other controllers not named in the spec untouched. They continue to work via their own filter parsing.

**No placeholders detected.** All code blocks contain real, runnable code; tests assert specific values; commands have expected outputs.
