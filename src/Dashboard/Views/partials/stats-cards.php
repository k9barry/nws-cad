<!-- Statistics Cards - 4 Essential Cards -->
<div class="row mb-4" id="stats-cards">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-primary clickable-stat" data-bs-toggle="modal" data-bs-target="#filters-modal" style="cursor: pointer;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Calls</h6>
                        <h2 class="mb-0" id="stat-total-calls">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                    </div>
                    <div class="stat-icon bg-primary">
                        <i class="bi bi-funnel"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-danger clickable-stat" onclick="filterDashboard('active')" style="cursor: pointer;">
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
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-secondary clickable-stat" onclick="filterDashboard('closed')" style="cursor: pointer;">
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
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card border-warning clickable-stat" data-bs-toggle="modal" data-bs-target="#analytics-modal" style="cursor: pointer;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Analytics</h6>
                        <h2 class="mb-0" style="font-size: 1.2rem;">
                            <i class="bi bi-graph-up"></i> View Charts
                        </h2>
                    </div>
                    <div class="stat-icon bg-warning">
                        <i class="bi bi-bar-chart-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
