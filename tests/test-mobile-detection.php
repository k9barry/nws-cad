<?php
/**
 * Test Mobile Device Detection and Routing
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Jenssegers\Agent\Agent;

echo "=== Mobile Device Detection Test ===\n\n";

// Test 1: Mobile User Agent
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1';
$agent = new Agent();
echo "Test 1 - iPhone User Agent:\n";
echo "  User Agent: " . $_SERVER['HTTP_USER_AGENT'] . "\n";
echo "  Is Mobile: " . ($agent->isMobile() ? 'YES' : 'NO') . "\n";
echo "  Is Tablet: " . ($agent->isTablet() ? 'YES' : 'NO') . "\n";
echo "  Device: " . $agent->device() . "\n";
echo "  Platform: " . $agent->platform() . "\n";
echo "  Browser: " . $agent->browser() . "\n";
echo "  Should serve mobile view: " . (($agent->isMobile() || $agent->isTablet()) ? 'YES' : 'NO') . "\n\n";

// Test 2: Desktop User Agent
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
$agent = new Agent();
echo "Test 2 - Desktop Chrome User Agent:\n";
echo "  User Agent: " . $_SERVER['HTTP_USER_AGENT'] . "\n";
echo "  Is Mobile: " . ($agent->isMobile() ? 'YES' : 'NO') . "\n";
echo "  Is Tablet: " . ($agent->isTablet() ? 'YES' : 'NO') . "\n";
echo "  Device: " . $agent->device() . "\n";
echo "  Platform: " . $agent->platform() . "\n";
echo "  Browser: " . $agent->browser() . "\n";
echo "  Should serve mobile view: " . (($agent->isMobile() || $agent->isTablet()) ? 'YES' : 'NO') . "\n\n";

// Test 3: Tablet User Agent
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPad; CPU OS 14_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1';
$agent = new Agent();
echo "Test 3 - iPad User Agent:\n";
echo "  User Agent: " . $_SERVER['HTTP_USER_AGENT'] . "\n";
echo "  Is Mobile: " . ($agent->isMobile() ? 'YES' : 'NO') . "\n";
echo "  Is Tablet: " . ($agent->isTablet() ? 'YES' : 'NO') . "\n";
echo "  Device: " . $agent->device() . "\n";
echo "  Platform: " . $agent->platform() . "\n";
echo "  Browser: " . $agent->browser() . "\n";
echo "  Should serve mobile view: " . (($agent->isMobile() || $agent->isTablet()) ? 'YES' : 'NO') . "\n\n";

// Test 4: Android User Agent
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 10; SM-G973F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.120 Mobile Safari/537.36';
$agent = new Agent();
echo "Test 4 - Android User Agent:\n";
echo "  User Agent: " . $_SERVER['HTTP_USER_AGENT'] . "\n";
echo "  Is Mobile: " . ($agent->isMobile() ? 'YES' : 'NO') . "\n";
echo "  Is Tablet: " . ($agent->isTablet() ? 'YES' : 'NO') . "\n";
echo "  Device: " . $agent->device() . "\n";
echo "  Platform: " . $agent->platform() . "\n";
echo "  Browser: " . $agent->browser() . "\n";
echo "  Should serve mobile view: " . (($agent->isMobile() || $agent->isTablet()) ? 'YES' : 'NO') . "\n\n";

// Test 5: Check if mobile view file exists
echo "Test 5 - Mobile View Files:\n";
$mobileViewPath = __DIR__ . '/../src/Dashboard/Views/dashboard-mobile.php';
echo "  Mobile view file exists: " . (file_exists($mobileViewPath) ? 'YES' : 'NO') . "\n";
echo "  Mobile view path: " . $mobileViewPath . "\n";

$desktopViewPath = __DIR__ . '/../src/Dashboard/Views/dashboard.php';
echo "  Desktop view file exists: " . (file_exists($desktopViewPath) ? 'YES' : 'NO') . "\n";
echo "  Desktop view path: " . $desktopViewPath . "\n\n";

// Test 6: Check mobile partial files
echo "Test 6 - Mobile Partial Files:\n";
$mobilePartials = [
    'filters-modal.php',
    'call-detail-modal.php',
    'analytics-modal.php'
];
foreach ($mobilePartials as $partial) {
    $path = __DIR__ . "/../src/Dashboard/Views/partials-mobile/{$partial}";
    echo "  {$partial}: " . (file_exists($path) ? 'EXISTS' : 'MISSING') . "\n";
}
echo "\n";

// Test 7: Check mobile asset files
echo "Test 7 - Mobile Asset Files:\n";
$mobileAssets = [
    'public/assets/css/mobile.css',
    'public/assets/js/mobile.js'
];
foreach ($mobileAssets as $asset) {
    $path = __DIR__ . "/../{$asset}";
    echo "  {$asset}: " . (file_exists($path) ? 'EXISTS' : 'MISSING') . "\n";
}
echo "\n";

echo "=== Test Complete ===\n";
