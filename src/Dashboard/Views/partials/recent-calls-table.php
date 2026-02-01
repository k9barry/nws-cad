<!-- Recent Calls Table - Full Width -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-list-ul"></i> <span id="recent-calls-title">Recent Calls</span> 
                    <span class="badge bg-primary" id="calls-count">0</span>
                </h5>
                <small class="text-muted">Last updated: <span id="last-updated">Never</span></small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                    <table class="table table-hover mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th style="width: 10%;">Call ID</th>
                                <th style="width: 15%;">Received</th>
                                <th style="width: 20%;">Location</th>
                                <th style="width: 15%;">Nature</th>
                                <th style="width: 10%;">Priority</th>
                                <th style="width: 10%;">Status</th>
                                <th style="width: 10%;">Units</th>
                                <th style="width: 10%;">Action</th>
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
            </div>
        </div>
    </div>
</div>
