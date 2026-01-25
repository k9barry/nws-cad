/**
 * Dashboard Main Page Script
 * Handles the main dashboard overview page
 */

(function() {
    'use strict';
    
    console.log('[Dashboard Main] Script loaded');
    console.log('[Dashboard Main] Document state:', document.readyState);
    console.log('[Dashboard Main] Dashboard available:', typeof Dashboard !== 'undefined');
    console.log('[Dashboard Main] MapManager available:', typeof MapManager !== 'undefined');
    console.log('[Dashboard Main] ChartManager available:', typeof ChartManager !== 'undefined');
    
    // Wait for both DOM and Dashboard to be ready
    async function init() {
        console.log('[Dashboard Main] Init called');
        
        if (typeof Dashboard === 'undefined') {
            console.error('[Dashboard Main] Dashboard object not found, retrying in 100ms...');
            setTimeout(init, 100);
            return;
        }
        
        console.log('[Dashboard Main] Dashboard config:', Dashboard.config);
        console.log('[Dashboard Main] API Base URL:', Dashboard.config.apiBaseUrl);
        
        await initDashboard();
    }
    
    // Check DOM ready state
    if (document.readyState === 'loading') {
        console.log('[Dashboard Main] Waiting for DOM...');
        document.addEventListener('DOMContentLoaded', init);
    } else {
        console.log('[Dashboard Main] DOM already ready');
        init();
    }
    
    async function initDashboard() {
        console.log('[Dashboard Main] Starting dashboard initialization...');
        
        // Check managers availability
        const managers = {
            MapManager: typeof MapManager !== 'undefined',
            ChartManager: typeof ChartManager !== 'undefined'
        };
        
        console.log('[Dashboard Main] Managers:', managers);
        
        // Initialize map
        let map = null;
        if (managers.MapManager) {
            try {
                const mapEl = document.getElementById('calls-map');
                if (mapEl) {
                    map = MapManager.initMap('calls-map');
                    console.log('[Dashboard Main] Map initialized');
                } else {
                    console.warn('[Dashboard Main] Map element not found');
                }
            } catch (error) {
                console.error('[Dashboard Main] Map init failed:', error);
            }
        }
        
        /**
         * Load statistics
         */
        async function loadStats() {
            console.log('[Dashboard Main] Loading stats...');
            try {
                const stats = await Dashboard.apiRequest('/stats');
                console.log('[Dashboard Main] Stats response:', stats);
                
                const elements = {
                    activeCalls: document.getElementById('stat-active-calls'),
                    availableUnits: document.getElementById('stat-available-units'),
                    avgResponse: document.getElementById('stat-avg-response'),
                    callsToday: document.getElementById('stat-calls-today')
                };
                
                if (elements.activeCalls) {
                    elements.activeCalls.textContent = stats.calls_by_status?.open || 0;
                }
                if (elements.availableUnits) {
                    elements.availableUnits.textContent = stats.total_units || 0;
                }
                if (elements.avgResponse) {
                    const avgMin = stats.response_times?.average_minutes;
                    elements.avgResponse.textContent = avgMin ? `${avgMin} min` : 'N/A';
                }
                if (elements.callsToday) {
                    elements.callsToday.textContent = stats.total_calls || 0;
                }
                
                console.log('[Dashboard Main] Stats updated');
            } catch (error) {
                console.error('[Dashboard Main] Stats error:', error);
                if (Dashboard.showError) {
                    Dashboard.showError('Failed to load statistics');
                }
            }
        }
        
        /**
         * Load recent calls
         */
        async function loadRecentCalls() {
            console.log('[Dashboard Main] Loading recent calls...');
            try {
                const url = '/calls' + Dashboard.buildQueryString({
                    page: 1,
                    per_page: 10,
                    sort: 'create_datetime',
                    order: 'desc'
                });
                
                console.log('[Dashboard Main] Fetching:', Dashboard.config.apiBaseUrl + url);
                
                const response = await fetch(Dashboard.config.apiBaseUrl + url);
                const result = await response.json();
                
                console.log('[Dashboard Main] Calls response:', result);
                
                if (!result.success) {
                    throw new Error(result.error || 'API failed');
                }
                
                const calls = result.data || [];
                const container = document.getElementById('recent-calls');
                
                if (!container) {
                    console.warn('[Dashboard Main] recent-calls container not found');
                    return;
                }
                
                if (calls.length === 0) {
                    container.innerHTML = `
                        <div class="empty-state text-center py-4">
                            <i class="bi bi-inbox fs-1 text-muted"></i>
                            <p class="text-muted mt-2">No recent calls</p>
                        </div>
                    `;
                } else {
                    container.innerHTML = calls.map(call => `
                        <div class="recent-call-item p-3 mb-2 border rounded" style="cursor: pointer;" onclick="viewCallDetails(${call.id})">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <div class="fw-bold">${call.nature_of_call || 'Unknown'}</div>
                                <small class="text-muted">${Dashboard.formatTime(call.create_datetime)}</small>
                            </div>
                            <div class="text-muted small">
                                <i class="bi bi-geo-alt"></i> ${call.full_address || call.city || 'No address'}
                            </div>
                            <div class="mt-2">
                                <span class="badge ${call.closed_flag ? 'bg-success' : 'bg-warning'}">
                                    ${call.closed_flag ? 'Closed' : 'Open'}
                                </span>
                                ${call.call_number ? `<span class="badge bg-secondary">#${call.call_number}</span>` : ''}
                                ${call.unit_count ? `<span class="badge bg-info">${call.unit_count} units</span>` : ''}
                            </div>
                        </div>
                    `).join('');
                }
                
                console.log('[Dashboard Main] Recent calls rendered:', calls.length);
            } catch (error) {
                console.error('[Dashboard Main] Recent calls error:', error);
                if (Dashboard.showError) {
                    Dashboard.showError('Failed to load recent calls');
                }
            }
        }
        
        /**
         * Load map calls
         */
        async function loadCallsMap() {
            if (!map) {
                console.log('[Dashboard Main] Map not available, skipping');
                return;
            }
            
            console.log('[Dashboard Main] Loading map calls...');
            try {
                const url = '/calls' + Dashboard.buildQueryString({
                    page: 1,
                    per_page: 100,
                    closed_flag: 0
                });
                
                const response = await fetch(Dashboard.config.apiBaseUrl + url);
                const result = await response.json();
                
                if (result.success && result.data) {
                    const callsWithLoc = result.data
                        .filter(c => c.latitude_y && c.longitude_x)
                        .map(c => ({
                            ...c,
                            latitude: parseFloat(c.latitude_y),
                            longitude: parseFloat(c.longitude_x),
                            address: c.full_address || c.city
                        }));
                    
                    console.log('[Dashboard Main] Map calls:', callsWithLoc.length);
                    
                    if (callsWithLoc.length > 0) {
                        MapManager.showCalls('calls-map', callsWithLoc);
                    }
                }
            } catch (error) {
                console.error('[Dashboard Main] Map calls error:', error);
            }
        }
        
        /**
         * Load charts
         */
        async function loadCharts() {
            if (!managers.ChartManager) {
                console.log('[Dashboard Main] ChartManager not available');
                return;
            }
            
            console.log('[Dashboard Main] Loading charts...');
            try {
                const stats = await Dashboard.apiRequest('/stats');
                
                // Call types chart
                if (stats.top_call_types?.length > 0 && document.getElementById('call-types-chart')) {
                    ChartManager.createDoughnutChart('call-types-chart', {
                        labels: stats.top_call_types.map(t => t.nature_of_call),
                        datasets: [{
                            data: stats.top_call_types.map(t => t.count),
                            backgroundColor: [
                                'rgb(255, 99, 132)', 'rgb(54, 162, 235)',
                                'rgb(255, 205, 86)', 'rgb(75, 192, 192)',
                                'rgb(153, 102, 255)', 'rgb(255, 159, 64)'
                            ]
                        }]
                    });
                    console.log('[Dashboard Main] Call types chart created');
                }
                
                // Status chart
                if (stats.calls_by_status && document.getElementById('unit-activity-chart')) {
                    ChartManager.createBarChart('unit-activity-chart', {
                        labels: ['Open', 'Closed', 'Canceled'],
                        datasets: [{
                            label: 'Calls',
                            data: [
                                stats.calls_by_status.open,
                                stats.calls_by_status.closed,
                                stats.calls_by_status.canceled
                            ],
                            backgroundColor: ['rgb(255, 205, 86)', 'rgb(75, 192, 192)', 'rgb(201, 203, 207)']
                        }]
                    });
                    console.log('[Dashboard Main] Status chart created');
                }
            } catch (error) {
                console.error('[Dashboard Main] Charts error:', error);
            }
        }
        
        /**
         * Refresh all data
         */
        async function refreshDashboard() {
            console.log('[Dashboard Main] === Refreshing ===');
            try {
                await Promise.all([
                    loadStats(),
                    loadRecentCalls(),
                    loadCallsMap(),
                    loadCharts()
                ]);
                console.log('[Dashboard Main] === Refresh complete ===');
            } catch (error) {
                console.error('[Dashboard Main] Refresh error:', error);
            }
        }
        
        // Global function for call details
        window.viewCallDetails = function(callId) {
            window.location.href = `/calls?id=${callId}`;
        };
        
        // Initial load
        console.log('[Dashboard Main] Starting initial load...');
        await refreshDashboard();
        
        // Auto-refresh
        if (Dashboard.setupAutoRefresh) {
            Dashboard.setupAutoRefresh(refreshDashboard);
            console.log('[Dashboard Main] Auto-refresh enabled');
        }
        
        console.log('[Dashboard Main] ✓ Initialization complete');
    }
})();
