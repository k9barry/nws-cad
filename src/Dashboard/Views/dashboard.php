<!-- Main Dashboard View - Modular Structure -->

<!-- Dashboard Header with Filter Controls (Right-aligned) -->
<div class="row mb-3">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="display-6 mb-0">
                <i class="bi bi-speedometer2"></i> Dashboard Control Center
            </h1>
            <div class="d-flex align-items-center gap-3">
                <div>
                    <i class="bi bi-funnel me-2"></i>
                    <span class="text-muted">Active Filters: </span>
                    <span id="filter-summary" class="fw-bold">Last 7 Days</span>
                </div>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#filters-modal">
                    <i class="bi bi-sliders"></i> Change Filters
                </button>
            </div>
        </div>
    </div>
</div>

<?php
// Include modular components
$partialsPath = __DIR__ . '/partials/';

// 1. Map and Statistics Cards with Recent Calls Table
include $partialsPath . 'map-and-stats.php';

// 2. Modals
include $partialsPath . 'filter-modal.php';
include $partialsPath . 'call-detail-modal.php';
include $partialsPath . 'analytics-modal.php';
?>
