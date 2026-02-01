<!-- Main Dashboard View -->
<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <h1 class="display-6 mb-0">
            <i class="bi bi-speedometer2"></i> Dashboard Control Center
        </h1>
        <div class="d-flex gap-2">
            <a href="/calls" class="btn btn-outline-primary">
                <i class="bi bi-telephone"></i> Calls
            </a>
            <a href="/units" class="btn btn-outline-success">
                <i class="bi bi-truck"></i> Units
            </a>
            <a href="/analytics" class="btn btn-outline-info">
                <i class="bi bi-graph-up"></i> Analytics
            </a>
        </div>
    </div>
</div>

<!-- Active Filters Summary & Button -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card bg-light">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center">
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
</div>

<!-- Statistics Cards - 6 Cards -->
<div class="row mb-4" id="stats-cards">
    <div class="col-md-2 col-sm-6 mb-3">
        <div class="card stat-card border-primary clickable-stat" onclick="navigateToFiltered('calls', {})">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Calls</h6>
                        <h2 class="mb-0" id="stat-total-calls">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-primary">
                        <i class="bi bi-list-check"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-2 col-sm-6 mb-3">
        <div class="card stat-card border-danger clickable-stat" onclick="navigateToFiltered('calls', {status: 'active'})">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Active Calls</h6>
                        <h2 class="mb-0 text-danger" id="stat-active-calls">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-danger">
                        <i class="bi bi-telephone-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-2 col-sm-6 mb-3">
        <div class="card stat-card border-secondary clickable-stat" onclick="navigateToFiltered('calls', {status: 'closed'})">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Closed Calls</h6>
                        <h2 class="mb-0" id="stat-closed-calls">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-secondary">
                        <i class="bi bi-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-2 col-sm-6 mb-3">
        <div class="card stat-card border-success clickable-stat" onclick="navigateToFiltered('units', {status: 'available'})">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Available Units</h6>
                        <h2 class="mb-0 text-success" id="stat-available-units">
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
    
    <div class="col-md-2 col-sm-6 mb-3">
        <div class="card stat-card border-info">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Avg Response</h6>
                        <h2 class="mb-0" id="stat-avg-response">
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
    
    <div class="col-md-2 col-sm-6 mb-3">
        <div class="card stat-card border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Top Call Type</h6>
                        <h2 class="mb-0" id="stat-top-call-type" style="font-size: 0.9rem;">
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

<!-- Active Calls Map -->
<div class="row mb-4">
    <div class="col-lg-9 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-map"></i> Active Calls Map
                </h5>
            </div>
            <div class="card-body p-0">
                <div id="calls-map" style="height: 500px;"></div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-3 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-list-ul"></i> <span id="recent-activity-title">Recent Activity</span>
                </h5>
            </div>
            <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                <div id="recent-calls">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Calls Table -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-table"></i> Recent Calls
                </h5>
                <a href="/calls" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-eye"></i> View All
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Units</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="calls-table-body">
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <div class="spinner-border text-primary"></div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
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
