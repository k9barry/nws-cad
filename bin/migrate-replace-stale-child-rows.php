#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phase-2 migration: replace the narratives-style content_hash dedupe with
 * upsert-by-natural-key for agency_contexts and incidents, and
 * delete-then-insert (driven by re-ingestion of archived XMLs) for
 * persons, vehicles and call_dispositions.
 *
 * Steps:
 *   1. Drop content_hash + uk_*_content from all 5 tables (idempotent).
 *   2. Dedupe agency_contexts: keep MAX(id) per (call_id, agency_type).
 *   3. Dedupe incidents: keep MAX(id) per (call_id, incident_number).
 *   4. Add UNIQUE (call_id, agency_type) on agency_contexts.
 *   5. Add UNIQUE (call_id, incident_number) on incidents.
 *   6. Truncate persons, vehicles, call_dispositions (re-ingest fills them).
 *   7. Clear processed_files for the active calls so the watcher will
 *      re-process their archived XMLs.
 *   8. Move each active call's archived XMLs from watch/processed/ back to
 *      watch/ for the watcher to pick up.
 *
 * Stop the watcher (docker stop nws-cad-app) BEFORE running.
 *
 * Usage:
 *   php bin/migrate-replace-stale-child-rows.php [--dry-run]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use NwsCad\Database;

$args = $_SERVER['argv'] ?? [];
array_shift($args);
$dryRun = in_array('--dry-run', $args, true);

$db = Database::getConnection();
if (Database::getDbType() !== 'mysql') {
    fwrite(STDERR, "MySQL only.\n");
    exit(2);
}

$dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
echo "Database: {$dbName}" . ($dryRun ? "  (DRY RUN)" : '') . "\n\n";

// ---------------------------------------------------------------------------
// 1. Drop legacy content_hash UNIQUEs and columns from all 5 tables.
// ---------------------------------------------------------------------------
$legacyConstraints = [
    'agency_contexts'    => 'uk_agency_contexts_content',
    'incidents'          => 'uk_incidents_content',
    'persons'            => 'uk_persons_content',
    'vehicles'           => 'uk_vehicles_content',
    'call_dispositions'  => 'uk_call_dispositions_content',
];
echo "── 1. Drop legacy content_hash artifacts ──\n";
foreach ($legacyConstraints as $table => $constraint) {
    if (constraintExists($db, $dbName, $table, $constraint)) {
        echo "  {$table}: DROP INDEX {$constraint}\n";
        if (!$dryRun) {
            $db->exec("ALTER TABLE {$table} DROP INDEX {$constraint}");
        }
    }
    if (columnExists($db, $dbName, $table, 'content_hash')) {
        echo "  {$table}: DROP COLUMN content_hash\n";
        if (!$dryRun) {
            $db->exec("ALTER TABLE {$table} DROP COLUMN content_hash");
        }
    }
}
echo "\n";

// ---------------------------------------------------------------------------
// 2 + 3. Dedupe agency_contexts / incidents to fit the new UNIQUE keys.
// ---------------------------------------------------------------------------
echo "── 2. Dedupe agency_contexts to (call_id, agency_type) ──\n";
$beforeAc = (int) $db->query("SELECT COUNT(*) FROM agency_contexts")->fetchColumn();
$dupAc = (int) $db->query(
    "SELECT COALESCE(SUM(c) - COUNT(*), 0) FROM (
       SELECT COUNT(*) c FROM agency_contexts
       WHERE agency_type IS NOT NULL
       GROUP BY call_id, agency_type
       HAVING COUNT(*) > 1
     ) t"
)->fetchColumn();
echo "  rows: {$beforeAc}, redundant snapshots to drop: {$dupAc}\n";
if ($dupAc > 0 && !$dryRun) {
    $db->exec(
        "DELETE t FROM agency_contexts t
         JOIN (
           SELECT call_id, agency_type, MAX(id) keep_id
           FROM agency_contexts
           WHERE agency_type IS NOT NULL
           GROUP BY call_id, agency_type
           HAVING COUNT(*) > 1
         ) k
           ON k.call_id = t.call_id
          AND k.agency_type = t.agency_type
          AND t.id <> k.keep_id"
    );
    $afterAc = (int) $db->query("SELECT COUNT(*) FROM agency_contexts")->fetchColumn();
    echo "  result: {$afterAc} rows\n";
}
echo "\n";

echo "── 3. Dedupe incidents to (call_id, incident_number) ──\n";
$beforeInc = (int) $db->query("SELECT COUNT(*) FROM incidents")->fetchColumn();
$dupInc = (int) $db->query(
    "SELECT COALESCE(SUM(c) - COUNT(*), 0) FROM (
       SELECT COUNT(*) c FROM incidents
       GROUP BY call_id, incident_number
       HAVING COUNT(*) > 1
     ) t"
)->fetchColumn();
echo "  rows: {$beforeInc}, redundant snapshots to drop: {$dupInc}\n";
if ($dupInc > 0 && !$dryRun) {
    $db->exec(
        "DELETE t FROM incidents t
         JOIN (
           SELECT call_id, incident_number, MAX(id) keep_id
           FROM incidents
           GROUP BY call_id, incident_number
           HAVING COUNT(*) > 1
         ) k
           ON k.call_id = t.call_id
          AND k.incident_number = t.incident_number
          AND t.id <> k.keep_id"
    );
    $afterInc = (int) $db->query("SELECT COUNT(*) FROM incidents")->fetchColumn();
    echo "  result: {$afterInc} rows\n";
}
echo "\n";

// ---------------------------------------------------------------------------
// 4 + 5. Add new natural-key UNIQUEs.
// ---------------------------------------------------------------------------
echo "── 4. Add UNIQUE uk_agency_contexts_call_agency ──\n";
if (constraintExists($db, $dbName, 'agency_contexts', 'uk_agency_contexts_call_agency')) {
    echo "  already exists\n";
} else {
    echo "  ADD UNIQUE KEY uk_agency_contexts_call_agency (call_id, agency_type)\n";
    if (!$dryRun) {
        $db->exec("ALTER TABLE agency_contexts ADD UNIQUE KEY uk_agency_contexts_call_agency (call_id, agency_type)");
    }
}
echo "\n";

echo "── 5. Add UNIQUE uk_incidents_call_number ──\n";
if (constraintExists($db, $dbName, 'incidents', 'uk_incidents_call_number')) {
    echo "  already exists\n";
} else {
    echo "  ADD UNIQUE KEY uk_incidents_call_number (call_id, incident_number)\n";
    if (!$dryRun) {
        $db->exec("ALTER TABLE incidents ADD UNIQUE KEY uk_incidents_call_number (call_id, incident_number)");
    }
}
echo "\n";

// ---------------------------------------------------------------------------
// 6. Truncate persons / vehicles / call_dispositions; re-ingest will refill.
// ---------------------------------------------------------------------------
echo "── 6. Empty persons / vehicles / call_dispositions for clean re-ingest ──\n";
foreach (['persons', 'vehicles', 'call_dispositions'] as $t) {
    $n = (int) $db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    echo "  {$t}: {$n} rows -> 0\n";
    if (!$dryRun) {
        $db->exec("DELETE FROM {$t}");
    }
}
echo "\n";

// ---------------------------------------------------------------------------
// 7. Identify the active calls and clear their processed_files entries.
// ---------------------------------------------------------------------------
echo "── 7. Reset processed_files for active calls ──\n";
$activeCallNumbers = $db->query(
    "SELECT DISTINCT call_number FROM calls WHERE call_number IS NOT NULL"
)->fetchAll(PDO::FETCH_COLUMN);
echo "  active calls: " . count($activeCallNumbers) . "\n";

$placeholders = implode(',', array_fill(0, count($activeCallNumbers), '?'));
$pfBefore = (int) $db->query("SELECT COUNT(*) FROM processed_files")->fetchColumn();
$pfActive = 0;
if ($activeCallNumbers) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM processed_files WHERE call_number IN ({$placeholders})");
    $stmt->execute($activeCallNumbers);
    $pfActive = (int) $stmt->fetchColumn();
}
echo "  processed_files rows for active calls: {$pfActive} (of {$pfBefore} total)\n";
if ($pfActive > 0 && !$dryRun) {
    $stmt = $db->prepare("DELETE FROM processed_files WHERE call_number IN ({$placeholders})");
    $stmt->execute($activeCallNumbers);
    echo "  cleared\n";
}
echo "\n";

// ---------------------------------------------------------------------------
// 8. Move archived XMLs for active calls back to watch/.
// ---------------------------------------------------------------------------
echo "── 8. Move archived XMLs for active calls back into watch/ ──\n";
$processedDir = '/var/www/var/watch/processed';
$watchDir     = '/var/www/var/watch';
if (!is_dir($processedDir) || !is_dir($watchDir)) {
    echo "  SKIP: processed/ or watch/ not present at expected paths (run inside the app container).\n\n";
} else {
    $moved = 0;
    foreach ($activeCallNumbers as $callNumber) {
        $matches = glob("{$processedDir}/{$callNumber}_*.xml") ?: [];
        foreach ($matches as $src) {
            $dst = $watchDir . '/' . basename($src);
            if ($dryRun) {
                $moved++;
                continue;
            }
            if (!@rename($src, $dst)) {
                fwrite(STDERR, "  rename failed: {$src} -> {$dst}\n");
                continue;
            }
            $moved++;
        }
    }
    echo "  XMLs queued for re-ingest: {$moved}\n\n";
}

echo $dryRun ? "Dry run complete.\n" : "Migration v2 complete. Restart the watcher.\n";
exit(0);


function columnExists(PDO $db, string $dbName, string $table, string $column): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = ? AND table_name = ? AND column_name = ?"
    );
    $stmt->execute([$dbName, $table, $column]);
    return (bool) $stmt->fetchColumn();
}

function constraintExists(PDO $db, string $dbName, string $table, string $constraint): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.statistics
         WHERE table_schema = ? AND table_name = ? AND index_name = ?"
    );
    $stmt->execute([$dbName, $table, $constraint]);
    return (bool) $stmt->fetchColumn();
}
