<!-- System Logs View -->
<style>
    .log-entry {
        font-family: 'Courier New', monospace;
        font-size: 0.85rem;
        margin-bottom: 0.5rem;
        padding: 0.5rem;
        border-left: 3px solid #6c757d;
        background-color: #f8f9fa;
    }
    .log-entry.level-ERROR,
    .log-entry.level-CRITICAL,
    .log-entry.level-ALERT,
    .log-entry.level-EMERGENCY {
        border-left-color: #dc3545;
        background-color: #f8d7da;
    }
    .log-entry.level-WARNING {
        border-left-color: #ffc107;
        background-color: #fff3cd;
    }
    .log-entry.level-INFO,
    .log-entry.level-NOTICE {
        border-left-color: #0dcaf0;
        background-color: #d1ecf1;
    }
    .log-entry.level-DEBUG {
        border-left-color: #6c757d;
        background-color: #e2e3e5;
    }
    .log-timestamp {
        color: #6c757d;
        font-weight: bold;
    }
    .log-level {
        display: inline-block;
        padding: 0.15rem 0.5rem;
        border-radius: 0.25rem;
        font-weight: bold;
        margin: 0 0.5rem;
    }
    .log-message {
        color: #212529;
    }
    .file-item {
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .file-item:hover {
        background-color: #f8f9fa;
    }
    .file-item.active {
        background-color: #e7f1ff;
        border-left: 3px solid #0d6efd;
    }
    .log-container {
        max-height: calc(100vh - var(--log-viewer-offset, 400px));
        overflow-y: auto;
    }
</style>

<div class="row mb-4">
    <div class="col">
        <h2><i class="bi bi-file-text"></i> System Logs</h2>
        <p class="text-muted">View and monitor application logs</p>
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-secondary" id="refresh-btn">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
        <button class="btn btn-outline-danger" id="cleanup-btn" data-bs-toggle="modal" data-bs-target="#cleanupModal">
            <i class="bi bi-trash"></i> Cleanup
        </button>
    </div>
</div>

<div class="row">
    <!-- Sidebar - Log Files -->
    <div class="col-md-3">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Log Files</h5>
            </div>
            <div class="card-body p-0">
                <div id="log-files-list" class="list-group list-group-flush">
                    <div class="text-center py-4">
                        <div class="spinner-border spinner-border-sm text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Logs Widget -->
        <div class="card mt-3">
            <div class="card-header">
                <h6 class="card-title mb-0">Quick View</h6>
            </div>
            <div class="card-body">
                <button class="btn btn-sm btn-outline-primary w-100 mb-2" id="view-recent-btn">
                    <i class="bi bi-clock-history"></i> Last 50 Entries
                </button>
                <button class="btn btn-sm btn-outline-danger w-100 mb-2" id="view-errors-btn">
                    <i class="bi bi-exclamation-triangle"></i> Recent Errors
                </button>
                <button class="btn btn-sm btn-outline-warning w-100" id="view-warnings-btn">
                    <i class="bi bi-exclamation-circle"></i> Recent Warnings
                </button>
            </div>
        </div>
    </div>

    <!-- Main Content - Log Viewer -->
    <div class="col-md-9">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0" id="current-log-title">Select a log file</h5>
                <div>
                    <select class="form-select form-select-sm d-inline-block w-auto" id="level-filter">
                        <option value="">All Levels</option>
                        <option value="DEBUG">DEBUG</option>
                        <option value="INFO">INFO</option>
                        <option value="NOTICE">NOTICE</option>
                        <option value="WARNING">WARNING</option>
                        <option value="ERROR">ERROR</option>
                        <option value="CRITICAL">CRITICAL</option>
                    </select>
                    <select class="form-select form-select-sm d-inline-block w-auto ms-2" id="per-page">
                        <option value="50">50 per page</option>
                        <option value="100" selected>100 per page</option>
                        <option value="200">200 per page</option>
                        <option value="500">500 per page</option>
                    </select>
                </div>
            </div>
            <div class="card-body">
                <div id="log-viewer" class="log-container">
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-file-text fs-1"></i>
                        <p class="mt-2">Select a log file from the sidebar to view its contents</p>
                    </div>
                </div>
                
                <!-- Pagination -->
                <nav id="log-pagination" class="mt-3" style="display: none;">
                    <ul class="pagination justify-content-center">
                        <!-- Pagination will be inserted here -->
                    </ul>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Cleanup Modal -->
<div class="modal fade" id="cleanupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Cleanup Old Logs</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Delete log files older than:</p>
                <select class="form-select" id="cleanup-days">
                    <option value="7">7 days</option>
                    <option value="14">14 days</option>
                    <option value="30" selected>30 days</option>
                    <option value="60">60 days</option>
                    <option value="90">90 days</option>
                </select>
                <div class="alert alert-warning mt-3 mb-0">
                    <i class="bi bi-exclamation-triangle"></i>
                    This action cannot be undone. The current log file (app.log) will not be deleted.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirm-cleanup-btn">Delete Old Logs</button>
            </div>
        </div>
    </div>
</div>
