<?php declare(strict_types=1); ?>
<h1 class="mb-3">Units</h1>

<?php
$filterFields = 'date,agency,unit,status,call_id';
$filterCompact = 'true';
include __DIR__ . '/partials/filter-panel.php';
?>

<div id="units-list" class="mt-3"></div>
<nav id="units-pagination" class="mt-3"></nav>

<script src="/assets/js/units-page.js?v=<?= time() ?>"></script>
