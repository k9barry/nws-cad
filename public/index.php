<?php

/**
 * NWS CAD Dashboard Entry Point
 * Web-based dashboard for CAD data visualization
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use NwsCad\Dashboard\Router;

// Simple routing
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';

// API base URL (adjust based on environment)
$apiBaseUrl = 'http://localhost:8080/api';

// Define routes
$routes = [
    '/' => 'dashboard',
    '/calls' => 'calls',
    '/units' => 'units',
    '/analytics' => 'analytics',
    '/logs' => 'logs',
];

// Get page from route
$page = $routes[$uri] ?? 'dashboard';
$pageTitle = ucfirst($page);

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
    <link href="/assets/css/dashboard.css" rel="stylesheet">
    
    <!-- Print CSS -->
    <link href="/assets/css/print.css" rel="stylesheet" media="print">
</head>
<body>
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
                        <a class="nav-link <?= $page === 'calls' ? 'active' : '' ?>" href="/calls">
                            <i class="bi bi-telephone"></i> Calls
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $page === 'units' ? 'active' : '' ?>" href="/units">
                            <i class="bi bi-truck"></i> Units
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $page === 'analytics' ? 'active' : '' ?>" href="/analytics">
                            <i class="bi bi-graph-up"></i> Analytics
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $page === 'logs' ? 'active' : '' ?>" href="/logs">
                            <i class="bi bi-file-text"></i> Logs
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="dbeaver-link" href="#" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-database"></i> DBeaver
                        </a>
                    </li>
                </ul>
                <div class="d-flex align-items-center">
                    <span class="navbar-text me-3">
                        <i class="bi bi-circle-fill text-success pulse"></i> Live
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
        // Detect if we're in Codespaces and use the correct URL
        const isCodespaces = window.location.hostname.includes('github.dev') || window.location.hostname.includes('githubpreview.dev');
        const baseUrl = isCodespaces 
            ? window.location.origin  // Use the current origin in Codespaces
            : 'http://localhost:8080'; // Use localhost for local development
        
        window.APP_CONFIG = {
            apiBaseUrl: baseUrl + '/api',
            currentPage: '<?= $page ?>',
            refreshInterval: 30000 // 30 seconds
        };
        
        // Set DBeaver URL dynamically
        const dbeaverPort = isCodespaces ? window.location.origin : 'http://localhost:8978';
        const dbeaverLink = document.getElementById('dbeaver-link');
        if (dbeaverLink) {
            dbeaverLink.href = dbeaverPort;
        }
        
        console.log('APP_CONFIG initialized:', window.APP_CONFIG);
    </script>
    <script src="/assets/js/dashboard.js"></script>
    <script src="/assets/js/maps.js"></script>
    <script src="/assets/js/charts.js"></script>
    
    <!-- Page-specific scripts -->
    <?php if ($page === 'dashboard'): ?>
        <script src="/assets/js/dashboard-main.js"></script>
    <?php elseif ($page === 'calls'): ?>
        <script src="/assets/js/calls.js"></script>
    <?php elseif ($page === 'units'): ?>
        <script src="/assets/js/units.js"></script>
    <?php elseif ($page === 'analytics'): ?>
        <script src="/assets/js/analytics.js"></script>
    <?php elseif ($page === 'logs'): ?>
        <script src="/assets/js/logs.js"></script>
    <?php endif; ?>
</body>
</html>
