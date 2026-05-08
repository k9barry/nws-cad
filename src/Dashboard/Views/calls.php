<?php declare(strict_types=1); ?>
<h1 class="mb-3">Calls</h1>

<?php
$filterFields = 'date,call_type,incident_type,nature_of_call,agency,ori,fdid,beat,area,city,location,call_id,unit,status,q';
$filterCompact = 'false';
include __DIR__ . '/partials/filter-panel.php';
?>

<div id="calls-list" class="mt-3"></div>
<nav id="calls-pagination" class="mt-3"></nav>

<script src="/assets/js/calls-page.js?v=<?= time() ?>"></script>
