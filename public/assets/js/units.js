/**
 * Units Page Script
 * Handles the units overview and tracking page
 */

(function() {
    'use strict';
    
    console.log('[Units] Script loaded');
    
    async function init() {
        if (typeof Dashboard === 'undefined') {
            console.error('[Units] Dashboard object not found, retrying...');
            setTimeout(init, 100);
            return;
        }
        
        console.log('[Units] Initializing units page...');
        
        // Initialize map
        if (typeof MapManager !== 'undefined') {
            MapManager.initMap('units-map');
            console.log('[Units] Map initialized');
        }
        
        await loadUnits();
        
        // Setup filters
        setupFilters();
        
        // Setup auto-refresh
        if (Dashboard.setupAutoRefresh) {
            Dashboard.setupAutoRefresh(loadUnits);
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    function setupFilters() {
        const filterForm = document.getElementById('units-filter-form');
        if (!filterForm) {
            console.warn('[Units] Filter form not found');
            return;
        }
        
        // Show/hide custom date range based on time range selection
        const timeRangeSelect = document.getElementById('filter-time-range');
        const customDateRange = document.getElementById('custom-date-range');
        
        if (timeRangeSelect && customDateRange) {
            timeRangeSelect.addEventListener('change', () => {
                if (timeRangeSelect.value === 'custom') {
                    customDateRange.style.display = 'block';
                } else {
                    customDateRange.style.display = 'none';
                }
            });
        }
        
        // Apply filters
        filterForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            await loadUnits();
        });
        
        // Reset filters
        filterForm.addEventListener('reset', async (e) => {
            e.preventDefault();
            filterForm.reset();
            // Reset to default 24 hours
            if (timeRangeSelect) {
                timeRangeSelect.value = '24';
            }
            if (customDateRange) {
                customDateRange.style.display = 'none';
            }
            await loadUnits();
        });
        
        // Populate unit types and agencies
        populateFilterOptions();
    }
    
    async function populateFilterOptions() {
        try {
            // Get distinct unit types and agencies from recent units (last 7 days)
            const now = new Date();
            const sevenDaysAgo = new Date(now.getTime() - (7 * 24 * 60 * 60 * 1000));
            const dateFrom = sevenDaysAgo.toISOString().slice(0, 19).replace('T', ' ');
            
            const queryParams = Dashboard.buildQueryString({
                date_from: dateFrom,
                per_page: 200
            });
            
            const response = await fetch(Dashboard.config.apiBaseUrl + '/units' + queryParams);
            const result = await response.json();
            const units = result?.data?.items || [];
            
            if (!units || !Array.isArray(units)) return;
            
            // Get unique types
            const types = [...new Set(units.map(u => u.unit_type).filter(Boolean))];
            const typeSelect = document.getElementById('filter-unit-type');
            if (typeSelect) {
                types.sort().forEach(type => {
                    const option = document.createElement('option');
                    option.value = type;
                    option.textContent = type;
                    typeSelect.appendChild(option);
                });
            }
            
            // Get unique agencies
            const agencies = [...new Set(units.map(u => u.jurisdiction).filter(Boolean))];
            const agencySelect = document.getElementById('filter-unit-agency');
            if (agencySelect) {
                agencies.sort().forEach(agency => {
                    const option = document.createElement('option');
                    option.value = agency;
                    option.textContent = agency;
                    agencySelect.appendChild(option);
                });
            }
        } catch (error) {
            console.error('[Units] Error populating filter options:', error);
        }
    }
    
    async function loadUnits() {
        console.log('[Units] Loading units...');
        
        try {
            const filters = {
                page: 1,
                per_page: 500,  // Increased to handle more units
                sort: 'assigned_datetime',
                order: 'desc'
            };
            
            // Handle time range filter
            const timeRangeSelect = document.getElementById('filter-time-range');
            const dateFromInput = document.getElementById('filter-date-from');
            const dateToInput = document.getElementById('filter-date-to');
            
            if (timeRangeSelect) {
                const timeRange = timeRangeSelect.value;
                
                if (timeRange === 'custom') {
                    // Use custom date range
                    if (dateFromInput && dateFromInput.value) {
                        filters.date_from = dateFromInput.value;
                    }
                    if (dateToInput && dateToInput.value) {
                        filters.date_to = dateToInput.value;
                    }
                } else {
                    // Calculate date range based on hours
                    const hours = parseInt(timeRange) || 24;  // Default to 24 hours
                    const now = new Date();
                    const fromDate = new Date(now.getTime() - (hours * 60 * 60 * 1000));
                    
                    // Format dates as YYYY-MM-DD HH:MM:SS
                    filters.date_from = fromDate.toISOString().slice(0, 19).replace('T', ' ');
                    filters.date_to = now.toISOString().slice(0, 19).replace('T', ' ');
                }
            }
            
            // Get filter values from form
            const statusFilter = document.getElementById('filter-unit-status');
            const typeFilter = document.getElementById('filter-unit-type');
            const agencyFilter = document.getElementById('filter-unit-agency');
            const searchFilter = document.getElementById('filter-unit-search');
            
            // Note: API doesn't support status filter, so we'll filter client-side
            // The API supports: unit_type, jurisdiction, unit_number, date_from, date_to
            
            if (typeFilter && typeFilter.value) {
                filters.unit_type = typeFilter.value;
            }
            if (agencyFilter && agencyFilter.value) {
                filters.jurisdiction = agencyFilter.value;
            }
            if (searchFilter && searchFilter.value) {
                filters.unit_number = searchFilter.value;
            }
            
            const url = '/units' + Dashboard.buildQueryString(filters);
            console.log('[Units] Fetching:', Dashboard.config.apiBaseUrl + url);
            
            const response = await fetch(Dashboard.config.apiBaseUrl + url);
            const result = await response.json();
            
            console.log('[Units] Response:', result);
            
            if (!result.success) {
                throw new Error(result.error || 'Failed to load units');
            }
            
            let filteredUnits = result.data?.items || [];
            console.log('[Units] Loaded', filteredUnits.length, 'units');
            
            // Apply client-side status filter (not supported by API)
            if (statusFilter && statusFilter.value) {
                const status = statusFilter.value;
                filteredUnits = filteredUnits.filter(u => {
                    if (status === 'offduty') return u.clear_datetime;
                    if (status === 'onscene') return u.arrive_datetime && !u.clear_datetime;
                    if (status === 'enroute') return u.enroute_datetime && !u.arrive_datetime;
                    if (status === 'available') return u.assigned_datetime && !u.enroute_datetime && !u.clear_datetime;
                    return true;
                });
            }
            
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
