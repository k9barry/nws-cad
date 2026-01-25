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
        const totalCallsEl = document.getElementById('total-calls');
        if (totalCallsEl) {
            totalCallsEl.textContent = stats.total_calls || 0;
        }
        
        const avgResponseEl = document.getElementById('avg-response-time');
        if (avgResponseEl) {
            const avgMin = stats.response_times?.average_minutes;
            avgResponseEl.textContent = avgMin ? `${avgMin} min` : 'N/A';
        }
        
        const activeUnitsEl = document.getElementById('active-units');
        if (activeUnitsEl) {
            activeUnitsEl.textContent = stats.total_units || 0;
        }
        
        const closureRateEl = document.getElementById('closure-rate');
        if (closureRateEl && stats.calls_by_status) {
            const total = stats.total_calls || 0;
            const closed = stats.calls_by_status.closed || 0;
            const rate = total > 0 ? ((closed / total) * 100).toFixed(1) : '0.0';
            closureRateEl.textContent = `${rate}%`;
        }
    }
    
    function createCharts(stats) {
        console.log('[Analytics] Creating charts...');
        
        // Call types chart
        if (stats.top_call_types && stats.top_call_types.length > 0) {
            const chartEl = document.getElementById('call-types-chart');
            if (chartEl) {
                ChartManager.createDoughnutChart('call-types-chart', {
                    labels: stats.top_call_types.map(t => t.nature_of_call),
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
                console.log('[Analytics] Call types chart created');
            }
        }
        
        // Status chart
        if (stats.calls_by_status) {
            const statusChartEl = document.getElementById('call-status-chart');
            if (statusChartEl) {
                ChartManager.createBarChart('call-status-chart', {
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
                console.log('[Analytics] Status chart created');
            }
        }
        
        // Response times chart
        if (stats.response_times) {
            const responseChartEl = document.getElementById('response-times-chart');
            if (responseChartEl) {
                ChartManager.createBarChart('response-times-chart', {
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
        
        console.log('[Analytics] All charts created');
    }
})();
