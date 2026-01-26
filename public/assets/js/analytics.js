/**
 * Analytics Page Script
 * Handles advanced analytics and reporting
 */

(function() {
    'use strict';
    
    console.log('[Analytics] Script loaded');
    
    async function init() {
        if (typeof Dashboard === 'undefined' || typeof ChartManager === 'undefined') {
            console.error('[Analytics] Dependencies not found, retrying...');
            setTimeout(init, 100);
            return;
        }
        
        console.log('[Analytics] Initializing analytics page...');
        await loadAnalytics();
        
        // Setup auto-refresh with longer interval
        if (Dashboard.setupAutoRefresh) {
            Dashboard.setupAutoRefresh(loadAnalytics, 60000); // 1 minute
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    async function loadAnalytics() {
        console.log('[Analytics] Loading analytics data...');
        
        try {
            const stats = await Dashboard.apiRequest('/stats');
            console.log('[Analytics] Stats:', stats);
            
            // Update summary cards
            updateSummaryCards(stats);
            
            // Create charts
            createCharts(stats);
            
            console.log('[Analytics] Analytics loaded successfully');
            
        } catch (error) {
            console.error('[Analytics] Error loading analytics:', error);
            if (Dashboard.showError) {
                Dashboard.showError('Failed to load analytics: ' + error.message);
            }
        }
    }
    
    function updateSummaryCards(stats) {
        const totalCallsEl = document.getElementById('analytics-total-calls');
        if (totalCallsEl) {
            totalCallsEl.textContent = stats.total_calls || 0;
        }
        
        const avgResponseEl = document.getElementById('analytics-avg-response');
        if (avgResponseEl) {
            const avgMin = stats.response_times?.average_minutes;
            avgResponseEl.textContent = avgMin ? `${avgMin} min` : 'N/A';
        }
        
        const activeUnitsEl = document.getElementById('analytics-active-units');
        if (activeUnitsEl) {
            activeUnitsEl.textContent = stats.total_units || 0;
        }
        
        const closureRateEl = document.getElementById('analytics-closure-rate');
        if (closureRateEl && stats.calls_by_status) {
            const total = stats.total_calls || 0;
            const closed = stats.calls_by_status.closed || 0;
            const rate = total > 0 ? ((closed / total) * 100).toFixed(1) : '0.0';
            closureRateEl.textContent = `${rate}%`;
        }
    }
    
    function createCharts(stats) {
        console.log('[Analytics] Creating charts...');
        
        // Call types chart (for distribution)
        if (stats.top_call_types && stats.top_call_types.length > 0) {
            const chartEl = document.getElementById('analytics-distribution-chart');
            if (chartEl) {
                ChartManager.createDoughnutChart('analytics-distribution-chart', {
                    labels: stats.top_call_types.map(t => t.nature_of_call || 'Unknown'),
                    datasets: [{
                        data: stats.top_call_types.map(t => t.count),
                        backgroundColor: [
                            'rgb(255, 99, 132)',
                            'rgb(54, 162, 235)',
                            'rgb(255, 205, 86)',
                            'rgb(75, 192, 192)',
                            'rgb(153, 102, 255)',
                            'rgb(255, 159, 64)'
                        ]
                    }]
                });
                console.log('[Analytics] Distribution chart created');
            }
        }
        
        // Status chart
        if (stats.calls_by_status) {
            const statusChartEl = document.getElementById('analytics-volume-chart');
            if (statusChartEl) {
                ChartManager.createBarChart('analytics-volume-chart', {
                    labels: ['Open', 'Closed', 'Canceled'],
                    datasets: [{
                        label: 'Call Status',
                        data: [
                            stats.calls_by_status.open || 0,
                            stats.calls_by_status.closed || 0,
                            stats.calls_by_status.canceled || 0
                        ],
                        backgroundColor: [
                            'rgba(255, 205, 86, 0.6)',
                            'rgba(75, 192, 192, 0.6)',
                            'rgba(201, 203, 207, 0.6)'
                        ]
                    }]
                });
                console.log('[Analytics] Volume chart created');
            }
        }
        
        // Response times chart
        if (stats.response_times) {
            const responseChartEl = document.getElementById('analytics-response-chart');
            if (responseChartEl) {
                ChartManager.createBarChart('analytics-response-chart', {
                    labels: ['Min', 'Average', 'Max'],
                    datasets: [{
                        label: 'Response Time (minutes)',
                        data: [
                            stats.response_times.min_minutes || 0,
                            stats.response_times.average_minutes || 0,
                            stats.response_times.max_minutes || 0
                        ],
                        backgroundColor: [
                            'rgba(75, 192, 192, 0.6)',
                            'rgba(54, 162, 235, 0.6)',
                            'rgba(255, 99, 132, 0.6)'
                        ]
                    }]
                });
                console.log('[Analytics] Response times chart created');
            }
        }
        
        // Update top call types list
        const topCallsEl = document.getElementById('top-call-types');
        if (topCallsEl && stats.top_call_types && stats.top_call_types.length > 0) {
            topCallsEl.innerHTML = stats.top_call_types.map((type, idx) => `
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-primary me-2">${idx + 1}</span>
                            ${type.nature_of_call || 'Unknown'}
                        </div>
                        <span class="badge bg-secondary">${type.count}</span>
                    </div>
                </div>
            `).join('');
        }
        
        console.log('[Analytics] All charts created');
    }
})();
