/**
 * Dashboard Main Page Script
 * Handles the main dashboard overview page
 */

(async function() {
    'use strict';
    
    // Initialize map
    const map = MapManager.initMap('calls-map');
    
    /**
     * Load dashboard statistics
     */
    async function loadStats() {
        try {
            const [callStats, unitStats, responseStats] = await Promise.all([
                Dashboard.apiRequest('/stats/calls'),
                Dashboard.apiRequest('/stats/units'),
                Dashboard.apiRequest('/stats/response-times')
            ]);
            
            // Update stat cards
            document.getElementById('stat-active-calls').textContent = callStats.active_count || 0;
            document.getElementById('stat-available-units').textContent = unitStats.available_count || 0;
            document.getElementById('stat-avg-response').textContent = 
                Dashboard.formatDuration(responseStats.average_response_time || 0);
            document.getElementById('stat-calls-today').textContent = callStats.today_count || 0;
            
        } catch (error) {
            console.error('Error loading stats:', error);
        }
    }
    
    /**
     * Load recent calls
     */
    async function loadRecentCalls() {
        try {
            const params = {
                page: 1,
                per_page: 10,
                sort: 'received_time',
                order: 'desc'
            };
            
            const calls = await Dashboard.apiRequest('/calls' + Dashboard.buildQueryString(params));
            
            const container = document.getElementById('recent-calls');
            
            if (!calls || calls.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>No recent calls</p>
                    </div>
                `;
                return;
            }
            
            container.innerHTML = calls.map(call => `
                <div class="recent-call-item" onclick="viewCallDetails(${call.id})">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div class="recent-call-type">${call.call_type || 'Unknown'}</div>
                        <small class="recent-call-time">${Dashboard.timeAgo(call.received_time)}</small>
                    </div>
                    <div class="recent-call-location">
                        <i class="bi bi-geo-alt"></i> ${call.address || 'No address'}
                    </div>
                    <div class="mt-2">
                        ${Dashboard.getPriorityBadge(call.priority)}
                        ${Dashboard.getStatusBadge(call.status)}
                    </div>
                </div>
            `).join('');
            
        } catch (error) {
            console.error('Error loading recent calls:', error);
        }
    }
    
    /**
     * Load call locations on map
     */
    async function loadCallsMap() {
        try {
            const params = {
                page: 1,
                per_page: 100,
                status: 'active'
            };
            
            const calls = await Dashboard.apiRequest('/calls' + Dashboard.buildQueryString(params));
            
            if (calls && calls.length > 0) {
                // Get calls with location data
                const callsWithLocation = [];
                for (const call of calls) {
                    try {
                        const location = await Dashboard.apiRequest(`/calls/${call.id}/location`);
                        if (location && location.latitude && location.longitude) {
                            callsWithLocation.push({
                                ...call,
                                latitude: location.latitude,
                                longitude: location.longitude,
                                address: location.address
                            });
                        }
                    } catch (e) {
                        // Skip calls without location
                    }
                }
                
                MapManager.showCalls('calls-map', callsWithLocation);
            }
            
        } catch (error) {
            console.error('Error loading calls map:', error);
        }
    }
    
    /**
     * Load charts
     */
    async function loadCharts() {
        try {
            const [callStats, unitStats] = await Promise.all([
                Dashboard.apiRequest('/stats/calls'),
                Dashboard.apiRequest('/stats/units')
            ]);
            
            // Call volume trend chart
            if (callStats) {
                const volumeData = ChartManager.prepareCallVolumeData(callStats);
                ChartManager.createLineChart('calls-trend-chart', volumeData);
            }
            
            // Call types distribution chart
            if (callStats) {
                const typesData = ChartManager.prepareCallTypesData(callStats);
                ChartManager.createDoughnutChart('call-types-chart', typesData);
            }
            
            // Unit activity chart
            if (unitStats) {
                const activityData = ChartManager.prepareUnitActivityData(unitStats);
                ChartManager.createBarChart('unit-activity-chart', activityData);
            }
            
        } catch (error) {
            console.error('Error loading charts:', error);
        }
    }
    
    /**
     * Refresh all dashboard data
     */
    async function refreshDashboard() {
        await Promise.all([
            loadStats(),
            loadRecentCalls(),
            loadCallsMap(),
            loadCharts()
        ]);
    }
    
    /**
     * View call details (called from map popup)
     */
    window.viewCallDetails = async function(callId) {
        try {
            const call = await Dashboard.apiRequest(`/calls/${callId}`);
            // For now, just show an alert - could open a modal
            alert(`Call Details:\nID: ${call.id}\nType: ${call.call_type}\nStatus: ${call.status}`);
        } catch (error) {
            Dashboard.showError('Failed to load call details');
        }
    };
    
    // Initial load
    await refreshDashboard();
    
    // Setup auto-refresh
    Dashboard.setupAutoRefresh(refreshDashboard);
    
})();
