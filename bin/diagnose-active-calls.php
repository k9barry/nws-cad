#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Diagnostic: list every call the dashboard counts as "active" and how
 * stale it is. Helps decide whether the 61-active-calls figure reflects
 * (a) genuinely in-flight calls, (b) calls upstream CAD never sent a
 * ClosedFlag=true XML for, or (c) calls whose closing XML never reached
 * the watcher.
 *
 * Active = closed_flag = 0 AND canceled_flag = 0 (matches StatsController).
 *
 * Usage:
 *   php bin/diagnose-active-calls.php           # full listing + summary
 *   php bin/diagnose-active-calls.php --summary # buckets only
 */

require_once __DIR__ . '/../vendor/autoload.php';

use NwsCad\Database;

$args = $_SERVER['argv'] ?? [];
array_shift($args);
$summaryOnly = in_array('--summary', $args, true);

$db = Database::getConnection();
$dbType = Database::getDbType();

$ageExpr = $dbType === 'mysql'
    ? 'TIMESTAMPDIFF(HOUR, updated_at, NOW())'
    : 'EXTRACT(EPOCH FROM (NOW() - updated_at)) / 3600';

$sql = "
    SELECT
        id,
        call_id,
        call_number,
        create_datetime,
        updated_at,
        closed_flag,
        canceled_flag,
        {$ageExpr} AS hours_since_update
    FROM calls
    WHERE closed_flag = 0 AND canceled_flag = 0
    ORDER BY updated_at DESC
";

$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$total = count($rows);

echo "Active calls (closed_flag=0 AND canceled_flag=0): {$total}\n";
echo str_repeat('-', 78) . "\n";

if (!$summaryOnly && $total > 0) {
    printf("%-7s %-12s %-14s %-20s %-20s %s\n",
        'id', 'call_id', 'call_number', 'create_datetime', 'updated_at', 'hrs');
    foreach ($rows as $r) {
        printf("%-7d %-12s %-14s %-20s %-20s %s\n",
            (int) $r['id'],
            (string) ($r['call_id'] ?? ''),
            (string) ($r['call_number'] ?? ''),
            (string) ($r['create_datetime'] ?? ''),
            (string) ($r['updated_at'] ?? ''),
            number_format((float) $r['hours_since_update'], 1)
        );
    }
    echo "\n";
}

$buckets = [
    '< 1 hour'       => 0,
    '1-6 hours'      => 0,
    '6-24 hours'     => 0,
    '1-7 days'       => 0,
    '7-30 days'      => 0,
    '> 30 days'      => 0,
];
foreach ($rows as $r) {
    $h = (float) $r['hours_since_update'];
    if      ($h <  1)        $buckets['< 1 hour']++;
    elseif  ($h <  6)        $buckets['1-6 hours']++;
    elseif  ($h < 24)        $buckets['6-24 hours']++;
    elseif  ($h < 24 *  7)   $buckets['1-7 days']++;
    elseif  ($h < 24 * 30)   $buckets['7-30 days']++;
    else                     $buckets['> 30 days']++;
}

echo "Staleness distribution (by updated_at):\n";
foreach ($buckets as $label => $n) {
    printf("  %-12s %5d  %s\n", $label, $n, str_repeat('#', $n));
}

$processedFiles = (int) $db->query(
    "SELECT COUNT(*) FROM processed_files WHERE status = 'success'"
)->fetchColumn();
echo "\nprocessed_files (success): {$processedFiles}\n";

$totalCalls = (int) $db->query("SELECT COUNT(*) FROM calls")->fetchColumn();
$closedCalls = (int) $db->query(
    "SELECT COUNT(*) FROM calls WHERE closed_flag = 1"
)->fetchColumn();
$canceledCalls = (int) $db->query(
    "SELECT COUNT(*) FROM calls WHERE canceled_flag = 1"
)->fetchColumn();
echo "calls total: {$totalCalls} (open: {$total}, closed: {$closedCalls}, canceled: {$canceledCalls})\n";
