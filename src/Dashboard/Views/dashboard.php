<!-- Main Dashboard View -->
<div class="row mb-4">
    <div class="col-12">
        <h1 class="display-6 mb-3">
            <i class="bi bi-speedometer2"></i> Dashboard Overview
        </h1>
    </div>
</div>

<!-- Date Range Filter -->
<div class="card mb-4">
    <div class="card-header bg-white">
        <h5 class="card-title mb-0">
            <i class="bi bi-funnel"></i> Filter Period
        </h5>
    </div>
    <div class="card-body">
        <form id="dashboard-filter-form" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" class="form-control" id="dashboard-date-from">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control" id="dashboard-date-to">
            </div>
            <div class="col-md-2">
                <label class="form-label">Quick Select</label>
                <select class="form-select" id="dashboard-quick-period">
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="7days" selected>Last 7 Days</option>
                    <option value="30days">Last 30 Days</option>
                    <option value="thismonth">This Month</option>
                    <option value="lastmonth">Last Month</option>
                    <option value="custom">Custom Range</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Agency</label>
                <select class="form-select" id="dashboard-agency">
                    <option value="">All Agencies</option>
                    <option value="Police">Police</option>
                    <option value="Fire">Fire</option>
                    <option value="EMS">EMS</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Jurisdiction</label>
                <select class="form-select" id="dashboard-jurisdiction">
                    <option value="">All Jurisdictions</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-arrow-clockwise"></i> Update
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4" id="stats-cards">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Active Calls</h6>
                        <h2 class="mb-0" id="stat-active-calls">
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
        <div class="card stat-card border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Available Units</h6>
                        <h2 class="mb-0" id="stat-available-units">
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
                        <h6 class="text-muted mb-1">Avg Response Time</h6>
                        <h2 class="mb-0" id="stat-avg-response">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-warning">
                        <i class="bi bi-clock-fill"></i>
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
                        <h6 class="text-muted mb-1">Calls Today</h6>
                        <h2 class="mb-0" id="stat-calls-today">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-info">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Map and Recent Calls -->
<div class="row mb-4">
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-geo-alt-fill"></i> Call Locations Map
                </h5>
            </div>
            <div class="card-body p-0">
                <div id="calls-map" style="height: 500px;"></div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-telephone"></i> Active Calls
                </h5>
            </div>
            <div class="card-body p-0">
                <div id="recent-calls" class="list-group list-group-flush" style="max-height: 500px; overflow-y: auto;">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary"></div>
                        <p class="text-muted mt-2">Loading calls...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row mb-4">
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-bar-chart-fill"></i> Call Volume Trends by Jurisdiction
                </h5>
            </div>
            <div class="card-body">
                <canvas id="calls-trend-chart"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-pie-chart-fill"></i> Call Types Distribution
                </h5>
            </div>
            <div class="card-body">
                <canvas id="call-types-chart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-12 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-activity"></i> System Overview
                </h5>
            </div>
            <div class="card-body">
                <canvas id="unit-activity-chart"></canvas>
            </div>
        </div>
    </div>
</div>
