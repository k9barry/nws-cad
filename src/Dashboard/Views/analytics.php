<!-- Analytics View -->
<div class="row mb-4">
    <div class="col-12">
        <h1 class="display-6 mb-3">
            <i class="bi bi-graph-up"></i> Analytics & Reports
        </h1>
    </div>
</div>

<!-- Date Range Selector -->
<div class="card mb-4">
    <div class="card-header bg-white">
        <h5 class="card-title mb-0">
            <i class="bi bi-calendar-range"></i> Analysis Period
        </h5>
    </div>
    <div class="card-body">
        <form id="analytics-period-form" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">From Date</label>
                <input type="date" class="form-control" id="analytics-date-from" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control" id="analytics-date-to" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Quick Select</label>
                <select class="form-select" id="quick-period">
                    <option value="">Custom Range</option>
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="7days">Last 7 Days</option>
                    <option value="30days">Last 30 Days</option>
                    <option value="thismonth">This Month</option>
                    <option value="lastmonth">Last Month</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-bar-chart"></i> Generate Report
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Key Metrics -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-primary">
            <div class="card-body">
                <h6 class="text-muted mb-2">Total Calls</h6>
                <h2 class="mb-0" id="analytics-total-calls">-</h2>
                <small class="text-muted" id="analytics-calls-change">-</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-success">
            <div class="card-body">
                <h6 class="text-muted mb-2">Avg Response Time</h6>
                <h2 class="mb-0" id="analytics-avg-response">-</h2>
                <small class="text-muted" id="analytics-response-change">-</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-warning">
            <div class="card-body">
                <h6 class="text-muted mb-2">Busiest Hour</h6>
                <h2 class="mb-0" id="analytics-busiest-hour">-</h2>
                <small class="text-muted" id="analytics-busiest-count">-</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-info">
            <div class="card-body">
                <h6 class="text-muted mb-2">Most Active Unit</h6>
                <h2 class="mb-0" id="analytics-top-unit">-</h2>
                <small class="text-muted" id="analytics-unit-calls">-</small>
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
                    <i class="bi bi-graph-up"></i> Call Volume Over Time
                </h5>
                <div class="btn-group btn-group-sm" role="group">
                    <input type="radio" class="btn-check" name="volume-grouping" id="volume-hour" value="hour" checked>
                    <label class="btn btn-outline-primary" for="volume-hour">Hour</label>
                    
                    <input type="radio" class="btn-check" name="volume-grouping" id="volume-day" value="day">
                    <label class="btn btn-outline-primary" for="volume-day">Day</label>
                    
                    <input type="radio" class="btn-check" name="volume-grouping" id="volume-week" value="week">
                    <label class="btn btn-outline-primary" for="volume-week">Week</label>
                </div>
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

<!-- Top Lists -->
<div class="row mb-4">
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-trophy"></i> Top Call Types
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush" id="top-call-types">
                    <div class="text-center py-4">
                        <div class="spinner-border spinner-border-sm"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-geo"></i> Top Locations
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush" id="top-locations">
                    <div class="text-center py-4">
                        <div class="spinner-border spinner-border-sm"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-truck"></i> Most Active Units
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush" id="top-units">
                    <div class="text-center py-4">
                        <div class="spinner-border spinner-border-sm"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Export Reports -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="card-title mb-0">
            <i class="bi bi-download"></i> Export Reports
        </h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <button class="btn btn-success w-100" id="export-summary-csv">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Summary Report (CSV)
                </button>
            </div>
            <div class="col-md-4">
                <button class="btn btn-success w-100" id="export-detailed-csv">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Detailed Report (CSV)
                </button>
            </div>
            <div class="col-md-4">
                <button class="btn btn-info w-100" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print Report
                </button>
            </div>
        </div>
    </div>
</div>
