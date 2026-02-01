<!-- Filters Modal -->
<div class="modal fade" id="filters-modal" tabindex="-1" aria-labelledby="filtersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="filtersModalLabel">
                    <i class="bi bi-funnel"></i> Filter Dashboard
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="dashboard-filter-form">
                    <!-- Quick Time Period Selector -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label fw-bold">Quick Select</label>
                            <select class="form-select" id="dashboard-quick-period" name="quick_period">
                                <option value="">Custom Range</option>
                                <option value="today" selected>Today</option>
                                <option value="yesterday">Yesterday</option>
                                <option value="last_7_days">Last 7 Days</option>
                                <option value="last_30_days">Last 30 Days</option>
                                <option value="this_month">This Month</option>
                                <option value="last_month">Last Month</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Custom Date Range (hidden by default) -->
                    <div class="row mb-3" id="custom-date-range" style="display: none;">
                        <div class="col-md-6">
                            <label class="form-label">Date From</label>
                            <input type="date" class="form-control" id="dashboard-date-from" name="date_from">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date To</label>
                            <input type="date" class="form-control" id="dashboard-date-to" name="date_to">
                        </div>
                    </div>
                    
                    <hr>
                    
                    <!-- Filters Grid -->
                    <div class="row g-3">
                        <!-- Agency Type -->
                        <div class="col-md-4">
                            <label class="form-label">Agency Type</label>
                            <select class="form-select" id="dashboard-agency-type" name="agency_type">
                                <option value="">All Agencies</option>
                                <option value="Police">Police</option>
                                <option value="Fire">Fire</option>
                                <option value="EMS">EMS</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Jurisdiction</label>
                            <select class="form-select" id="dashboard-jurisdiction" name="jurisdiction">
                                <option value="">All Jurisdictions</option>
                            </select>
                        </div>
                        
                        <!-- Call Status -->
                        <div class="col-md-4">
                            <label class="form-label">Call Status</label>
                            <select class="form-select" id="dashboard-call-status" name="status">
                                <option value="">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                        
                        <!-- Unit Status -->
                        <div class="col-md-4">
                            <label class="form-label">Unit Status</label>
                            <select class="form-select" id="dashboard-unit-status" name="unit_status">
                                <option value="">All Units</option>
                                <option value="available">Available</option>
                                <option value="enroute">En Route</option>
                                <option value="onscene">On Scene</option>
                                <option value="offduty">Off Duty</option>
                            </select>
                        </div>
                        
                        <!-- Priority -->
                        <div class="col-md-4">
                            <label class="form-label">Priority</label>
                            <select class="form-select" id="dashboard-priority" name="priority">
                                <option value="">All Priorities</option>
                                <option value="1">Priority 1</option>
                                <option value="2">Priority 2</option>
                                <option value="3">Priority 3</option>
                                <option value="4">Priority 4</option>
                            </select>
                        </div>
                        
                        <!-- Search -->
                        <div class="col-md-8">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" id="dashboard-search" name="search" 
                                   placeholder="Call ID, Unit ID, Location...">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="clear-filters">
                    <i class="bi bi-x-circle"></i> Clear Filters
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Cancel
                </button>
                <button type="submit" form="dashboard-filter-form" class="btn btn-primary" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle"></i> Apply Filters
                </button>
            </div>
        </div>
    </div>
</div>
