<!-- Statistics Cards Row - 4 across at the top -->
<div class="row mb-0" id="stats-cards">
    <div class="col-lg-3 col-md-6 col-sm-6 mb-2">
        <div class="card stat-card-v2 is-total clickable-stat" data-bs-toggle="modal" data-bs-target="#filters-modal">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Calls</h6>
                        <h2 class="mb-0" id="stat-total-calls">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                        <span class="summary-chips mt-1" id="stat-total-pill"></span>
                    </div>
                    <div class="stat-icon">
                        <i class="bi bi-funnel"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 col-sm-6 mb-2">
        <div class="card stat-card-v2 is-active clickable-stat" onclick="filterDashboard('active')">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Active Calls</h6>
                        <h2 class="mb-0 text-danger" id="stat-active-calls">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                        <span class="summary-chips mt-1" id="stat-active-pill"></span>
                    </div>
                    <div class="stat-icon">
                        <i class="bi bi-telephone-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 col-sm-6 mb-2">
        <div class="card stat-card-v2 is-closed clickable-stat" onclick="filterDashboard('closed')">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Closed Calls</h6>
                        <h2 class="mb-0" id="stat-closed-calls">
                            <span class="spinner-border spinner-border-sm"></span>
                        </h2>
                        <span class="summary-chips mt-1" id="stat-closed-pill"></span>
                    </div>
                    <div class="stat-icon">
                        <i class="bi bi-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 col-sm-6 mb-2">
        <div class="card stat-card-v2 is-analytics clickable-stat" data-bs-toggle="modal" data-bs-target="#analytics-modal">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Analytics</h6>
                        <h2 class="mb-0 stat-title-compact">
                            <i class="bi bi-graph-up"></i> View Charts
                        </h2>
                        <span class="summary-chips mt-1" id="stat-analytics-pill"></span>
                    </div>
                    <div class="stat-icon">
                        <i class="bi bi-bar-chart-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Map (left) and Recent Calls (right) Row -->
<div class="row mb-4 dashboard-row-fill">
    <!-- Madison County Map (left column) -->
    <div class="col-lg-5 mb-3">
        <div class="card map-column-card">
            <div class="card-header gradient-card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-geo-alt"></i> Madison County Map
                </h5>
                <span class="pill-badge is-info" id="map-marker-count">0 markers</span>
            </div>
            <div class="card-body p-0">
                <div id="calls-map"></div>
            </div>
        </div>
    </div>

    <!-- Recent Calls (right column) -->
    <div class="col-lg-7 dashboard-right-col">
        <div class="card recent-calls-card">
            <div class="card-header gradient-card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-list-ul"></i> <span id="recent-calls-title">Recent Calls</span>
                </h5>
                <small>Last updated: <span id="last-updated">Never</span></small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive recent-calls-scroll">
                    <table class="table table-hover mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="col-w-9">Call ID</th>
                                <th class="col-w-13">Received</th>
                                <th class="col-w-20">Call Type</th>
                                <th class="col-w-19">Location</th>
                                <th class="col-w-10">Priority</th>
                                <th class="col-w-10">Status</th>
                                <th class="col-w-9">Units</th>
                                <th class="col-w-10">Map</th>
                            </tr>
                        </thead>
                        <tbody id="recent-calls-body">
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="text-muted mt-2">Loading calls...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination Controls -->
                <div class="d-flex justify-content-between align-items-center p-3 border-top d-none" id="calls-pagination-container">
                    <div>
                        <small class="text-muted">
                            Showing <span id="calls-showing-start">0</span>-<span id="calls-showing-end">0</span> 
                            of <span id="calls-total">0</span> calls
                        </small>
                    </div>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-primary" id="calls-prev-btn" disabled>
                            <i class="bi bi-chevron-left"></i> Previous
                        </button>
                        <button type="button" class="btn btn-outline-primary disabled" id="calls-page-info">
                            Page 1 of 1
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="calls-next-btn" disabled>
                            Next <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Map Zoom Modal -->
<div class="modal fade" id="map-zoom-modal" tabindex="-1" aria-labelledby="mapZoomModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mapZoomModalLabel">
                    <i class="bi bi-geo-alt-fill"></i> Call Location - <span id="map-modal-call-id"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <!-- Map Container -->
                <div id="modal-map"></div>

                <!-- Call Info Card (Overlay) -->
                <div class="card position-absolute map-info-overlay">
                    <div class="card-body p-3">
                        <h6 class="card-title mb-2" id="map-modal-call-type">Loading...</h6>
                        <p class="card-text small mb-1">
                            <strong>Address:</strong> <span id="map-modal-address">-</span>
                        </p>
                        <p class="card-text small mb-1">
                            <strong>Priority:</strong> <span id="map-modal-priority">-</span>
                        </p>
                        <p class="card-text small mb-1">
                            <strong>Status:</strong> <span id="map-modal-status">-</span>
                        </p>
                        <p class="card-text small mb-0">
                            <strong>Time:</strong> <span id="map-modal-time">-</span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" id="map-modal-view-details-btn" class="btn btn-primary" data-popup-action="view-call">
                    <i class="bi bi-eye"></i> View Full Details
                </button>
            </div>
        </div>
    </div>
</div>
