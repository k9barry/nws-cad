/**
 * Analytics Page Script
 * Handles analytics and reporting
 */

(async function() {
    'use strict';
    
    let currentPeriod = {
        from: null,
        to: null
    };
    
    /**
     * Set date range
     */
    function setDateRange(from, to) {
        currentPeriod.from = from;
        currentPeriod.to = to;
        
        document.getElementById('analytics-date-from').value = from;
        document.getElementById('analytics-date-to').value = to;
    }
    
    /**
     * Handle quick period selection
     */
    document.getElementById('quick-period')?.addEventListener('change', function() {
        const value = this.value;
        const today = new Date();
        let from, to;
        
        to = today.toISOString().split('T')[0];
        
        switch(value) {
            case 'today':
                from = to;
                break;
            case 'yesterday':
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                from = to = yesterday.toISOString().split('T')[0];
                break;
            case '7days':
                const week = new Date(today);
                week.setDate(week.getDate() - 7);
                from = week.toISOString().split('T')[0];
                break;
            case '30days':
                const month = new Date(today);
                month.setDate(month.getDate() - 30);
                from = month.toISOString().split('T')[0];
                break;
            case 'thismonth':
                from = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                break;
            case 'lastmonth':
                const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                from = lastMonth.toISOString().split('T')[0];
                to = new Date(today.getFullYear(), today.getMonth(), 0).toISOString().split('T')[0];
                break;
            default:
                return;
        }
        
        setDateRange(from, to);
    });
    
    /**
     * Load analytics data
     */
    async function loadAnalytics() {
        if (!currentPeriod.from || !currentPeriod.to) {
            Dashboard.showError('Please select a date range');
            return;
        }
        
        const params = {
            date_from: currentPeriod.from,
            date_to: currentPeriod.to
        };
        
        try {
            const [callStats, unitStats, responseStats] = await Promise.all([
                Dashboard.apiRequest('/stats/calls' + Dashboard.buildQueryString(params)),
                Dashboard.apiRequest('/stats/units' + Dashboard.buildQueryString(params)),
                Dashboard.apiRequest('/stats/response-times' + Dashboard.buildQueryString(params))
            ]);
            
            // Update key metrics
            updateKeyMetrics(callStats, responseStats);
            
            // Update charts
            updateCharts(callStats, unitStats, responseStats);
            
            // Update top lists
            updateTopLists(callStats);
            
        } catch (error) {
            console.error('Error loading analytics:', error);
            Dashboard.showError('Failed to load analytics data');
        }
    }
    
    /**
     * Update key metrics
     */
    function updateKeyMetrics(callStats, responseStats) {
        document.getElementById('analytics-total-calls').textContent = 
            callStats.total_count?.toLocaleString() || '0';
        
        document.getElementById('analytics-avg-response').textContent = 
            Dashboard.formatDuration(responseStats.average_response_time || 0);
        
        if (callStats.busiest_hour) {
            document.getElementById('analytics-busiest-hour').textContent = 
                callStats.busiest_hour.hour || 'N/A';
            document.getElementById('analytics-busiest-count').textContent = 
                `${callStats.busiest_hour.count || 0} calls`;
        }
        
        if (callStats.top_unit) {
            document.getElementById('analytics-top-unit').textContent = 
                callStats.top_unit.unit_id || 'N/A';
            document.getElementById('analytics-unit-calls').textContent = 
                `${callStats.top_unit.call_count || 0} calls`;
        }
        
        // Show change indicators (if available)
        if (callStats.change_percentage !== undefined) {
            const change = callStats.change_percentage;
            const arrow = change > 0 ? '↑' : change < 0 ? '↓' : '→';
            const color = change > 0 ? 'text-success' : change < 0 ? 'text-danger' : 'text-muted';
            document.getElementById('analytics-calls-change').innerHTML = 
                `<span class="${color}">${arrow} ${Math.abs(change)}%</span>`;
        }
    }
    
    /**
     * Update charts
     */
    function updateCharts(callStats, unitStats, responseStats) {
        // Volume chart
        const volumeData = ChartManager.prepareCallVolumeData(callStats);
        ChartManager.createLineChart('analytics-volume-chart', volumeData);
        
        // Distribution chart
        const distributionData = ChartManager.prepareCallTypesData(callStats);
        ChartManager.createDoughnutChart('analytics-distribution-chart', distributionData);
        
        // Response time chart
        const responseData = ChartManager.prepareResponseTimeData(responseStats);
        ChartManager.createLineChart('analytics-response-chart', responseData);
        
        // Agency chart
        const agencyData = ChartManager.prepareAgencyData(callStats);
        ChartManager.createBarChart('analytics-agency-chart', agencyData);
    }
    
    /**
     * Update top lists
     */
    function updateTopLists(callStats) {
        // Top call types
        const topTypes = document.getElementById('top-call-types');
        if (callStats.by_type && callStats.by_type.length > 0) {
            topTypes.innerHTML = callStats.by_type.slice(0, 10).map((item, index) => `
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-primary me-2">#${index + 1}</span>
                            <strong>${item.type}</strong>
                        </div>
                        <span class="badge bg-secondary">${item.count}</span>
                    </div>
                </div>
            `).join('');
        } else {
            topTypes.innerHTML = '<div class="text-center py-3 text-muted">No data</div>';
        }
        
        // Top locations
        const topLocations = document.getElementById('top-locations');
        if (callStats.by_location && callStats.by_location.length > 0) {
            topLocations.innerHTML = callStats.by_location.slice(0, 10).map((item, index) => `
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-primary me-2">#${index + 1}</span>
                            <strong>${item.location}</strong>
                        </div>
                        <span class="badge bg-secondary">${item.count}</span>
                    </div>
                </div>
            `).join('');
        } else {
            topLocations.innerHTML = '<div class="text-center py-3 text-muted">No data</div>';
        }
        
        // Top units
        const topUnits = document.getElementById('top-units');
        if (callStats.by_unit && callStats.by_unit.length > 0) {
            topUnits.innerHTML = callStats.by_unit.slice(0, 10).map((item, index) => `
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-primary me-2">#${index + 1}</span>
                            <strong>${item.unit_id}</strong>
                        </div>
                        <span class="badge bg-secondary">${item.count}</span>
                    </div>
                </div>
            `).join('');
        } else {
            topUnits.innerHTML = '<div class="text-center py-3 text-muted">No data</div>';
        }
    }
    
    /**
     * Handle volume grouping change
     */
    document.querySelectorAll('input[name="volume-grouping"]').forEach(input => {
        input.addEventListener('change', function() {
            // Reload analytics with new grouping
            loadAnalytics();
        });
    });
    
    /**
     * Handle form submission
     */
    document.getElementById('analytics-period-form')?.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const from = document.getElementById('analytics-date-from').value;
        const to = document.getElementById('analytics-date-to').value;
        
        if (!from || !to) {
            Dashboard.showError('Please select both start and end dates');
            return;
        }
        
        if (new Date(from) > new Date(to)) {
            Dashboard.showError('Start date must be before end date');
            return;
        }
        
        setDateRange(from, to);
        loadAnalytics();
    });
    
    /**
     * Export summary report
     */
    document.getElementById('export-summary-csv')?.addEventListener('click', async function() {
        if (!currentPeriod.from || !currentPeriod.to) {
            Dashboard.showError('Please generate a report first');
            return;
        }
        
        try {
            const params = {
                date_from: currentPeriod.from,
                date_to: currentPeriod.to
            };
            
            const callStats = await Dashboard.apiRequest('/stats/calls' + Dashboard.buildQueryString(params));
            
            const exportData = [{
                'Period': `${currentPeriod.from} to ${currentPeriod.to}`,
                'Total Calls': callStats.total_count || 0,
                'Average Response Time': callStats.avg_response_time || 0,
                'Top Call Type': callStats.by_type?.[0]?.type || 'N/A',
                'Busiest Hour': callStats.busiest_hour?.hour || 'N/A'
            }];
            
            Dashboard.exportToCSV(exportData, `analytics-summary-${currentPeriod.from}-${currentPeriod.to}.csv`);
            
        } catch (error) {
            Dashboard.showError('Failed to export report');
        }
    });
    
    /**
     * Export detailed report
     */
    document.getElementById('export-detailed-csv')?.addEventListener('click', async function() {
        if (!currentPeriod.from || !currentPeriod.to) {
            Dashboard.showError('Please generate a report first');
            return;
        }
        
        try {
            const params = {
                date_from: currentPeriod.from,
                date_to: currentPeriod.to,
                per_page: 1000
            };
            
            const calls = await Dashboard.apiRequest('/calls' + Dashboard.buildQueryString(params));
            
            const exportData = calls.map(call => ({
                'Call ID': call.id,
                'Date': call.received_time,
                'Type': call.call_type,
                'Priority': call.priority,
                'Status': call.status,
                'Address': call.address,
                'Agency': call.agency,
                'Response Time': call.response_time || 'N/A'
            }));
            
            Dashboard.exportToCSV(exportData, `analytics-detailed-${currentPeriod.from}-${currentPeriod.to}.csv`);
            
        } catch (error) {
            Dashboard.showError('Failed to export detailed report');
        }
    });
    
    // Set default date range (last 7 days)
    const today = new Date();
    const weekAgo = new Date(today);
    weekAgo.setDate(weekAgo.getDate() - 7);
    
    setDateRange(
        weekAgo.toISOString().split('T')[0],
        today.toISOString().split('T')[0]
    );
    
    // Initial load
    await loadAnalytics();
    
})();
