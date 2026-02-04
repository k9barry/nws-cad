<!-- Map and Stats Cards Row - Top Section -->
<div class="row mb-4">
    <!-- Madison County Map (40% width) -->
    <div class="col-lg-5 mb-3">
        <div class="card" style="height: 100%;">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-geo-alt"></i> Madison County Map
                </h5>
            </div>
            <div class="card-body p-0">
                <div id="calls-map" style="height: 800px; width: 100%;"></div>
            </div>
        </div>
    </div>
    
    <!-- Right Column: Statistics Cards + Recent Calls (60% width) -->
    <div class="col-lg-7">
        <!-- Statistics Cards - 2x2 Grid -->
        <div class="row mb-4" id="stats-cards">
            <div class="col-md-6 col-sm-6 mb-3">
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
            
            <div class="col-md-6 col-sm-6 mb-3">
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
            
            <div class="col-md-6 col-sm-6 mb-3">
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
            
            <div class="col-md-6 col-sm-6 mb-3">
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
        
        <!-- Recent Calls Table (Below Stats Cards) -->
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-list-ul"></i> <span id="recent-calls-title">Recent Calls</span>
                </h5>
                <small class="text-muted">Last updated: <span id="last-updated">Never</span></small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-hover mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th style="width: 8%;">Call ID</th>
                                <th style="width: 12%;">Received</th>
                                <th style="width: 18%;">Call Type</th>
                                <th style="width: 15%;">Location</th>
                                <th style="width: 10%;">Priority</th>
                                <th style="width: 10%;">Status</th>
                                <th style="width: 9%;">Units</th>
                                <th style="width: 9%;">Map</th>
                                <th style="width: 9%;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="recent-calls-body">
                            <tr>
                                <td colspan="9" class="text-center py-4">
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
                <div class="d-flex justify-content-between align-items-center p-3 border-top" id="calls-pagination-container" style="display: none !important;">
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
                <div id="modal-map" style="height: 600px; width: 100%;"></div>
                
                <!-- Call Info Card (Overlay) -->
                <div class="card position-absolute" style="bottom: 20px; left: 20px; max-width: 350px; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.3);">
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
                <button type="button" class="btn btn-primary" onclick="viewCallDetails(window.currentModalCallId)">
                    <i class="bi bi-eye"></i> View Full Details
                </button>
            </div>
        </div>
    </div>
</div>
