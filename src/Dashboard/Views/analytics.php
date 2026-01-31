<!-- Analytics View -->
<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <h1 class="display-6 mb-0">
            <i class="bi bi-graph-up"></i> Analytics & Reports
        </h1>
        <div class="d-flex gap-2">
            <a href="/" class="btn btn-primary">
                <i class="bi bi-arrow-left"></i> Return to Dashboard
            </a>
            <a href="/calls" class="btn btn-outline-primary">
                <i class="bi bi-telephone"></i> Calls
            </a>
            <a href="/units" class="btn btn-outline-success">
                <i class="bi bi-truck"></i> Units
            </a>
        </div>
    </div>
</div>

<!-- Active Filters Info -->
<div class="card mb-4 border-info" id="active-filters-card" style="display: none;">
    <div class="card-body py-2">
        <div class="d-flex align-items-center">
            <i class="bi bi-info-circle text-info me-2"></i>
            <span class="text-muted">Filters Active: </span>
            <span id="active-filters-display" class="ms-2 fw-bold"></span>
            <button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-bs-toggle="modal" data-bs-target="#filters-modal">
                <i class="bi bi-funnel"></i> Change Filters
            </button>
        </div>
    </div>
</div>

<!-- Analytics Statistics Cards -->
<div class="row mb-4" id="analytics-stats-cards">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Calls</h6>
                        <h2 class="mb-0" id="analytics-stat-total">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-primary">
                        <i class="bi bi-telephone-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-info">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Avg Response Time</h6>
                        <h2 class="mb-0" id="analytics-stat-response">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-info">
                        <i class="bi bi-clock-history"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Units</h6>
                        <h2 class="mb-0" id="analytics-stat-units">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-success">
                        <i class="bi bi-truck"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Top Call Type</h6>
                        <h2 class="mb-0" id="analytics-stat-toptype" style="font-size: 0.9rem;">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-warning">
                        <i class="bi bi-star-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row 1 -->
<div class="row mb-4">
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-graph-up"></i> Incidents by Jurisdiction
                </h5>
            </div>
            <div class="card-body">
                <canvas id="analytics-volume-chart"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-pie-chart"></i> Call Distribution
                </h5>
            </div>
            <div class="card-body">
                <canvas id="analytics-distribution-chart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row 2 -->
<div class="row mb-4">
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-clock"></i> Response Time Analysis
                </h5>
            </div>
            <div class="card-body">
                <canvas id="analytics-response-chart"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-building"></i> Calls by Agency
                </h5>
            </div>
            <div class="card-body">
                <canvas id="analytics-agency-chart"></canvas>
            </div>
        </div>
    </div>
</div>
<!-- Filters Modal -->
<div class="modal fade" id="filters-modal" tabindex="-1" aria-labelledby="filtersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="filtersModalLabel">
                    <i class="bi bi-funnel"></i> Global Filters
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="dashboard-filter-form" class="row g-3">
                    <!-- Date Range -->
                    <div class="col-md-3">
                        <label class="form-label">Quick Select</label>
                        <select class="form-select" id="dashboard-quick-period" name="quick_period">
                            <option value="">Custom Range</option>
                            <option value="today">Today</option>
                            <option value="yesterday">Yesterday</option>
                            <option value="7days" selected>Last 7 Days</option>
                            <option value="30days">Last 30 Days</option>
                            <option value="thismonth">This Month</option>
                            <option value="lastmonth">Last Month</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">From Date</label>
                        <input type="date" class="form-control" id="dashboard-date-from" name="date_from">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">To Date</label>
                        <input type="date" class="form-control" id="dashboard-date-to" name="date_to">
                    </div>
                    
                    <!-- Agency and Jurisdiction -->
                    <div class="col-md-3">
                        <label class="form-label">Agency Type</label>
                        <select class="form-select" id="dashboard-agency" name="agency_type">
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
