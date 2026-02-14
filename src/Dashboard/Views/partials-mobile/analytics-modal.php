<!-- Mobile Analytics Modal -->
<div class="modal fade" id="mobile-analytics-modal" tabindex="-1" aria-labelledby="mobileAnalyticsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mobileAnalyticsModalLabel">
                    <i class="bi bi-graph-up"></i> Analytics
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Call Volume Chart -->
                <div class="mobile-detail-section">
                    <h6>Call Volume Over Time</h6>
                    <canvas id="mobile-chart-call-volume" height="200"></canvas>
                </div>
                
                <!-- Call Types Chart -->
                <div class="mobile-detail-section">
                    <h6>Call Types Distribution</h6>
                    <canvas id="mobile-chart-call-types" height="200"></canvas>
                </div>
                
                <!-- Priority Distribution -->
                <div class="mobile-detail-section">
                    <h6>Priority Distribution</h6>
                    <canvas id="mobile-chart-priority" height="200"></canvas>
                </div>
                
                <!-- Status Distribution -->
                <div class="mobile-detail-section">
                    <h6>Status Distribution</h6>
                    <canvas id="mobile-chart-status" height="200"></canvas>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
