<?php
/**
 * Shared filter panel partial.
 * Caller passes:
 *   $filterFields: comma-separated string of fields to render
 *   $filterCompact: 'true' or 'false' (default 'false')
 */
$filterFields = $filterFields ?? '';
$filterCompact = $filterCompact ?? 'false';
?>
<link rel="stylesheet" href="/assets/vendor/choices/choices.min.css">
<link rel="stylesheet" href="/assets/vendor/flatpickr/flatpickr.min.css">
<link rel="stylesheet" href="/assets/js/filters/filters.css">

<div id="filter-panel"
     data-filter-panel
     data-fields="<?= htmlspecialchars($filterFields, ENT_QUOTES, 'UTF-8') ?>"
     data-compact="<?= htmlspecialchars($filterCompact, ENT_QUOTES, 'UTF-8') ?>"></div>

<script src="/assets/vendor/choices/choices.min.js"></script>
<script src="/assets/vendor/flatpickr/flatpickr.min.js"></script>
<script src="/assets/js/filters/FilterState.js"></script>
<script src="/assets/js/filters/fields/MultiSelectField.js"></script>
<script src="/assets/js/filters/fields/DateRangeField.js"></script>
<script src="/assets/js/filters/fields/TextField.js"></script>
<script src="/assets/js/filters/fields/StatusField.js"></script>
<script src="/assets/js/filters/fieldRegistry.js"></script>
<script src="/assets/js/filters/FilterPanel.js"></script>
