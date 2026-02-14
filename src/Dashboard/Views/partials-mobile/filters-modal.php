<!-- Mobile Filters Modal -->
<div class="modal fade" id="mobile-filters-modal" tabindex="-1" aria-labelledby="mobileFiltersModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mobileFiltersModalLabel">
                    <i class="bi bi-funnel"></i> Filter Calls
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Quick Select Period -->
                <div class="mobile-filter-section">
                    <label>Quick Select Period</label>
                    <div class="mobile-quick-select">
                        <button type="button" class="btn btn-outline-primary" data-period="today" 
                                onclick="this.parentElement.querySelectorAll('.btn').forEach(b => b.classList.remove('active')); this.classList.add('active');">
                            Today
                        </button>
                        <button type="button" class="btn btn-outline-primary" data-period="yesterday" 
                                onclick="this.parentElement.querySelectorAll('.btn').forEach(b => b.classList.remove('active')); this.classList.add('active');">
                            Yesterday
                        </button>
                        <button type="button" class="btn btn-outline-primary active" data-period="7days" 
                                onclick="this.parentElement.querySelectorAll('.btn').forEach(b => b.classList.remove('active')); this.classList.add('active');">
                            Last 7 Days
                        </button>
                        <button type="button" class="btn btn-outline-primary" data-period="30days" 
                                onclick="this.parentElement.querySelectorAll('.btn').forEach(b => b.classList.remove('active')); this.classList.add('active');">
                            Last 30 Days
                        </button>
                    </div>
                </div>
                
                <!-- Jurisdiction -->
                <div class="mobile-filter-section">
                    <label for="mobile-filter-jurisdiction">Jurisdiction</label>
                    <select class="form-select" id="mobile-filter-jurisdiction">
                        <option value="">All Jurisdictions</option>
                        <!-- Options will be populated dynamically -->
                    </select>
                </div>
                
                <!-- Agency -->
                <div class="mobile-filter-section">
                    <label for="mobile-filter-agency">Agency</label>
                    <select class="form-select" id="mobile-filter-agency">
                        <option value="">All Agencies</option>
                        <!-- Options will be populated dynamically -->
                    </select>
                </div>
                
                <!-- Call Type -->
                <div class="mobile-filter-section">
                    <label for="mobile-filter-call_type">Call Type</label>
                    <input type="text" class="form-control" id="mobile-filter-call_type" placeholder="e.g., Traffic Stop">
                </div>
                
                <!-- Status -->
                <div class="mobile-filter-section">
                    <label for="mobile-filter-status">Status</label>
                    <select class="form-select" id="mobile-filter-status">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="pending">Pending</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
                
                <!-- Priority -->
                <div class="mobile-filter-section">
                    <label for="mobile-filter-priority">Priority</label>
                    <select class="form-select" id="mobile-filter-priority">
                        <option value="">All Priorities</option>
                        <option value="1">Priority 1 (Highest)</option>
                        <option value="2">Priority 2</option>
                        <option value="3">Priority 3</option>
                        <option value="4">Priority 4 (Lowest)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="MobileDashboard.resetFilters()">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </button>
                <button type="button" class="btn btn-primary" onclick="MobileDashboard.applyFilters()">
                    <i class="bi bi-check-lg"></i> Apply Filters
                </button>
            </div>
        </div>
    </div>
</div>
