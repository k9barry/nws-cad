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
        let programmaticChange = false; // Flag to prevent form submit loops during programmatic changes
        
        // Initialize date filters with default (last 7 days) or load from session
        function initializeFilters() {
            const dateFromInput = document.getElementById('dashboard-date-from');
            const dateToInput = document.getElementById('dashboard-date-to');
            const quickPeriod = document.getElementById('dashboard-quick-period');
            const jurisdictionSelect = document.getElementById('dashboard-jurisdiction');
            
            // Check for saved filters first
            const savedFilters = Dashboard.filters.load();
            if (savedFilters && Object.keys(savedFilters).length > 0) {
                console.log('[Dashboard Main] Loading saved filters:', savedFilters);
                currentFilters = savedFilters;
                
                // Populate form with saved values
                if (dateFromInput && savedFilters.date_from) {
                    dateFromInput.value = savedFilters.date_from;
                }
                if (dateToInput && savedFilters.date_to) {
                    dateToInput.value = savedFilters.date_to;
                }
                if (quickPeriod && savedFilters.quick_period) {
                    quickPeriod.value = savedFilters.quick_period;
                }
                if (jurisdictionSelect && savedFilters.jurisdiction) {
                    jurisdictionSelect.value = savedFilters.jurisdiction;
                }
                
                // Load other filter fields
                const agencySelect = document.getElementById('dashboard-agency');
                const statusSelect = document.getElementById('dashboard-status');
                const prioritySelect = document.getElementById('dashboard-priority');
                
                if (agencySelect && savedFilters.agency_type) {
                    agencySelect.value = savedFilters.agency_type;
                }
                if (statusSelect && savedFilters.call_status) {
                    statusSelect.value = savedFilters.call_status;
                }
                if (prioritySelect && savedFilters.priority) {
                    prioritySelect.value = savedFilters.priority;
                }
            } else {
                // Set default dates (last 7 days, including today)
                console.log('[Dashboard Main] No saved filters, using defaults');
                if (dateFromInput && dateToInput) {
                    const now = new Date();
                    const sevenDaysAgo = new Date(now.getTime() - (7 * 24 * 60 * 60 * 1000));
                    const tomorrow = new Date(now.getTime() + (24 * 60 * 60 * 1000));
                    
                    dateFromInput.value = sevenDaysAgo.toISOString().split('T')[0];
                    dateToInput.value = tomorrow.toISOString().split('T')[0];
                    
                    currentFilters.date_from = dateFromInput.value;
                    currentFilters.date_to = dateToInput.value;
                    
                    // Set the quick period dropdown to match
                    if (quickPeriod) {
                        quickPeriod.value = '7days';
                    }
                    
                    // Save defaults
                    Dashboard.filters.save(currentFilters);
                }
            }
            
            // Update summary display
            updateFilterSummary();
            
            // Handle quick period selection
            if (quickPeriod) {
                quickPeriod.addEventListener('change', () => {
                    const period = quickPeriod.value;
                    let fromDate = new Date();
                    let toDate = new Date();
                    
                    switch(period) {
                        case 'today':
                            fromDate.setHours(0,0,0,0);
                            // For 'today', set toDate to tomorrow to include all of today
                            toDate.setDate(toDate.getDate() + 1);
                            toDate.setHours(0,0,0,0);
                            break;
                        case 'yesterday':
                            fromDate.setDate(fromDate.getDate() - 1);
                            fromDate.setHours(0,0,0,0);
                            // For 'yesterday', set toDate to today to include all of yesterday
                            toDate.setHours(0,0,0,0);
                            break;
                        case '7days':
                            fromDate = new Date(fromDate.getTime() - (7 * 24 * 60 * 60 * 1000));
                            fromDate.setHours(0,0,0,0);
                            // Include today
                            toDate.setDate(toDate.getDate() + 1);
                            toDate.setHours(0,0,0,0);
                            break;
                        case '30days':
                            fromDate = new Date(fromDate.getTime() - (30 * 24 * 60 * 60 * 1000));
                            fromDate.setHours(0,0,0,0);
                            // Include today
                            toDate.setDate(toDate.getDate() + 1);
                            toDate.setHours(0,0,0,0);
                            break;
                        case 'thismonth':
                            fromDate = new Date(fromDate.getFullYear(), fromDate.getMonth(), 1);
                            // Set toDate to tomorrow to include today
                            toDate.setDate(toDate.getDate() + 1);
                            toDate.setHours(0,0,0,0);
                            break;
                        case 'lastmonth':
                            fromDate = new Date(fromDate.getFullYear(), fromDate.getMonth() - 1, 1);
                            // Set toDate to first day of current month
                            toDate = new Date(fromDate.getFullYear(), fromDate.getMonth() + 1, 1);
                            break;
                        case 'custom':
                            return; // Don't auto-update dates for custom
                    }
                    
                    if (period !== 'custom' && dateFromInput && dateToInput) {
                        // Set flag to prevent form submit handler from interfering
                        programmaticChange = true;
                        
                        dateFromInput.value = fromDate.toISOString().split('T')[0];
                        dateToInput.value = toDate.toISOString().split('T')[0];
                        
                        // Manually update current filters and trigger refresh
                        currentFilters.date_from = dateFromInput.value;
                        currentFilters.date_to = dateToInput.value;
                        currentFilters.quick_period = period; // Save quick period selection
                        
                        // Save to session storage
                        Dashboard.filters.save(currentFilters);
                        
                        console.log('[Dashboard Main] Quick period changed to', period, 'Filters:', currentFilters);
                        
                        // Reload dashboard
                        refreshDashboard();
                        
                        // Reset flag after refresh starts
                        setTimeout(() => { programmaticChange = false; }, 100);
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
                    
                    // Skip if this is a programmatic change from quick period selector
                    if (programmaticChange) {
                        console.log('[Dashboard Main] Ignoring form submit (programmatic change)');
                        return;
                    }
                    
                    // Get all filter values from form
                    const formData = new FormData(filterForm);
                    currentFilters = {};
                    
                    for (const [key, value] of formData.entries()) {
                        if (value !== '') {
                            currentFilters[key] = value;
                        }
                    }
                    
                    // Save filters to session storage
                    Dashboard.filters.save(currentFilters);
                    
                    console.log('[Dashboard Main] Filters updated:', currentFilters);
                    
                    // Update filter summary
                    updateFilterSummary();
                    
                    // Reload dashboard with new filters
                    refreshDashboard();
                });
                
                // Clear filters button
                const clearButton = document.getElementById('clear-filters');
                if (clearButton) {
                    clearButton.addEventListener('click', () => {
                        // Set flag to prevent form submit interference
                        programmaticChange = true;
                        
                        currentFilters = {};
                        Dashboard.filters.clear();
                        
                        // Reset to default (last 7 days)
                        const now = new Date();
                        const sevenDaysAgo = new Date(now.getTime() - (7 * 24 * 60 * 60 * 1000));
                        const tomorrow = new Date(now.getTime() + (24 * 60 * 60 * 1000));
                        
                        const dateFromInput = document.getElementById('dashboard-date-from');
                        const dateToInput = document.getElementById('dashboard-date-to');
                        const quickPeriod = document.getElementById('dashboard-quick-period');
                        
                        if (dateFromInput) dateFromInput.value = sevenDaysAgo.toISOString().split('T')[0];
                        if (dateToInput) dateToInput.value = tomorrow.toISOString().split('T')[0];
                        if (quickPeriod) quickPeriod.value = '7days';
                        
                        currentFilters.date_from = dateFromInput.value;
                        currentFilters.date_to = dateToInput.value;
                        
                        Dashboard.filters.save(currentFilters);
                        
                        // Reload
                        refreshDashboard();
                        
                        // Reset flag
                        setTimeout(() => { programmaticChange = false; }, 100);
                    });
                }
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
         * Update filter summary display
         */
        function updateFilterSummary() {
            const summaryEl = document.getElementById('filter-summary');
            if (!summaryEl) return;
            
            const parts = [];
            
            // Quick period or date range
            if (currentFilters.quick_period) {
                const periods = {
                    'today': 'Today',
                    'yesterday': 'Yesterday',
                    '7days': 'Last 7 Days',
                    '30days': 'Last 30 Days',
                    'thismonth': 'This Month',
                    'lastmonth': 'Last Month'
                };
                parts.push(periods[currentFilters.quick_period] || 'Custom Range');
            } else if (currentFilters.date_from && currentFilters.date_to) {
                parts.push(`${currentFilters.date_from} to ${currentFilters.date_to}`);
            } else {
                parts.push('All Time');
            }
            
            // Add other filters
            if (currentFilters.agency_type) parts.push(`Agency: ${currentFilters.agency_type}`);
            if (currentFilters.jurisdiction) parts.push(`Jurisdiction: ${currentFilters.jurisdiction}`);
            if (currentFilters.status) parts.push(`Status: ${currentFilters.status}`);
            if (currentFilters.priority) parts.push(`Priority: ${currentFilters.priority}`);
            
            summaryEl.textContent = parts.join(' • ');
        }
        
        /**
         * Load statistics
         */
        async function loadStats() {
            console.log('[Dashboard Main] Loading stats...');
            console.log('[Dashboard Main] Current filters:', currentFilters);
            try {
                // Translate filters for API
                const apiFilters = Dashboard.filters.translateForAPI(currentFilters);
                console.log('[Dashboard Main] Translated API filters:', apiFilters);
                
                const url = '/stats' + Dashboard.buildQueryString(apiFilters);
                console.log('[Dashboard Main] Stats API URL:', url);
                const stats = await Dashboard.apiRequest(url);
                console.log('[Dashboard Main] Stats response:', stats);
                
                // Get units for available count (stats endpoint doesn't provide this)
                const unitsParams = { per_page: 1000, ...apiFilters };
                const units = await Dashboard.apiRequest('/units' + Dashboard.buildQueryString(unitsParams))
                    .then(r => r?.items || []).catch(() => []);
                
                console.log('[Dashboard Main] Loaded', units.length, 'units from API');
                
                // Use stats from the API endpoint (respects all filters correctly)
                const totalCalls = stats.total_calls || 0;
                const activeCalls = stats.calls_by_status?.open || 0;
                const closedCalls = stats.calls_by_status?.closed || 0;
                const availableUnits = units.filter(u => u.unit_status?.toLowerCase() === 'available').length;
                
                // Get top call type from stats
                let topCallType = 'N/A';
                if (stats.top_call_types && stats.top_call_types.length > 0) {
                    topCallType = stats.top_call_types[0].call_type;
                    // Truncate if too long
                    if (topCallType.length > 15) {
                        topCallType = topCallType.substring(0, 12) + '...';
                    }
                }
                
                console.log('[Dashboard Main] Stats calculated:', {
                    totalCalls,
                    activeCalls,
                    closedCalls,
                    availableUnits,
                    topCallType
                });
                
                // Update stat cards
                updateStatCard('stat-total-calls', totalCalls);
                updateStatCard('stat-active-calls', activeCalls);
                updateStatCard('stat-closed-calls', closedCalls);
                updateStatCard('stat-available-units', availableUnits);
                
                // Avg Response Time
                const avgMin = stats.response_times?.average_minutes || stats.avg_response_time_minutes;
                updateStatCard('stat-avg-response', avgMin ? `${Math.round(avgMin)}m` : 'N/A');
                
                updateStatCard('stat-top-call-type', topCallType);
                
                console.log('[Dashboard Main] Stats updated');
            } catch (error) {
                console.error('[Dashboard Main] Stats error:', error);
                if (Dashboard.showError) {
                    Dashboard.showError('Failed to load statistics');
                }
            }
        }
        
        // Helper to update stat cards
        function updateStatCard(elementId, value) {
            const element = document.getElementById(elementId);
            if (element) {
                element.textContent = value;
            }
        }
        
        /**
         * Load active calls (status=open)
         */
        async function loadRecentCalls() {
            console.log('[Dashboard Main] Loading recent calls...');
            console.log('[Dashboard Main] Current filters for recent calls:', currentFilters);
            try {
                // Translate filters for API
                const apiFilters = Dashboard.filters.translateForAPI(currentFilters);
                
                const queryParams = {
                    page: 1,
                    per_page: 10,
                    sort: 'create_datetime',
                    order: 'desc',
                    ...apiFilters
                };
                
                // Default to active calls if no status filter
                if (!currentFilters.status) {
                    queryParams.closed_flag = 'false';
                }
                
                // Update card title based on filters
                const titleEl = document.getElementById('recent-activity-title');
                if (titleEl) {
                    if (currentFilters.status === 'active' || !currentFilters.status) {
                        titleEl.textContent = 'Recent Active Calls';
                    } else if (currentFilters.status === 'closed') {
                        titleEl.textContent = 'Recent Closed Calls';
                    } else {
                        titleEl.textContent = 'Recent Activity';
                    }
                }
                
                const url = '/calls' + Dashboard.buildQueryString(queryParams);
                console.log('[Dashboard Main] Recent calls API URL:', url);
                console.log('[Dashboard Main] Query params:', queryParams);
                
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
                // Translate filters for API
                const apiFilters = Dashboard.filters.translateForAPI(currentFilters);
                
                const queryParams = {
                    page: 1,
                    per_page: 100,
                    ...apiFilters
                };
                
                // Default to showing only open calls on map if no status filter set
                if (!currentFilters.status) {
                    queryParams.closed_flag = 'false';
                }
                
                const url = '/calls' + Dashboard.buildQueryString(queryParams);
                
                console.log('[Dashboard Main] Fetching calls for map from:', Dashboard.config.apiBaseUrl + url);
                console.log('[Dashboard Main] Map query params:', queryParams);
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
            
            // Update filter summary
            updateFilterSummary();
            
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
        
        // Global function for navigating with filtered data
        window.navigateToFiltered = function(page, additionalFilters) {
            console.log('[Dashboard Main] Navigating to:', page, 'with filters:', additionalFilters);
            
            // Get current filters
            const currentFilters = Dashboard.filters.load() || {};
            
            // Merge with additional filters
            const mergedFilters = { ...currentFilters, ...additionalFilters };
            
            // Save merged filters
            Dashboard.filters.save(mergedFilters);
            
            console.log('[Dashboard Main] Saved filters:', mergedFilters);
            
            // Navigate to page
            window.location.href = `/${page}`;
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
