<?php declare(strict_types=1); ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0 h4">Calls</h1>
    <button type="button" class="btn btn-outline-primary btn-sm"
            data-bs-toggle="offcanvas" data-bs-target="#filter-drawer"
            aria-controls="filter-drawer">
        <i class="bi bi-sliders"></i> Filters
    </button>
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
        $filterFields = 'date,call_type,nature_of_call,agency,ori,fdid,beat,area,city,location,call_id,unit,status,q';
        $filterCompact = 'true';
        include __DIR__ . '/partials/filter-panel.php';
        ?>
    </div>
</div>

<div id="calls-list" class="mt-3"></div>
<nav id="calls-pagination" class="mt-3"></nav>

<script src="/assets/js/calls-page.js?v=<?= time() ?>"></script>
