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
                    <span id="filter-summary" class="fw-bold">Today, Open</span>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm"
                        data-bs-toggle="offcanvas" data-bs-target="#filter-drawer"
                        aria-controls="filter-drawer">
                    <i class="bi bi-sliders"></i> Filters
                </button>
            </div>
        </div>
    </div>
</div>

<?php
// Include modular components
$partialsPath = __DIR__ . '/partials/';
?>

<!-- Filter Drawer (offcanvas) -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="filter-drawer" aria-labelledby="filter-drawer-label">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="filter-drawer-label">
            <i class="bi bi-sliders"></i> Filters
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <?php
        $filterFields = 'date,call_type,nature_of_call,agency,ori,fdid,beat,area,city,location,call_id,unit,status,q';
        $filterCompact = 'true'; // narrow drawer: stack fields vertically
        include $partialsPath . 'filter-panel.php';
        ?>
    </div>
</div>

<?php
// Map and Statistics Cards with Recent Calls Table
include $partialsPath . 'map-and-stats.php';

// Modals
include $partialsPath . 'call-detail-modal.php';
include $partialsPath . 'analytics-modal.php';
?>
