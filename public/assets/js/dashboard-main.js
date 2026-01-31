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
        
        // Global filter state
        let currentFilters = {};
        
        // Initialize date filters with default (last 7 days)
        function initializeFilters() {
            const dateFromInput = document.getElementById('dashboard-date-from');
            const dateToInput = document.getElementById('dashboard-date-to');
            const quickPeriod = document.getElementById('dashboard-quick-period');
            const jurisdictionSelect = document.getElementById('dashboard-jurisdiction');
            
            // Set default dates (last 7 days)
            if (dateFromInput && dateToInput) {
                const now = new Date();
                const sevenDaysAgo = new Date(now.getTime() - (7 * 24 * 60 * 60 * 1000));
                
                dateToInput.value = new Date().toISOString().split('T')[0];
                dateFromInput.value = sevenDaysAgo.toISOString().split('T')[0];
                
                currentFilters.date_from = dateFromInput.value;
                currentFilters.date_to = dateToInput.value;
                
                // Set the quick period dropdown to match
                if (quickPeriod) {
                    quickPeriod.value = '7days';
                }
            }
            
            // Handle quick period selection
            if (quickPeriod) {
                quickPeriod.addEventListener('change', () => {
                    const period = quickPeriod.value;
                    const now = new Date();
                    let fromDate = new Date();
                    
                    switch(period) {
                        case 'today':
                            fromDate = new Date(now.setHours(0,0,0,0));
                            break;
                        case 'yesterday':
                            fromDate = new Date(now.setDate(now.getDate() - 1));
                            fromDate.setHours(0,0,0,0);
                            now.setHours(23,59,59,999);
                            break;
                        case '7days':
                            fromDate = new Date(now.getTime() - (7 * 24 * 60 * 60 * 1000));
                            break;
                        case '30days':
                            fromDate = new Date(now.getTime() - (30 * 24 * 60 * 60 * 1000));
                            break;
                        case 'thismonth':
                            fromDate = new Date(now.getFullYear(), now.getMonth(), 1);
                            break;
                        case 'lastmonth':
                            fromDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                            now.setDate(0); // Last day of previous month
                            break;
                        case 'custom':
                            return; // Don't auto-update dates for custom
                    }
                    
                    if (period !== 'custom' && dateFromInput && dateToInput) {
                        dateFromInput.value = fromDate.toISOString().split('T')[0];
                        dateToInput.value = new Date().toISOString().split('T')[0];
                    }
                });
            }
            
            // Load jurisdictions
            loadJurisdictions();
        }
        
        // Load jurisdictions for filter
        async function loadJurisdictions() {
            try {
                const stats = await Dashboard.apiRequest('/stats');
                const jurisdictionSelect = document.getElementById('dashboard-jurisdiction');
                
                if (jurisdictionSelect && stats.calls_by_jurisdiction) {
                    stats.calls_by_jurisdiction.forEach(j => {
                        const option = document.createElement('option');
                        option.value = j.jurisdiction;
                        option.textContent = j.jurisdiction;
                        jurisdictionSelect.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('[Dashboard Main] Error loading jurisdictions:', error);
            }
        }
        
        // Setup filter form
        function setupFilterForm() {
            const filterForm = document.getElementById('dashboard-filter-form');
            
            if (filterForm) {
                filterForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    
                    // Update filters from form
                    const dateFrom = document.getElementById('dashboard-date-from')?.value;
                    const dateTo = document.getElementById('dashboard-date-to')?.value;
                    const agency = document.getElementById('dashboard-agency')?.value;
                    const jurisdiction = document.getElementById('dashboard-jurisdiction')?.value;
                    
                    // Update filters (preserve existing, override with new values)
                    if (dateFrom) {
                        currentFilters.date_from = dateFrom;
                    } else {
                        delete currentFilters.date_from;
                    }
                    
                    if (dateTo) {
                        currentFilters.date_to = dateTo;
                    } else {
                        delete currentFilters.date_to;
                    }
                    
                    if (agency) {
                        currentFilters.agency_type = agency;
                    } else {
                        delete currentFilters.agency_type;
                    }
                    
                    if (jurisdiction) {
                        currentFilters.jurisdiction = jurisdiction;
                    } else {
                        delete currentFilters.jurisdiction;
                    }
                    
                    console.log('[Dashboard Main] Updated filters:', currentFilters);
                    
                    // Reload all data with new filters
                    loadStats();
                    loadRecentCalls();
                    loadCallsMap();
                    loadCharts();
                });
            }
        }
        
        // Initialize filters
        initializeFilters();
        setupFilterForm();
        
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
                const url = '/stats' + Dashboard.buildQueryString(currentFilters);
                const stats = await Dashboard.apiRequest(url);
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
         * Load active calls (status=open)
         */
        async function loadRecentCalls() {
            console.log('[Dashboard Main] Loading active calls...');
            try {
                const queryParams = {
                    page: 1,
                    per_page: 10,
                    sort: 'create_datetime',
                    order: 'desc',
                    closed_flag: '0',
                    ...currentFilters
                };
                
                const url = '/calls' + Dashboard.buildQueryString(queryParams);
                
                console.log('[Dashboard Main] Fetching:', Dashboard.config.apiBaseUrl + url);
                
                const response = await fetch(Dashboard.config.apiBaseUrl + url);
                const result = await response.json();
                
                console.log('[Dashboard Main] Calls response:', result);
                
                if (!result.success) {
                    throw new Error(result.error || 'API failed');
                }
                
                const calls = result.data?.items || [];
                const container = document.getElementById('recent-calls');
                
                if (!container) {
                    console.warn('[Dashboard Main] recent-calls container not found');
                    return;
                }
                
                if (calls.length === 0) {
                    container.innerHTML = `
                        <div class="empty-state text-center py-4">
                            <i class="bi bi-inbox fs-1 text-muted"></i>
                            <p class="text-muted mt-2">No active calls</p>
                        </div>
                    `;
                } else {
                    container.innerHTML = calls.map(call => `
                        <div class="recent-call-item p-3 mb-2 border rounded" style="cursor: pointer;" onclick="viewCallDetails(${call.id})">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <div class="fw-bold">${call.call_types?.[0] || call.nature_of_call || 'Unknown'}</div>
                                <small class="text-muted">${Dashboard.formatTime(call.create_datetime)}</small>
                            </div>
                            <div class="text-muted small">
                                <i class="bi bi-geo-alt"></i> ${call.location?.address || call.location?.city || 'No address'}
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
                
                console.log('[Dashboard Main] Active calls rendered:', calls.length);
            } catch (error) {
                console.error('[Dashboard Main] Active calls error:', error);
                if (Dashboard.showError) {
                    Dashboard.showError('Failed to load active calls');
                }
            }
        }
        
        /**
         * Load map calls
         */
        async function loadCallsMap() {
            console.log('[Dashboard Main] Loading map calls...');
            
            // Check if map manager is available
            if (typeof MapManager === 'undefined') {
                console.error('[Dashboard Main] MapManager not available');
                return;
            }
            
            try {
                const queryParams = {
                    page: 1,
                    per_page: 100,
                    closed_flag: 0,
                    ...currentFilters
                };
                
                const url = '/calls' + Dashboard.buildQueryString(queryParams);
                
                console.log('[Dashboard Main] Fetching open calls from:', Dashboard.config.apiBaseUrl + url);
                const response = await fetch(Dashboard.config.apiBaseUrl + url);
                const result = await response.json();
                
                console.log('[Dashboard Main] Map calls response:', result);
                
                if (result.success && result.data?.items) {
                    const callsWithLoc = result.data.items
                        .filter(c => c.location?.coordinates?.lat && c.location?.coordinates?.lng)
                        .map(c => ({
                            ...c,
                            latitude: parseFloat(c.location.coordinates.lat),
                            longitude: parseFloat(c.location.coordinates.lng),
                            address: c.location?.address || c.location?.city
                        }));
                    
                    console.log('[Dashboard Main] Calls with location:', callsWithLoc.length);
                    
                    if (callsWithLoc.length > 0) {
                        // Make sure calls-map element exists
                        const mapElement = document.getElementById('calls-map');
                        if (mapElement) {
                            console.log('[Dashboard Main] Showing', callsWithLoc.length, 'calls on map');
                            MapManager.showCalls('calls-map', callsWithLoc);
                            console.log('[Dashboard Main] Map updated and centered on calls');
                        } else {
                            console.warn('[Dashboard Main] calls-map element not found');
                        }
                    } else {
                        console.log('[Dashboard Main] No calls with location data');
                    }
                } else {
                    console.error('[Dashboard Main] API error:', result.error);
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
            console.log('[Dashboard Main] Current filters for charts:', currentFilters);
            try {
                const url = '/stats' + Dashboard.buildQueryString(currentFilters);
                console.log('[Dashboard Main] Charts API URL:', url);
                const stats = await Dashboard.apiRequest(url);
                console.log('[Dashboard Main] Charts stats received:', stats);
                
                // Call volume trends chart (by jurisdiction)
                const trendChartEl = document.getElementById('calls-trend-chart');
                if (trendChartEl) {
                    if (stats.calls_by_jurisdiction?.length > 0) {
                        const colors = [
                            'rgba(255, 99, 132, 0.6)',
                            'rgba(54, 162, 235, 0.6)',
                            'rgba(255, 205, 86, 0.6)',
                            'rgba(75, 192, 192, 0.6)',
                            'rgba(153, 102, 255, 0.6)',
                            'rgba(255, 159, 64, 0.6)',
                            'rgba(199, 199, 199, 0.6)',
                            'rgba(83, 102, 255, 0.6)',
                            'rgba(255, 99, 255, 0.6)',
                            'rgba(99, 255, 132, 0.6)'
                        ];
                        const borderColors = colors.map(c => c.replace('0.6', '1'));
                        
                        ChartManager.createBarChart('calls-trend-chart', {
                            labels: stats.calls_by_jurisdiction.map(j => j.jurisdiction),
                            datasets: [{
                                label: 'Calls by Jurisdiction',
                                data: stats.calls_by_jurisdiction.map(j => j.count),
                                backgroundColor: colors.slice(0, stats.calls_by_jurisdiction.length),
                                borderColor: borderColors.slice(0, stats.calls_by_jurisdiction.length),
                                borderWidth: 2
                            }]
                        });
                        console.log('[Dashboard Main] Call volume trend chart by jurisdiction created');
                    } else {
                        ChartManager.showEmptyChart('calls-trend-chart', 'No jurisdiction data available');
                    }
                }
                
                // Call types chart
                const typesChartEl = document.getElementById('call-types-chart');
                if (typesChartEl) {
                    if (stats.top_call_types?.length > 0) {
                        ChartManager.createDoughnutChart('call-types-chart', {
                            labels: stats.top_call_types.map(t => `${t.call_type || t.nature_of_call || 'Unknown'} (${t.count})`),
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
                    } else {
                        ChartManager.showEmptyChart('call-types-chart', 'No call type data available');
                    }
                }
                
                // Unit Activity chart - show unit statistics
                const unitChartEl = document.getElementById('unit-activity-chart');
                if (unitChartEl) {
                    // For now, show total units and dispatch count
                    // In future, this could query /stats/units for more detailed unit status
                    const totalUnits = stats.total_units || 0;
                    const activeCalls = stats.calls_by_status?.open || 0;
                    
                    ChartManager.createBarChart('unit-activity-chart', {
                        labels: ['Total Units', 'Active Calls', 'Closed Calls'],
                        datasets: [{
                            label: 'Count',
                            data: [
                                totalUnits,
                                activeCalls,
                                stats.calls_by_status?.closed || 0
                            ],
                            backgroundColor: [
                                'rgba(54, 162, 235, 0.6)',
                                'rgba(255, 205, 86, 0.6)',
                                'rgba(75, 192, 192, 0.6)'
                            ],
                            borderColor: [
                                'rgb(54, 162, 235)',
                                'rgb(255, 205, 86)',
                                'rgb(75, 192, 192)'
                            ],
                            borderWidth: 2
                        }]
                    });
                    console.log('[Dashboard Main] Unit activity chart created');
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
