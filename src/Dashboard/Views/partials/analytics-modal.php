<!-- Analytics Modal -->
<div class="modal fade" id="analytics-modal" tabindex="-1" aria-labelledby="analyticsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title" id="analyticsModalLabel">
                    <i class="bi bi-graph-up"></i> Analytics & Reports
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
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
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
