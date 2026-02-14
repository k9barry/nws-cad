<?php

/**
 * NWS CAD Dashboard Entry Point
 * Web-based dashboard for CAD data visualization
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use NwsCad\Dashboard\Router;
use Jenssegers\Agent\Agent;

// Detect device type
$agent = new Agent();
$isMobile = $agent->isMobile() || $agent->isTablet();

// Simple routing
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';

// API base URL (adjust based on environment)
$apiBaseUrl = 'http://localhost:8080/api';

// Get Dozzle port from environment (default 8081 - external Dozzle container)
$dozzlePort = getenv('DOZZLE_PORT') ?: '8081';

// Define routes (dashboard-only)
$routes = [
    '/' => 'dashboard',
];

// Get page from route and determine view based on device
$page = $routes[$uri] ?? 'dashboard';
if ($isMobile) {
    $page .= '-mobile';
}
$pageTitle = ucfirst(str_replace('-mobile', '', $page));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="NWS CAD Dashboard - Computer-Aided Dispatch Data Visualization">
    <title><?= htmlspecialchars($pageTitle) ?> - NWS CAD Dashboard</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <!-- Custom CSS -->
    <link href="/assets/css/dashboard.css?v=<?= time() ?>" rel="stylesheet">
    
    <?php if ($isMobile): ?>
    <!-- Mobile-specific CSS -->
    <link href="/assets/css/mobile.css?v=<?= time() ?>" rel="stylesheet">
    <?php endif; ?>
    
    <!-- Print CSS -->
    <link href="/assets/css/print.css" rel="stylesheet" media="print">
</head>
<body<?= $isMobile ? ' class="mobile-view"' : '' ?>>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="/">
                <i class="bi bi-broadcast"></i> NWS CAD Dashboard
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>" href="/">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="dozzle-link" href="#" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-file-text"></i> Logs
                        </a>
                    </li>
                </ul>
                <div class="d-flex align-items-center">
                    <span class="navbar-text me-3" id="live-indicator">
                        <i class="bi bi-circle-fill text-secondary"></i> Connecting...
                    </span>
                    <button class="btn btn-outline-light btn-sm" onclick="window.print()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="container-fluid py-4">
        <?php
        $viewFile = __DIR__ . "/../src/Dashboard/Views/{$page}.php";
        if (file_exists($viewFile)) {
            include $viewFile;
        } else {
            echo '<div class="alert alert-danger">Page not found</div>';
        }
        ?>
    </main>

    <!-- Footer -->
    <footer class="bg-light py-3 mt-5 no-print">
        <div class="container-fluid text-center text-muted">
            <small>
                NWS CAD Dashboard &copy; <?= date('Y') ?> | 
                <a href="https://github.com/k9barry/nws-cad" target="_blank">Documentation</a> |
                API Status: <span id="api-status" class="badge bg-secondary">Checking...</span>
            </small>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <!-- Custom JS -->
    <script>
        // Always use the current origin for API calls (works for local, remote, and Codespaces)
        const baseUrl = window.location.origin;
        
        window.APP_CONFIG = {
            apiBaseUrl: baseUrl + '/api',
            currentPage: '<?= $page ?>',
            refreshInterval: 30000 // 30 seconds
        };
        
        console.log('[NWS CAD] Configuration loaded:', {
            baseUrl: baseUrl,
            apiBaseUrl: window.APP_CONFIG.apiBaseUrl,
            hostname: window.location.hostname,
            origin: window.location.origin
        });
        
        // Helper function to get external service URL
        function getServiceUrl(port) {
            // Check if hostname looks like Codespaces
            const hostname = window.location.hostname;
            if (hostname.includes('github.dev') || hostname.includes('githubpreview.dev')) {
                // Extract the codespace base name (everything before the first -NNNN)
                const match = hostname.match(/^([a-zA-Z0-9-]+)-\d+\./i);
                if (match) {
                    return window.location.protocol + '//' + match[1] + '-' + port + '.app.github.dev';
                }
            }
            // For local or other environments, use the current hostname with specified port
            return `${window.location.protocol}//${window.location.hostname.split(':')[0]}:${port}`;
        }
        
        // Set Dozzle (Logs) URL dynamically using configured port
        const dozzlePort = <?= json_encode($dozzlePort) ?>;
        const dozzleUrl = getServiceUrl(dozzlePort);
        const dozzleLink = document.getElementById('dozzle-link');
        if (dozzleLink) {
            dozzleLink.href = dozzleUrl;
        }
        
        console.log('APP_CONFIG initialized:', window.APP_CONFIG);
    </script>
    <script src="/assets/js/dashboard.js?v=<?= time() ?>"></script>
    
    <?php if ($isMobile): ?>
    <!-- Mobile-specific scripts -->
    <script src="/assets/js/mobile.js?v=<?= time() ?>"></script>
    <?php else: ?>
    <!-- Desktop-specific scripts -->
    <script src="/assets/js/filter-manager.js?v=<?= time() ?>"></script>
    <script src="/assets/js/maps.js?v=<?= time() ?>"></script>
    <script src="/assets/js/charts.js?v=<?= time() ?>"></script>
    
    <!-- Page-specific scripts -->
    <?php if ($page === 'dashboard'): ?>
        <script src="/assets/js/dashboard-main.js?v=<?= time() ?>"></script>
        <script src="/assets/js/analytics.js?v=<?= time() ?>"></script>
    <?php endif; ?>
    <?php endif; ?>
</body>
</html>
