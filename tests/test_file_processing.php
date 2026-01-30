#!/usr/bin/env php
<?php
/**
 * Test script to validate FilenameParser with real sample files
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use NwsCad\FilenameParser;

echo "=================================================================\n";
echo "         NWS CAD File Processing Optimization Test              \n";
echo "=================================================================\n\n";

// Get all XML files from samples directory
$samplesDir = __DIR__ . '/../samples';
$files = glob($samplesDir . '/*.xml');

if (empty($files)) {
    echo "❌ No XML files found in samples directory\n";
    exit(1);
}

echo "📁 Scanning samples directory: $samplesDir\n";
echo "📄 Found " . count($files) . " XML files\n\n";

// Extract just filenames
$filenames = array_map('basename', $files);

// Test parsing
echo "=================================================================\n";
echo "1. TESTING FILENAME PARSING\n";
echo "=================================================================\n\n";

$parseSuccessCount = 0;
$parseFailCount = 0;

foreach (array_slice($filenames, 0, 5) as $filename) {
    $parsed = FilenameParser::parse($filename);
    if ($parsed) {
        echo "✅ $filename\n";
        echo "   Call Number: {$parsed['call_number']}\n";
        echo "   Timestamp: {$parsed['timestamp']}\n";
        echo "   Timestamp Int: {$parsed['timestamp_int']}\n\n";
        $parseSuccessCount++;
    } else {
        echo "❌ Failed to parse: $filename\n\n";
        $parseFailCount++;
    }
}

echo "Summary: $parseSuccessCount successful, $parseFailCount failed\n\n";

// Test grouping
echo "=================================================================\n";
echo "2. TESTING FILE GROUPING BY CALL NUMBER\n";
echo "=================================================================\n\n";

$grouped = FilenameParser::groupByCallNumber($filenames);
echo "Found " . count($grouped) . " unique call numbers:\n\n";

foreach ($grouped as $callNumber => $files) {
    echo "📞 Call #$callNumber: " . count($files) . " version(s)\n";
    if (count($files) > 1) {
        echo "   Files: " . implode(', ', array_slice($files, 0, 3));
        if (count($files) > 3) {
            echo " ... and " . (count($files) - 3) . " more";
        }
        echo "\n";
    }
}

echo "\n";

// Test latest file selection
echo "=================================================================\n";
echo "3. TESTING LATEST FILE SELECTION\n";
echo "=================================================================\n\n";

$latestFiles = FilenameParser::getLatestFiles($filenames);
echo "Latest files (one per call): " . count($latestFiles) . "\n\n";

// Show comparison for calls with multiple versions
$callsWithMultiple = array_filter($grouped, function($files) {
    return count($files) > 1;
});

echo "Examples of latest selection for calls with multiple versions:\n\n";
$count = 0;
foreach ($callsWithMultiple as $callNumber => $files) {
    if ($count++ >= 5) break; // Show first 5
    
    echo "Call #$callNumber (" . count($files) . " files):\n";
    
    // Sort to show progression
    usort($files, function($a, $b) {
        return FilenameParser::compare($a, $b);
    });
    
    foreach ($files as $index => $file) {
        $parsed = FilenameParser::parse($file);
        $isLatest = in_array($file, $latestFiles);
        $marker = $isLatest ? " ← LATEST ✅" : "";
        echo "  " . ($index + 1) . ". {$parsed['timestamp']}$marker\n";
    }
    echo "\n";
}

// Test files to skip
echo "=================================================================\n";
echo "4. OPTIMIZATION SUMMARY\n";
echo "=================================================================\n\n";

$toSkip = FilenameParser::getFilesToSkip($filenames);
$toProcess = count($latestFiles);
$totalFiles = count($filenames);
$reduction = round((count($toSkip) / $totalFiles) * 100, 1);

echo "Total files in folder: $totalFiles\n";
echo "Files to process: $toProcess (latest versions only)\n";
echo "Files to skip: " . count($toSkip) . " (older versions)\n";
echo "Processing reduction: $reduction%\n\n";

echo "✅ Optimization will reduce processing by $reduction%!\n\n";

// Show detailed breakdown
echo "=================================================================\n";
echo "5. DETAILED CALL ANALYSIS\n";
echo "=================================================================\n\n";

echo "Calls by version count:\n";
$versionCounts = [];
foreach ($grouped as $callNumber => $files) {
    $count = count($files);
    if (!isset($versionCounts[$count])) {
        $versionCounts[$count] = 0;
    }
    $versionCounts[$count]++;
}

ksort($versionCounts);
foreach ($versionCounts as $versions => $callCount) {
    $plural = $versions > 1 ? 's' : '';
    echo "  $callCount call(s) with $versions version$plural\n";
}

echo "\n";
echo "=================================================================\n";
echo "                      TEST COMPLETED                             \n";
echo "=================================================================\n";
