<!-- Calls List View -->
<div class="row mb-4">
    <div class="col-12">
        <h1 class="display-6 mb-3">
            <i class="bi bi-telephone"></i> Calls Management
        </h1>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-header bg-white">
        <h5 class="card-title mb-0">
            <i class="bi bi-funnel"></i> Filters
        </h5>
    </div>
    <div class="card-body">
        <form id="calls-filter-form">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control" id="filter-date-from" name="date_from">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control" id="filter-date-to" name="date_to">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Call Type</label>
                    <select class="form-select" id="filter-call-type" name="call_type">
                        <option value="">All Types</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="filter-status" name="status">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="pending">Pending</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Agency</label>
                    <select class="form-select" id="filter-agency" name="agency">
                        <option value="">All Agencies</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Priority</label>
                    <select class="form-select" id="filter-priority" name="priority">
                        <option value="">All Priorities</option>
                        <option value="1">Priority 1</option>
                        <option value="2">Priority 2</option>
                        <option value="3">Priority 3</option>
                        <option value="4">Priority 4</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Jurisdiction</label>
                    <select class="form-select" id="filter-jurisdiction" name="jurisdiction">
                        <option value="">All Jurisdictions</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Location Search</label>
                    <input type="text" class="form-control" id="filter-location" name="location" placeholder="Address, city, or place...">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" id="filter-search" name="search" placeholder="Search...">
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Filter
                        </button>
                        <button type="reset" class="btn btn-secondary" id="reset-filters">
                            <i class="bi bi-x-circle"></i> Reset
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Results -->
<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            <i class="bi bi-list-ul"></i> Calls List
            <span class="badge bg-primary ms-2" id="calls-count">0</span>
        </h5>
        <div>
            <button class="btn btn-sm btn-success" id="export-csv">
                <i class="bi bi-download"></i> Export CSV
            </button>
            <button class="btn btn-sm btn-info" id="refresh-calls">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0" id="calls-table">
                <thead class="table-dark">
                    <tr>
                        <th>Incident Number</th>
                        <th>Date/Time</th>
                        <th>Type</th>
                        <th>Location</th>
                        <th>Jurisdiction</th>
                        <th>Agency</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Units</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="calls-table-body">
                    <tr>
                        <td colspan="10" class="text-center py-4">
                            <div class="spinner-border text-primary"></div>
                            <p class="text-muted mt-2">Loading calls...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white">
        <div class="d-flex justify-content-between align-items-center">
            <div id="pagination-info" class="text-muted">
                Showing 0 of 0 calls
            </div>
            <nav>
                <ul class="pagination mb-0" id="pagination">
                </ul>
            </nav>
        </div>
    </div>
</div>

<!-- Call Detail Modal -->
<div class="modal fade" id="call-detail-modal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Call Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="call-detail-content">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary"></div>
                </div>
            </div>
        </div>
    </div>
</div>
