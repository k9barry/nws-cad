/**
 * Units Page Script
 * Handles the units overview and tracking page
 */

(function() {
    'use strict';
    
    console.log('[Units] Script loaded');
    
    let currentFilters = {};
    
    /**
     * Load filters from Dashboard session storage
     */
    function loadDashboardFilters() {
        if (typeof Dashboard !== 'undefined' && Dashboard.filters) {
            const savedFilters = Dashboard.filters.load();
            if (savedFilters) {
                currentFilters = savedFilters;
                console.log('[Units] Loaded filters from Dashboard:', currentFilters);
                displayActiveFilters();
                return true;
            }
        }
        console.log('[Units] No saved filters found');
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
    
    async function init() {
        if (typeof Dashboard === 'undefined') {
            console.error('[Units] Dashboard object not found, retrying...');
            setTimeout(init, 100);
            return;
        }
        
        console.log('[Units] Initializing units page...');
        
        // Load Dashboard filters first
        loadDashboardFilters();
        
        // Initialize map
        if (typeof MapManager !== 'undefined') {
            MapManager.initMap('units-map');
            console.log('[Units] Map initialized');
        }
        
        // Update statistics
        await updateUnitStatistics();
        
        // Load recent activity
        await loadRecentUnits();
        
        await loadUnits();
        
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
                        
                        console.log('[Units] Quick period changed to', period, 'Filters:', currentFilters);
                        
                        // Update active filters display
                        displayActiveFilters();
                        
                        // Reload page data
                        updateUnitStatistics();
                        loadRecentUnits();
                        loadUnits();
                        
                        setTimeout(() => { programmaticChange = false; }, 100);
                    }
                });
            }
            
            filterForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                if (programmaticChange) {
                    console.log('[Units] Skipping form submit - programmatic change');
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
                
                console.log('[Units] Filters updated:', currentFilters);
                
                // Update active filters display
                displayActiveFilters();
                
                // Reload page data
                await updateUnitStatistics();
                await loadRecentUnits();
                await loadUnits();
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
                    await updateUnitStatistics();
                    await loadRecentUnits();
                    await loadUnits();
                });
            }
        }
        
        // Setup auto-refresh
        if (Dashboard.setupAutoRefresh) {
            Dashboard.setupAutoRefresh(async () => {
                await updateUnitStatistics();
                await loadRecentUnits();
                await loadUnits();
            });
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    /**
     * Update unit statistics cards
     */
    async function updateUnitStatistics() {
        try {
            // Translate filters for API
            const apiFilters = Dashboard.filters.translateForAPI(currentFilters);
            const queryString = Dashboard.buildQueryString(apiFilters);
            
            // Fetch units with filters - use higher limit for statistics
            const units = await Dashboard.apiRequest(`/units?per_page=1000${queryString ? '&' + queryString.substring(1) : ''}`);
            const items = units.items || [];
            
            // Calculate statistics based on unit_status
            const available = items.filter(u => u.unit_status?.toLowerCase() === 'available').length;
            const enroute = items.filter(u => u.unit_status?.toLowerCase() === 'en route' || u.unit_status?.toLowerCase() === 'enroute').length;
            const onscene = items.filter(u => u.unit_status?.toLowerCase() === 'on scene' || u.unit_status?.toLowerCase() === 'onscene').length;
            const offduty = items.filter(u => u.unit_status?.toLowerCase() === 'off duty' || u.unit_status?.toLowerCase() === 'offduty').length;
            
            // Update cards
            const updateCard = (id, value) => {
                const el = document.getElementById(id);
                if (el) el.textContent = value;
            };
            
            updateCard('units-available', available);
            updateCard('units-enroute', enroute);
            updateCard('units-onscene', onscene);
            updateCard('units-offduty', offduty);
            
            console.log('[Units] Statistics updated:', { available, enroute, onscene, offduty });
            
        } catch (error) {
            console.error('[Units] Error updating statistics:', error);
        }
    }
    
    /**
     * Load recent unit activity
     */
    async function loadRecentUnits() {
        console.log('[Units] Loading recent unit activity...');
        try {
            // Translate filters for API
            const apiFilters = Dashboard.filters.translateForAPI(currentFilters);
            
            const queryParams = {
                page: 1,
                per_page: 10,
                sort: 'assigned_datetime',
                order: 'desc',
                ...apiFilters
            };
            
            const url = '/units' + Dashboard.buildQueryString(queryParams);
            console.log('[Units] Recent units API URL:', url);
            
            const response = await Dashboard.apiRequest(url);
            const units = response?.items || [];
            const container = document.getElementById('units-recent-activity');
            
            if (!container) {
                console.warn('[Units] units-recent-activity container not found');
                return;
            }
            
            if (units.length === 0) {
                container.innerHTML = `
                    <div class="empty-state text-center py-4">
                        <i class="bi bi-inbox fs-1 text-muted"></i>
                        <p class="text-muted mt-2">No recent units</p>
                    </div>
                `;
            } else {
                container.innerHTML = units.map(unit => `
                    <div class="recent-unit-item p-3 mb-2 border rounded" style="cursor: pointer;" onclick="viewUnitDetails(${unit.id})">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <div class="fw-bold">${unit.unit_number || 'Unknown'}</div>
                            <small class="text-muted">${Dashboard.formatTime(unit.assigned_datetime)}</small>
                        </div>
                        <div class="text-muted small">
                            <i class="bi bi-person-badge"></i> ${unit.agency_type || 'N/A'}
                        </div>
                        <div class="mt-2">
                            <span class="badge ${
                                unit.unit_status?.toLowerCase() === 'available' ? 'bg-success' : 
                                unit.unit_status?.toLowerCase() === 'en route' || unit.unit_status?.toLowerCase() === 'enroute' ? 'bg-warning' :
                                unit.unit_status?.toLowerCase() === 'on scene' || unit.unit_status?.toLowerCase() === 'onscene' ? 'bg-danger' :
                                'bg-secondary'
                            }">
                                ${unit.unit_status || 'Unknown'}
                            </span>
                            ${unit.call_number ? `<span class="badge bg-secondary">#${unit.call_number}</span>` : ''}
                        </div>
                    </div>
                `).join('');
            }
        } catch (error) {
            console.error('[Units] Error loading recent activity:', error);
        }
    }
    
    async function loadUnits() {
        console.log('[Units] Loading units...');
        console.log('[Units] Current filters:', currentFilters);
        
        try {
            // Translate filters for API
            const apiFilters = Dashboard.filters.translateForAPI(currentFilters);
            
            const params = {
                page: 1,
                per_page: 500,
                ...apiFilters
            };
            
            console.log('[Units] Fetching units with params:', params);
            
            const url = '/units' + Dashboard.buildQueryString(params);
            console.log('[Units] API URL:', url);
            
            const response = await Dashboard.apiRequest(url);
            
            console.log('[Units] Response:', response);
            
            let filteredUnits = response?.items || [];
            console.log('[Units] Loaded', filteredUnits.length, 'units');
            
            // Update count
            const countEl = document.getElementById('units-count');
            if (countEl) {
                countEl.textContent = filteredUnits.length;
            }
            
            // Update filter status display
            const filterStatusEl = document.getElementById('filter-status');
            if (filterStatusEl && timeRangeSelect) {
                const timeRange = timeRangeSelect.value;
                if (timeRange === 'custom') {
                    const from = dateFromInput?.value || 'N/A';
                    const to = dateToInput?.value || 'N/A';
                    filterStatusEl.textContent = `Custom: ${from} to ${to}`;
                } else {
                    const hours = parseInt(timeRange) || 24;
                    if (hours < 24) {
                        filterStatusEl.textContent = `Last ${hours} Hour${hours > 1 ? 's' : ''}`;
                    } else if (hours === 24) {
                        filterStatusEl.textContent = 'Last 24 Hours';
                    } else {
                        const days = Math.floor(hours / 24);
                        filterStatusEl.textContent = `Last ${days} Day${days > 1 ? 's' : ''}`;
                    }
                }
            }
            
            // Update stats
            let available = 0, enroute = 0, onscene = 0, offduty = 0;
            filteredUnits.forEach(u => {
                if (u.clear_datetime) offduty++;
                else if (u.arrive_datetime && !u.clear_datetime) onscene++;
                else if (u.enroute_datetime && !u.arrive_datetime) enroute++;
                else if (u.assigned_datetime) available++;
            });
            
            const availEl = document.getElementById('units-available');
            if (availEl) availEl.textContent = available;
            const enrouteEl = document.getElementById('units-enroute');
            if (enrouteEl) enrouteEl.textContent = enroute;
            const onsceneEl = document.getElementById('units-onscene');
            if (onsceneEl) onsceneEl.textContent = onscene;
            const offdtyEl = document.getElementById('units-offduty');
            if (offdtyEl) offdtyEl.textContent = offduty;
            
            // Render table
            renderUnitsTable(filteredUnits);
            
            // Update map with unit locations
            updateUnitsMap(filteredUnits);
            
        } catch (error) {
            console.error('[Units] Error loading units:', error);
            if (Dashboard.showError) {
                Dashboard.showError('Failed to load units: ' + error.message);
            }
        }
    }
    
    function renderUnitsTable(units) {
        const tbody = document.getElementById('units-table-body');
        if (!tbody) {
            console.warn('[Units] Table body not found');
            return;
        }
        
        if (units.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4"><i class="bi bi-inbox fs-1 text-muted"></i><p class="text-muted mt-2">No units found</p></td></tr>';
            return;
        }
        
        tbody.innerHTML = units.map(unit => {
            const hasClearTime = unit.timestamps?.clear;
            const statusClass = hasClearTime ? 'secondary' : 'success';
            const status = hasClearTime ? 'Clear' : 'Active';
            const incidentNumber = unit.call?.incident_number || unit.call?.call_number || 'None';
            
            return `
                <tr>
                    <td><strong>${escapeHtml(unit.unit_number || 'N/A')}</strong></td>
                    <td>${escapeHtml(unit.unit_type || 'N/A')}</td>
                    <td>${escapeHtml(unit.jurisdiction || 'N/A')}</td>
                    <td><span class="badge bg-${statusClass}">${status}</span></td>
                    <td>${incidentNumber}</td>
                    <td>${unit.personnel_count || 0}</td>
                    <td><small class="text-muted">${unit.timestamps?.assigned ? Dashboard.formatTime(unit.timestamps.assigned) : 'N/A'}</small></td>
                    <td>
                        <button class="btn btn-sm btn-primary" onclick="viewUnitDetails(${unit.id})" title="View Details">
                            <i class="bi bi-eye"></i>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }
    
    function updateUnitsMap(units) {
        if (typeof MapManager === 'undefined' || !MapManager.maps['units-map']) {
            console.warn('[Units] Map not available');
            return;
        }
        
        console.log('[Units] Updating map with', units.length, 'units');
        
        // Clear existing markers
        MapManager.clearMarkers('units-map');
        
        // Add markers for units with location
        let markerCount = 0;
        units.forEach(unit => {
            // Location data is directly on unit object from API
            const lat = unit.latitude_y;
            const lng = unit.longitude_x;
            
            console.log('[Units] Unit:', unit.unit_number, 'Lat:', lat, 'Lng:', lng);
            
            if (lat && lng) {
                const status = unit.clear_datetime ? 'Clear' : 'Active';
                const statusColor = unit.clear_datetime ? 'secondary' : 'success';
                const incidentNum = unit.call?.incident_number || unit.call_number;
                
                MapManager.addCallMarker('units-map', {
                    id: unit.id,
                    latitude: parseFloat(lat),
                    longitude: parseFloat(lng),
                    popupContent: `
                        <strong>${escapeHtml(unit.unit_number)}</strong><br>
                        Type: ${escapeHtml(unit.unit_type || 'N/A')}<br>
                        Status: <span class="badge bg-${statusColor}">${status}</span><br>
                        Incident: ${incidentNum || 'N/A'}<br>
                        Location: ${escapeHtml(unit.full_address || unit.city || 'N/A')}
                    `
                });
                markerCount++;
            }
        });
        
        console.log('[Units] Added', markerCount, 'unit markers to map');
        
        // Fit map to markers or set default view
        if (markerCount > 0) {
            MapManager.fitToMarkers('units-map');
        } else {
            // Center on Indiana if no markers
            if (MapManager.maps['units-map']) {
                MapManager.maps['units-map'].setView([40.0, -86.0], 7);
            }
            console.log('[Units] No units with location data found, centering on default location');
        }
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Export units to CSV
    document.getElementById('export-units-csv')?.addEventListener('click', async function() {
        try {
            console.log('[Units] Exporting units to CSV...');
            const url = '/units/export';
            const response = await fetch(Dashboard.config.apiBaseUrl + url);
            
            if (!response.ok) {
                throw new Error('Export failed');
            }
            
            const blob = await response.blob();
            const downloadUrl = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = downloadUrl;
            a.download = `units_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(downloadUrl);
            
            if (Dashboard.showSuccess) {
                Dashboard.showSuccess('Units exported successfully');
            }
        } catch (error) {
            console.error('[Units] Export error:', error);
            if (Dashboard.showError) {
                Dashboard.showError('Failed to export units: ' + error.message);
            }
        }
    });
    
    // Refresh units button
    document.getElementById('refresh-units')?.addEventListener('click', function() {
        console.log('[Units] Manual refresh triggered');
        loadUnits();
    });
    
    // Global function for unit details
    window.viewUnitDetails = async function(unitId) {
        console.log('[Units] View details for unit ID:', unitId);
        
        try {
            const url = `/units/${unitId}`;
            console.log('[Units] Fetching from:', Dashboard.config.apiBaseUrl + url);
            
            // Dashboard.apiRequest already unwraps the response
            const unit = await Dashboard.apiRequest(url);
            console.log('[Units] Unit data:', unit);
            
            if (!unit || !unit.id) {
                throw new Error('Invalid unit data received');
            }
            
            // Build modal content
            let modalContent = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Unit Information</h6>
                        <table class="table table-sm">
                            <tr><th>Unit Number:</th><td><strong>${escapeHtml(unit.unit_number || 'N/A')}</strong></td></tr>
                            <tr><th>Unit Type:</th><td>${escapeHtml(unit.unit_type || 'N/A')}</td></tr>
                            <tr><th>Jurisdiction:</th><td>${escapeHtml(unit.jurisdiction || 'N/A')}</td></tr>
                            <tr><th>Primary Unit:</th><td>${unit.is_primary ? 'Yes' : 'No'}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Call Assignment</h6>
                        ${unit.call_id && unit.call ? `
                            <table class="table table-sm">
                                <tr><th>Incident Number:</th><td><a href="/calls#call-${unit.call_id}" class="fw-bold">${escapeHtml(unit.call.incident_number || unit.call.call_number || 'N/A')}</a></td></tr>
                                <tr><th>Nature:</th><td>${escapeHtml(unit.call.nature_of_call || 'N/A')}</td></tr>
                                <tr><th>Location:</th><td>${escapeHtml(unit.call.location?.address || 'N/A')}</td></tr>
                                <tr><th>Caller:</th><td>${escapeHtml(unit.call.caller_name || 'N/A')}</td></tr>
                            </table>
                        ` : '<p class="text-muted">No active call assignment</p>'}
                    </div>
                </div>
                
                ${unit.timestamps ? `
                    <div class="mt-3">
                        <h6>Timeline</h6>
                        <table class="table table-sm">
                            ${unit.timestamps.assigned ? `<tr><th>Assigned:</th><td>${new Date(unit.timestamps.assigned).toLocaleString()}</td></tr>` : ''}
                            ${unit.timestamps.enroute ? `<tr><th>En Route:</th><td>${new Date(unit.timestamps.enroute).toLocaleString()}</td></tr>` : ''}
                            ${unit.timestamps.arrive ? `<tr><th>Arrived:</th><td>${new Date(unit.timestamps.arrive).toLocaleString()}</td></tr>` : ''}
                            ${unit.timestamps.clear ? `<tr><th>Cleared:</th><td>${new Date(unit.timestamps.clear).toLocaleString()}</td></tr>` : ''}
                        </table>
                    </div>
                ` : ''}
                
                <div class="mt-3">
                    <h6>Counts</h6>
                    <div class="row text-center">
                        <div class="col"><strong>${unit.personnel_count || 0}</strong><br><small class="text-muted">Personnel</small></div>
                        <div class="col"><strong>${unit.log_count || 0}</strong><br><small class="text-muted">Logs</small></div>
                        <div class="col"><strong>${unit.disposition_count || 0}</strong><br><small class="text-muted">Dispositions</small></div>
                    </div>
                </div>
            `;
            
            // Show in modal
            document.getElementById('unit-detail-content').innerHTML = modalContent;
            new bootstrap.Modal(document.getElementById('unit-detail-modal')).show();
            
        } catch (error) {
            console.error('[Units] Error loading unit details:', error);
            if (Dashboard.showError) {
                Dashboard.showError('Failed to load unit details');
            }
        }
    };
})();
