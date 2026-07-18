<!-- System Status Modal (version + disk/server health) -->
<div class="modal fade" id="system-status-modal" tabindex="-1" aria-labelledby="systemStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header gradient-card-header">
                <div>
                    <h5 class="modal-title" id="systemStatusModalLabel">
                        <i class="bi bi-hdd-stack"></i> System Status
                    </h5>
                    <div class="small text-white-50">Version &amp; server health</div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary" id="system-status-overall">Loading…</span>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body" id="system-status-body">
                <div class="text-center text-muted py-5" id="system-status-loading">
                    <span class="spinner-border spinner-border-sm"></span>
                    Loading system status…
                </div>
                <div id="system-status-content" class="d-none"></div>
                <div class="alert alert-danger d-none" id="system-status-error" role="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    Unable to load system status.
                </div>
            </div>
            <div class="modal-footer">
                <span class="text-muted small me-auto" id="system-status-timestamp"></span>
                <button type="button" class="btn btn-outline-primary btn-sm" id="system-status-refresh">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
