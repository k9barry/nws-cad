<!-- Units View -->
<div class="row mb-4">
    <div class="col-12">
        <h1 class="display-6 mb-3">
            <i class="bi bi-truck"></i> Units Status & Tracking
        </h1>
    </div>
</div>

<!-- Filter Period -->
<div class="card mb-4">
    <div class="card-header bg-white">
        <h5 class="card-title mb-0">
            <i class="bi bi-funnel"></i> Filter Period
        </h5>
    </div>
    <div class="card-body">
        <form id="units-filter-form">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Time Range</label>
                    <select class="form-select" id="filter-time-range" name="time_range">
                        <option value="1">Last 1 Hour</option>
                        <option value="3">Last 3 Hours</option>
                        <option value="6">Last 6 Hours</option>
                        <option value="12">Last 12 Hours</option>
                        <option value="24" selected>Last 24 Hours</option>
                        <option value="48">Last 48 Hours</option>
                        <option value="72">Last 3 Days</option>
                        <option value="168">Last 7 Days</option>
                        <option value="custom">Custom Date Range</option>
                    </select>
                </div>
                
                <div id="custom-date-range" style="display: none;">
                    <div class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">Date From</label>
                            <input type="date" class="form-control" id="filter-date-from" name="date_from">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date To</label>
                            <input type="date" class="form-control" id="filter-date-to" name="date_to">
                        </div>
                    </div>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Unit Status</label>
                    <select class="form-select" id="filter-unit-status" name="status">
                        <option value="">All Statuses</option>
                        <option value="available">Available</option>
                        <option value="enroute">En Route</option>
                        <option value="onscene">On Scene</option>
                        <option value="dispatched">Dispatched</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Unit Type</label>
                    <select class="form-select" id="filter-unit-type" name="unit_type">
                        <option value="">All Types</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Agency</label>
                    <select class="form-select" id="filter-agency-type" name="agency_type">
                        <option value="">All Agencies</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-arrow-clockwise"></i> Update
                    </button>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="reset" class="btn btn-secondary w-100">
                        <i class="bi bi-x-circle"></i> Reset
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Unit Statistics -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Available</h6>
                        <h2 class="mb-0 text-success" id="units-available">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-success">
                        <i class="bi bi-check-circle"></i>
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
                        <h6 class="text-muted mb-1">En Route</h6>
                        <h2 class="mb-0 text-warning" id="units-enroute">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-warning">
                        <i class="bi bi-arrow-right-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-danger">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">On Scene</h6>
                        <h2 class="mb-0 text-danger" id="units-onscene">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-danger">
                        <i class="bi bi-geo-alt-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-secondary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Off Duty</h6>
                        <h2 class="mb-0 text-secondary" id="units-offduty">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-secondary">
                        <i class="bi bi-dash-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Unit Statistics -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Available</h6>
                        <h2 class="mb-0 text-success" id="units-available">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-success">
                        <i class="bi bi-check-circle"></i>
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
                        <h6 class="text-muted mb-1">En Route</h6>
                        <h2 class="mb-0 text-warning" id="units-enroute">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-warning">
                        <i class="bi bi-arrow-right-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-danger">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">On Scene</h6>
                        <h2 class="mb-0 text-danger" id="units-onscene">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-danger">
                        <i class="bi bi-geo-alt-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-secondary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Off Duty</h6>
                        <h2 class="mb-0 text-secondary" id="units-offduty">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-secondary">
                        <i class="bi bi-dash-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Map and Unit List -->
<div class="row mb-4">
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-map"></i> Unit Locations
                </h5>
            </div>
            <div class="card-body p-0">
                <div id="units-map" style="height: 500px;"></div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-funnel"></i> Filters
                </h5>
            </div>
            <div class="card-body">
                <form id="units-filter-form">
                    <div class="mb-3">
                        <label class="form-label">Time Range</label>
                        <select class="form-select" id="filter-time-range" name="time_range">
                            <option value="1">Last 1 Hour</option>
                            <option value="3">Last 3 Hours</option>
                            <option value="6">Last 6 Hours</option>
                            <option value="12">Last 12 Hours</option>
                            <option value="24" selected>Last 24 Hours</option>
                            <option value="48">Last 48 Hours</option>
                            <option value="72">Last 3 Days</option>
                            <option value="168">Last 7 Days</option>
                            <option value="custom">Custom Date Range</option>
                        </select>
                    </div>
                    
                    <div id="custom-date-range" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Date From</label>
                            <input type="date" class="form-control" id="filter-date-from" name="date_from">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Date To</label>
                            <input type="date" class="form-control" id="filter-date-to" name="date_to">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Unit Status</label>
                        <select class="form-select" id="filter-unit-status" name="status">
                            <option value="">All Statuses</option>
                            <option value="available">Available</option>
                            <option value="enroute">En Route</option>
                            <option value="onscene">On Scene</option>
                            <option value="offduty">Off Duty</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Unit Type</label>
                        <select class="form-select" id="filter-unit-type" name="type">
                            <option value="">All Types</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Agency</label>
                        <select class="form-select" id="filter-unit-agency" name="agency_type">
                            <option value="">All Agencies</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" id="filter-unit-search" 
                               name="search" placeholder="Unit ID, Badge...">
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> Apply Filters
                    </button>
                    <button type="reset" class="btn btn-secondary w-100 mt-2">
                        <i class="bi bi-x-circle"></i> Reset
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Units Table -->
<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            <i class="bi bi-list-ul"></i> Units List
            <span class="badge bg-primary ms-2" id="units-count">0</span>
            <small class="text-muted ms-2" id="filter-status">Last 24 Hours</small>
        </h5>
        <div>
            <button class="btn btn-sm btn-success" id="export-units-csv">
                <i class="bi bi-download"></i> Export CSV
            </button>
            <button class="btn btn-sm btn-info" id="refresh-units">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0" id="units-table">
                <thead class="table-dark">
                    <tr>
                        <th>Unit ID</th>
                        <th>Type</th>
                        <th>Agency</th>
                        <th>Status</th>
                        <th>Incident Number</th>
                        <th>Personnel</th>
                        <th>Last Update</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="units-table-body">
                    <tr>
                        <td colspan="8" class="text-center py-4">
                            <div class="spinner-border text-primary"></div>
                            <p class="text-muted mt-2">Loading units...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Unit Detail Modal -->
<div class="modal fade" id="unit-detail-modal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Unit Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="unit-detail-content">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary"></div>
                </div>
            </div>
        </div>
    </div>
</div>
