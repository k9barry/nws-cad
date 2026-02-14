<!-- Mobile Dashboard View -->

<!-- Mobile Header -->
<div class="mobile-header">
    <h1>
        <i class="bi bi-broadcast"></i> 
        NWS CAD
    </h1>
    <div class="mobile-header-actions">
        <div class="mobile-live-indicator">
            <i class="bi bi-circle-fill"></i>
            <span>Live</span>
        </div>
    </div>
</div>

<!-- Mobile Content -->
<div class="mobile-content">
    <!-- Stats Cards - Horizontal Scroll -->
    <div class="mobile-stats-scroll">
        <div class="mobile-stat-card border-primary" data-bs-toggle="modal" data-bs-target="#mobile-filters-modal">
            <h6>Total Calls</h6>
            <h2 class="text-primary" id="stat-total">
                <span class="spinner-border spinner-border-sm"></span>
            </h2>
        </div>
        
        <div class="mobile-stat-card border-danger" onclick="MobileDashboard.filters.status = 'active'; MobileDashboard.applyFilters();">
            <h6>Active</h6>
            <h2 class="text-danger" id="stat-active">
                <span class="spinner-border spinner-border-sm"></span>
            </h2>
        </div>
        
        <div class="mobile-stat-card border-success" onclick="MobileDashboard.filters.status = 'closed'; MobileDashboard.applyFilters();">
            <h6>Closed</h6>
            <h2 class="text-success" id="stat-closed">
                <span class="spinner-border spinner-border-sm"></span>
            </h2>
        </div>
        
        <div class="mobile-stat-card border-warning" data-bs-toggle="modal" data-bs-target="#mobile-analytics-modal">
            <h6>Analytics</h6>
            <h2 class="text-warning">
                <i class="bi bi-graph-up"></i>
            </h2>
        </div>
    </div>
    
    <!-- Calls View (Default) -->
    <div id="mobile-calls-view">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">
                <i class="bi bi-list-ul"></i> Recent Calls
            </h5>
            <small class="text-muted" id="mobile-showing-text">Loading...</small>
        </div>
        
        <div id="mobile-calls-list">
            <!-- Calls will be loaded here -->
        </div>
    </div>
    
    <!-- Map View (Hidden by default) -->
    <div id="mobile-map-view" style="display: none;">
        <div id="calls-map" style="height: calc(100vh - var(--mobile-header-height) - var(--mobile-bottom-nav-height) - 24px);"></div>
    </div>
</div>

<!-- Pull to Refresh Indicator -->
<div id="mobile-refresh-indicator" class="mobile-refresh-indicator">
    <div class="spinner-border spinner-border-sm"></div>
    <span>Refreshing...</span>
</div>

<!-- Mobile Bottom Navigation -->
<div class="mobile-bottom-nav">
    <button class="mobile-nav-item active" data-action="calls">
        <i class="bi bi-list-ul"></i>
        <span>Calls</span>
    </button>
    
    <button class="mobile-nav-item" data-action="map">
        <i class="bi bi-geo-alt"></i>
        <span>Map</span>
    </button>
    
    <button class="mobile-nav-item" data-action="filters">
        <i class="bi bi-funnel"></i>
        <span>Filters</span>
    </button>
    
    <button class="mobile-nav-item" data-action="analytics">
        <i class="bi bi-graph-up"></i>
        <span>Charts</span>
    </button>
    
    <button class="mobile-nav-item" data-action="refresh">
        <i class="bi bi-arrow-clockwise"></i>
        <span>Refresh</span>
    </button>
</div>

<?php
// Include mobile modals
$mobilepartialsPath = __DIR__ . '/partials-mobile/';

include $mobilepartialsPath . 'filters-modal.php';
include $mobilepartialsPath . 'call-detail-modal.php';
include $mobilepartialsPath . 'analytics-modal.php';
?>
