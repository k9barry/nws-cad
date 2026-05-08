<?php declare(strict_types=1); ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0">Units</h1>
    <div class="d-flex align-items-center gap-3">
        <span class="text-muted small">
            <i class="bi bi-funnel me-1"></i>
            <span id="filter-summary">Today, Open</span>
        </span>
        <button type="button" class="btn btn-outline-primary btn-sm"
                data-bs-toggle="offcanvas" data-bs-target="#filter-drawer"
                aria-controls="filter-drawer">
            <i class="bi bi-sliders"></i> Filters
        </button>
    </div>
</div>

<!-- Filter Drawer -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="filter-drawer" aria-labelledby="filter-drawer-label">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="filter-drawer-label">
            <i class="bi bi-sliders"></i> Filters
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <?php
        $filterFields = 'date,agency,unit,status,call_id';
        $filterCompact = 'true';
        include __DIR__ . '/partials/filter-panel.php';
        ?>
    </div>
</div>

<div id="units-list" class="mt-3"></div>
<nav id="units-pagination" class="mt-3"></nav>

<script src="/assets/js/units-page.js?v=<?= time() ?>"></script>
