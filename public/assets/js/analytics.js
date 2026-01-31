/**
 * Analytics Page Script
 * Handles advanced analytics and reporting
 */

(function() {
    'use strict';
    
    console.log('[Analytics] Script loaded');
    
    let currentFilters = {};
    
    /**
     * Load filters from Dashboard session storage
     */
    function loadDashboardFilters() {
        if (typeof Dashboard !== 'undefined' && Dashboard.filters) {
            const savedFilters = Dashboard.filters.load();
            if (savedFilters) {
                currentFilters = savedFilters;
                console.log('[Analytics] Loaded filters from Dashboard:', currentFilters);
                displayActiveFilters();
                return true;
            }
        }
        console.log('[Analytics] No saved filters found');
        return false;
    }
    
    /**
     * Display active filters banner
     */
    function displayActiveFilters() {
        const banner = document.getElementById('active-filters-card');
        const display = document.getElementById('active-filters-display');
        
        if (!banner || !display) return;
        
        const filterCount = Object.keys(currentFilters).length;
        if (filterCount > 0) {
            const filterText = Object.entries(currentFilters)
                .filter(([key, value]) => value && key !== 'quick_period')
                .map(([key, value]) => `${key.replace('_', ' ')}: ${value}`)
                .slice(0, 3)  // Show only first 3
                .join(', ');
            
            display.textContent = filterText + (filterCount > 3 ? '...' : '');
            banner.style.display = 'block';
        } else {
            banner.style.display = 'none';
        }
    }
    
    /**
     * Load filter options
     */
    async function loadFilterOptions() {
        try {
            const stats = await Dashboard.apiRequest('/stats');
            
            // Populate jurisdiction filter
            const jurisdictionSelect = document.getElementById('analytics-jurisdiction');
            if (jurisdictionSelect && stats.calls_by_jurisdiction) {
                stats.calls_by_jurisdiction.forEach(j => {
                    const option = document.createElement('option');
                    option.value = j.jurisdiction;
                    option.textContent = j.jurisdiction;
                    jurisdictionSelect.appendChild(option);
                });
            }
        } catch (error) {
            console.error('[Analytics] Error loading filter options:', error);
        }
    }
    
    /**
     * Set default date range (last 30 days, including today)
     */
    function setDefaultDateRange() {
        const today = new Date();
        const thirtyDaysAgo = new Date();
        thirtyDaysAgo.setDate(today.getDate() - 30);
        const tomorrow = new Date();
        tomorrow.setDate(today.getDate() + 1);
        
        const dateFromEl = document.getElementById('analytics-date-from');
        const dateToEl = document.getElementById('analytics-date-to');
        
        if (dateFromEl && dateToEl) {
            currentDateFrom = thirtyDaysAgo.toISOString().split('T')[0];
            currentDateTo = tomorrow.toISOString().split('T')[0];
            
            dateFromEl.value = currentDateFrom;
            dateToEl.value = currentDateTo;
        }
    }
    
    /**
     * Handle quick period selection
     */
    function setupQuickPeriodSelector() {
        const quickPeriodEl = document.getElementById('quick-period');
        if (quickPeriodEl) {
            quickPeriodEl.addEventListener('change', function() {
                const value = this.value;
                const today = new Date();
                const dateFromEl = document.getElementById('analytics-date-from');
                const dateToEl = document.getElementById('analytics-date-to');
                
                if (!dateFromEl || !dateToEl) return;
                
                let fromDate, toDate;
                
                switch(value) {
                    case 'today':
                        fromDate = new Date(today);
                        fromDate.setHours(0,0,0,0);
                        toDate = new Date(today);
                        toDate.setDate(toDate.getDate() + 1);
                        toDate.setHours(0,0,0,0);
                        break;
                    case 'yesterday':
                        fromDate = new Date(today);
                        fromDate.setDate(today.getDate() - 1);
                        fromDate.setHours(0,0,0,0);
                        toDate = new Date(today);
                        toDate.setHours(0,0,0,0);
                        break;
                    case '7days':
                        fromDate = new Date();
                        fromDate.setDate(today.getDate() - 7);
                        fromDate.setHours(0,0,0,0);
                        toDate = new Date(today);
                        toDate.setDate(toDate.getDate() + 1);
                        toDate.setHours(0,0,0,0);
                        break;
                    case '30days':
                        fromDate = new Date();
                        fromDate.setDate(today.getDate() - 30);
                        fromDate.setHours(0,0,0,0);
                        toDate = new Date(today);
                        toDate.setDate(toDate.getDate() + 1);
                        toDate.setHours(0,0,0,0);
                        break;
                    case 'thismonth':
                        fromDate = new Date(today.getFullYear(), today.getMonth(), 1);
                        toDate = new Date(today);
                        toDate.setDate(toDate.getDate() + 1);
                        toDate.setHours(0,0,0,0);
                        break;
                    case 'lastmonth':
                        fromDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                        toDate = new Date(today.getFullYear(), today.getMonth(), 1);
                        break;
                    default:
                        return; // Custom range, do nothing
                }
                
                if (fromDate && toDate) {
                    dateFromEl.value = fromDate.toISOString().split('T')[0];
                    dateToEl.value = toDate.toISOString().split('T')[0];
                    
                    // Trigger form submission
                    document.getElementById('analytics-period-form')?.dispatchEvent(new Event('submit'));
                }
            });
        }
    }
    
    /**
     * Handle period form submission
     */
    function setupPeriodForm() {
        const formEl = document.getElementById('analytics-period-form');
        if (formEl) {
            formEl.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const dateFromEl = document.getElementById('analytics-date-from');
                const dateToEl = document.getElementById('analytics-date-to');
                const agencyEl = document.getElementById('analytics-agency');
                const jurisdictionEl = document.getElementById('analytics-jurisdiction');
                
                if (dateFromEl && dateToEl) {
                    currentDateFrom = dateFromEl.value;
                    currentDateTo = dateToEl.value;
                    currentAgency = agencyEl?.value || null;
                    currentJurisdiction = jurisdictionEl?.value || null;
                    
                    console.log('[Analytics] Filters updated:', {
                        dateFrom: currentDateFrom,
                        dateTo: currentDateTo,
                        agency: currentAgency,
                        jurisdiction: currentJurisdiction
                    });
                    loadAnalytics();
                }
            });
        }
    }
    
    async function init() {
        if (typeof Dashboard === 'undefined' || typeof ChartManager === 'undefined') {
            console.error('[Analytics] Dependencies not found, retrying...');
            setTimeout(init, 100);
            return;
        }
        
        console.log('[Analytics] Initializing analytics page...');
        
        // Load Dashboard filters first
        loadDashboardFilters();
        
        // Setup filter form handler
        const filterForm = document.getElementById('dashboard-filter-form');
        if (filterForm) {
            // Quick period selector
            const quickPeriod = document.getElementById('dashboard-quick-period');
            const dateFromInput = document.getElementById('dashboard-date-from');
            const dateToInput = document.getElementById('dashboard-date-to');
            let programmaticChange = false;
            
            if (quickPeriod) {
                quickPeriod.addEventListener('change', () => {
                    const period = quickPeriod.value;
                    let fromDate = new Date();
                    let toDate = new Date();
                    
                    switch(period) {
                        case 'today':
                            fromDate.setHours(0,0,0,0);
                            toDate.setDate(toDate.getDate() + 1);
                            toDate.setHours(0,0,0,0);
                            break;
                        case 'yesterday':
                            fromDate.setDate(fromDate.getDate() - 1);
                            fromDate.setHours(0,0,0,0);
                            toDate.setHours(0,0,0,0);
                            break;
                        case '7days':
                            fromDate = new Date(fromDate.getTime() - (7 * 24 * 60 * 60 * 1000));
                            fromDate.setHours(0,0,0,0);
                            toDate.setDate(toDate.getDate() + 1);
                            toDate.setHours(0,0,0,0);
                            break;
                        case '30days':
                            fromDate = new Date(fromDate.getTime() - (30 * 24 * 60 * 60 * 1000));
                            fromDate.setHours(0,0,0,0);
                            toDate.setDate(toDate.getDate() + 1);
                            toDate.setHours(0,0,0,0);
                            break;
                        case 'thismonth':
                            fromDate = new Date(fromDate.getFullYear(), fromDate.getMonth(), 1);
                            toDate.setDate(toDate.getDate() + 1);
                            toDate.setHours(0,0,0,0);
                            break;
                        case 'lastmonth':
                            fromDate = new Date(fromDate.getFullYear(), fromDate.getMonth() - 1, 1);
                            toDate = new Date(fromDate.getFullYear(), fromDate.getMonth() + 1, 1);
                            break;
                        case 'custom':
                            return;
                    }
                    
                    if (period !== 'custom' && dateFromInput && dateToInput) {
                        programmaticChange = true;
                        
                        dateFromInput.value = fromDate.toISOString().split('T')[0];
                        dateToInput.value = toDate.toISOString().split('T')[0];
                        
                        currentFilters.date_from = dateFromInput.value;
                        currentFilters.date_to = dateToInput.value;
                        currentFilters.quick_period = period;
                        
                        Dashboard.filters.save(currentFilters);
                        
                        console.log('[Analytics] Quick period changed to', period, 'Filters:', currentFilters);
                        
                        // Update active filters display
                        displayActiveFilters();
                        
                        // Reload page data
                        loadAnalytics();
                        
                        setTimeout(() => { programmaticChange = false; }, 100);
                    }
                });
            }
            
            filterForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                if (programmaticChange) {
                    console.log('[Analytics] Skipping form submit - programmatic change');
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
                
                console.log('[Analytics] Filters updated:', currentFilters);
                
                // Update active filters display
                displayActiveFilters();
                
                // Reload page data
                await loadAnalytics();
            });
            
            // Clear filters button
            const clearButton = document.getElementById('clear-filters');
            if (clearButton) {
                clearButton.addEventListener('click', async () => {
                    currentFilters = {};
                    Dashboard.filters.clear();
                    filterForm.reset();
                    
                    // Hide active filters banner
                    const banner = document.getElementById('active-filters-card');
                    if (banner) banner.style.display = 'none';
                    
                    // Reload page data
                    await loadAnalytics();
                });
            }
        }
        
        // Load initial data
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
        console.log('[Analytics] Current filters:', currentFilters);
        
        try {
            // Use filters from Dashboard
            const queryString = Dashboard.buildQueryString(currentFilters);
            
            console.log('[Analytics] Using query string:', queryString);
            
            // Fetch both general stats and detailed call stats (which includes agency data)
            const [stats, callStats, calls, units] = await Promise.all([
                Dashboard.apiRequest('/stats' + queryString),
                Dashboard.apiRequest('/stats/calls' + queryString).catch(() => null),
                Dashboard.apiRequest('/calls?per_page=10000' + (queryString ? '&' + queryString.substring(1) : '')).then(r => r?.items || []).catch(() => []),
                Dashboard.apiRequest('/units?per_page=10000' + (queryString ? '&' + queryString.substring(1) : '')).then(r => r?.items || []).catch(() => [])
            ]);
            console.log('[Analytics] Stats:', stats);
            console.log('[Analytics] Call Stats:', callStats);
            console.log('[Analytics] Loaded', calls.length, 'calls and', units.length, 'units with filters');
            console.log('[Analytics] Sample calls:', calls.slice(0, 3));
            
            // Update summary cards
            updateSummaryCards(stats, calls, units);
            
            // Create charts (pass both stats objects)
            createCharts(stats, callStats, calls);
            
            // Populate top locations and units
            populateTopLocations(calls);
            populateTopUnits(units);
            
            console.log('[Analytics] Analytics loaded successfully');
            
        } catch (error) {
            console.error('[Analytics] Error loading analytics:', error);
            if (Dashboard.showError) {
                Dashboard.showError('Failed to load analytics: ' + error.message);
            }
        }
    }
    
    /**
     * Helper function to update stats cards
     */
    function updateStatsCard(elementId, value) {
        const element = document.getElementById(elementId);
        if (element) {
            element.textContent = value;
        }
    }
    
    function updateSummaryCards(stats, calls, units) {
        // Update new analytics stats cards
        updateStatsCard('analytics-stat-total', stats.total_calls || 0);
        
        // Average response time
        const avgMin = stats.response_times?.average_minutes || stats.avg_response_time_minutes;
        updateStatsCard('analytics-stat-response', avgMin ? `${Math.round(avgMin)}m` : 'N/A');
        
        // Total unique units
        const uniqueUnits = stats.units_by_type?.reduce((sum, item) => sum + (item.count || 0), 0) || units.length || 0;
        updateStatsCard('analytics-stat-units', uniqueUnits);
        
        // Top call type
        if (stats.calls_by_type && stats.calls_by_type.length > 0) {
            const topType = stats.calls_by_type[0];
            const displayText = (topType.call_type || 'N/A').length > 20 
                ? (topType.call_type || 'N/A').substring(0, 17) + '...'
                : (topType.call_type || 'N/A');
            updateStatsCard('analytics-stat-toptype', displayText);
        } else {
            updateStatsCard('analytics-stat-toptype', 'N/A');
        }
        const totalCallsEl = document.getElementById('analytics-total-calls');
        if (totalCallsEl) {
            totalCallsEl.textContent = stats.total_calls || 0;
        }
        
        const avgResponseEl = document.getElementById('analytics-avg-response');
        if (avgResponseEl) {
            const avgMin = stats.response_times?.average_minutes;
            avgResponseEl.textContent = avgMin ? `${avgMin} min` : 'N/A';
        }
        
        // Calculate busiest hour
        const busiestHourEl = document.getElementById('analytics-busiest-hour');
        const busiestCountEl = document.getElementById('analytics-busiest-count');
        console.log('[Analytics] Calculating busiest hour from', calls.length, 'calls');
        if (busiestHourEl && calls.length > 0) {
            const hourCounts = {};
            calls.forEach(call => {
                if (call.create_datetime) {
                    const hour = new Date(call.create_datetime).getHours();
                    hourCounts[hour] = (hourCounts[hour] || 0) + 1;
                }
            });
            
            console.log('[Analytics] Hour counts:', hourCounts);
            console.log('[Analytics] Total hours with calls:', Object.keys(hourCounts).length);
            
            let maxHour = 0;
            let maxCount = 0;
            Object.entries(hourCounts).forEach(([hour, count]) => {
                if (count > maxCount) {
                    maxCount = count;
                    maxHour = parseInt(hour);
                }
            });
            
            console.log('[Analytics] Busiest hour:', maxHour, 'with', maxCount, 'calls');
            
            if (maxCount > 0) {
                busiestHourEl.textContent = `${maxHour.toString().padStart(2, '0')}:00`;
                if (busiestCountEl) {
                    busiestCountEl.textContent = `${maxCount} calls`;
                }
            } else {
                busiestHourEl.textContent = 'N/A';
                if (busiestCountEl) {
                    busiestCountEl.textContent = '-';
                }
            }
        } else {
            console.log('[Analytics] No calls data or element missing for busiest hour');
            if (busiestHourEl) {
                busiestHourEl.textContent = 'N/A';
                if (busiestCountEl) {
                    busiestCountEl.textContent = '-';
                }
            }
        }
        
        // Calculate most active unit
        const topUnitEl = document.getElementById('analytics-top-unit');
        const unitCallsEl = document.getElementById('analytics-unit-calls');
        if (topUnitEl && units.length > 0) {
            const unitCounts = {};
            units.forEach(unit => {
                const unitNum = unit.unit_number || 'Unknown';
                unitCounts[unitNum] = (unitCounts[unitNum] || 0) + 1;
            });
            
            let topUnit = 'N/A';
            let topCount = 0;
            Object.entries(unitCounts).forEach(([unit, count]) => {
                if (count > topCount) {
                    topCount = count;
                    topUnit = unit;
                }
            });
            
            if (topCount > 0) {
                topUnitEl.textContent = topUnit;
                if (unitCallsEl) {
                    unitCallsEl.textContent = `${topCount} calls`;
                }
            } else {
                topUnitEl.textContent = 'N/A';
                if (unitCallsEl) {
                    unitCallsEl.textContent = '-';
                }
            }
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
    
    function createCharts(stats, callStats, calls) {
        console.log('[Analytics] Creating charts...');
        
        // Call types chart (for distribution) - add counts to labels
        if (stats.top_call_types && stats.top_call_types.length > 0) {
            const chartEl = document.getElementById('analytics-distribution-chart');
            if (chartEl) {
                ChartManager.createDoughnutChart('analytics-distribution-chart', {
                    labels: stats.top_call_types.map(t => `${t.call_type || t.nature_of_call || 'Unknown'} (${t.count})`),
                    datasets: [{
                        data: stats.top_call_types.map(t => t.count),
                        backgroundColor: [
                            'rgb(255, 99, 132)',
                            'rgb(54, 162, 235)',
                            'rgb(255, 205, 86)',
                            'rgb(75, 192, 192)',
                            'rgb(153, 102, 255)',
                            'rgb(255, 159, 64)',
                            'rgb(201, 203, 207)',
                            'rgb(255, 159, 243)',
                            'rgb(54, 235, 162)',
                            'rgb(235, 99, 54)'
                        ]
                    }]
                });
                console.log('[Analytics] Distribution chart created');
            }
        }
        
        // Incidents by jurisdiction chart
        if (stats.calls_by_jurisdiction && stats.calls_by_jurisdiction.length > 0) {
            const chartEl = document.getElementById('analytics-volume-chart');
            if (chartEl) {
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
                
                ChartManager.createBarChart('analytics-volume-chart', {
                    labels: stats.calls_by_jurisdiction.map(j => j.jurisdiction),
                    datasets: [{
                        label: 'Incidents by Jurisdiction',
                        data: stats.calls_by_jurisdiction.map(j => j.count),
                        backgroundColor: colors.slice(0, stats.calls_by_jurisdiction.length),
                        borderColor: borderColors.slice(0, stats.calls_by_jurisdiction.length),
                        borderWidth: 2
                    }]
                });
                console.log('[Analytics] Jurisdiction chart created');
            }
        } else {
            const chartEl = document.getElementById('analytics-volume-chart');
            if (chartEl) {
                ChartManager.showEmptyChart('analytics-volume-chart', 'No jurisdiction data available');
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
        
        // Calls by Agency chart
        if (callStats && callStats.by_agency_type) {
            const agencyChartEl = document.getElementById('analytics-agency-chart');
            if (agencyChartEl) {
                // Convert object to array for chart
                const agencyData = Object.entries(callStats.by_agency_type).map(([agency, count]) => ({
                    agency: agency || 'Unknown',
                    count: parseInt(count)
                })).sort((a, b) => b.count - a.count).slice(0, 10); // Top 10 agencies
                
                if (agencyData.length > 0) {
                    ChartManager.createBarChart('analytics-agency-chart', {
                        labels: agencyData.map(a => a.agency),
                        datasets: [{
                            label: 'Call Count',
                            data: agencyData.map(a => a.count),
                            backgroundColor: [
                                'rgba(13, 110, 253, 0.6)',
                                'rgba(25, 135, 84, 0.6)',
                                'rgba(220, 53, 69, 0.6)',
                                'rgba(13, 202, 240, 0.6)',
                                'rgba(102, 16, 242, 0.6)',
                                'rgba(253, 126, 20, 0.6)',
                                'rgba(32, 201, 151, 0.6)',
                                'rgba(111, 66, 193, 0.6)',
                                'rgba(214, 51, 132, 0.6)',
                                'rgba(255, 193, 7, 0.6)'
                            ]
                        }]
                    });
                    console.log('[Analytics] Agency chart created');
                } else {
                    console.log('[Analytics] No agency data available');
                }
            }
        } else {
            console.log('[Analytics] No call stats or agency data available');
        }
        
        // Update top call types list
        const topCallsEl = document.getElementById('top-call-types');
        if (topCallsEl && stats.top_call_types && stats.top_call_types.length > 0) {
            topCallsEl.innerHTML = stats.top_call_types.map((type, idx) => `
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-primary me-2">${idx + 1}</span>
                            ${type.call_type || type.nature_of_call || 'Unknown'}
                        </div>
                        <span class="badge bg-secondary">${type.count}</span>
                    </div>
                </div>
            `).join('');
        }
        
        console.log('[Analytics] All charts created');
    }
    
    function populateTopLocations(calls) {
        const topLocationsEl = document.getElementById('top-locations');
        if (!topLocationsEl) return;
        
        // Helper function to escape HTML
        const escapeHtml = (text) => {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        };
        
        // Count calls by location (city)
        const locationCounts = {};
        calls.forEach(call => {
            const location = call.location?.city || call.city || 'Unknown';
            locationCounts[location] = (locationCounts[location] || 0) + 1;
        });
        
        // Sort and get top 5
        const topLocations = Object.entries(locationCounts)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 5);
        
        if (topLocations.length === 0) {
            topLocationsEl.innerHTML = '<div class="list-group-item text-center text-muted">No location data available</div>';
            return;
        }
        
        topLocationsEl.innerHTML = topLocations.map(([location, count], idx) => `
            <div class="list-group-item">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="badge bg-primary me-2">${idx + 1}</span>
                        ${escapeHtml(location)}
                    </div>
                    <span class="badge bg-secondary">${count} calls</span>
                </div>
            </div>
        `).join('');
    }
    
    function populateTopUnits(units) {
        const topUnitsEl = document.getElementById('top-units');
        if (!topUnitsEl) return;
        
        // Helper function to escape HTML
        const escapeHtml = (text) => {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        };
        
        // Count activity by unit number
        const unitCounts = {};
        units.forEach(unit => {
            const unitNumber = unit.unit_number || 'Unknown';
            unitCounts[unitNumber] = (unitCounts[unitNumber] || 0) + 1;
        });
        
        // Sort and get top 5
        const topUnits = Object.entries(unitCounts)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 5);
        
        if (topUnits.length === 0) {
            topUnitsEl.innerHTML = '<div class="list-group-item text-center text-muted">No unit data available</div>';
            return;
        }
        
        topUnitsEl.innerHTML = topUnits.map(([unitNumber, count], idx) => `
            <div class="list-group-item">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="badge bg-primary me-2">${idx + 1}</span>
                        ${escapeHtml(unitNumber)}
                    </div>
                    <span class="badge bg-secondary">${count} calls</span>
                </div>
            </div>
        `).join('');
    }
    
    // Export handlers
    document.getElementById('export-summary-csv')?.addEventListener('click', async function() {
        try {
            console.log('[Analytics] Exporting summary to CSV...');
            const stats = await Dashboard.apiRequest('/stats');
            
            // Create CSV content
            let csv = 'Metric,Value\n';
            csv += `Total Calls,${stats.total_calls || 0}\n`;
            csv += `Open Calls,${stats.calls_by_status?.open || 0}\n`;
            csv += `Closed Calls,${stats.calls_by_status?.closed || 0}\n`;
            csv += `Canceled Calls,${stats.calls_by_status?.canceled || 0}\n`;
            csv += `Total Units,${stats.total_units || 0}\n`;
            csv += `Average Response Time,${stats.response_times?.average_minutes || 0} minutes\n`;
            
            // Download CSV
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `analytics_summary_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(url);
            
            if (Dashboard.showSuccess) {
                Dashboard.showSuccess('Summary exported successfully');
            }
        } catch (error) {
            console.error('[Analytics] Export error:', error);
            if (Dashboard.showError) {
                Dashboard.showError('Failed to export summary');
            }
        }
    });
    
    document.getElementById('export-detailed-csv')?.addEventListener('click', async function() {
        try {
            console.log('[Analytics] Exporting detailed report...');
            const url = '/calls/export';
            const response = await fetch(Dashboard.config.apiBaseUrl + url);
            
            if (!response.ok) {
                throw new Error('Export failed');
            }
            
            const blob = await response.blob();
            const downloadUrl = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = downloadUrl;
            a.download = `analytics_detailed_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(downloadUrl);
            
            if (Dashboard.showSuccess) {
                Dashboard.showSuccess('Detailed report exported successfully');
            }
        } catch (error) {
            console.error('[Analytics] Export error:', error);
            if (Dashboard.showError) {
                Dashboard.showError('Failed to export detailed report');
            }
        }
    });
})();
