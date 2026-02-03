/**
 * Calls Page Script - Refactored with FilterManager
 * Handles the calls list and filtering
 */

(async function() {
    'use strict';
    
    let currentPage = 1;
    let filterManager = null;
    
    /**
     * Display active filters banner
     */
    function displayActiveFilters() {
        const banner = document.getElementById('active-filters-card');
        const display = document.getElementById('active-filters-display');
        
        if (!banner || !display) return;
        
        const filters = filterManager.getFilters();
        const filterCount = Object.keys(filters).length;
        
        if (filterCount > 0) {
            const filterText = Object.entries(filters)
                .filter(([key, value]) => value && key !== 'quick_period')
                .map(([key, value]) => `${key.replace('_', ' ')}: ${value}`)
                .slice(0, 3)
                .join(', ');
            
            display.textContent = filterText + (filterCount > 3 ? '...' : '');
            banner.style.display = 'block';
        } else {
            banner.style.display = 'none';
        }
    }
    
    /**
     * Handle filter changes
     */
    async function onFilterChange(filters) {
        console.log('[Calls] Filters changed:', filters);
        displayActiveFilters();
        await updateCallStatistics();
        await loadCalls(1);
    }
    
    /**
     * Load filter options - REMOVED (handled by FilterManager)
     */
    async function loadFilterOptions() {
        // Filter options now loaded by FilterManager
        console.log('[Calls] Filter options managed by FilterManager');
    }
    
    /**
     * Initialize date filters - REMOVED (handled by FilterManager)
     */
    function initializeDateFilters() {
        // Date filters now managed by FilterManager
        console.log('[Calls] Date filters managed by FilterManager');
                    agencySelect.appendChild(option);
                });
            }
            
        } catch (error) {
            console.error('Error loading filter options:', error);
        }
    }
    
    /**
     * Load calls list
     */
    async function loadCalls(page = 1) {
        currentPage = page;
        
        // Get filters from FilterManager
        const filters = filterManager.getFilters();
        const apiFilters = filterManager.translateForAPI(filters);
        
        const params = {
            page: page,
            per_page: 30,
            ...apiFilters
        };
        
        try {
            const response = await Dashboard.apiRequest('/calls' + Dashboard.buildQueryString(params));
            const calls = response?.items || [];
            const pagination = response?.pagination || {};
            
            const tbody = document.getElementById('calls-table-body');
            
            if (!calls || calls.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="10" class="text-center py-4">
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>
                                <p>No calls found</p>
                            </div>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = calls.map(call => {
                // Get priority display
                const priority = call.priorities?.[0] || '';
                const priorityBadge = priority ? `<span class="badge bg-warning">${priority}</span>` : '<span class="badge bg-secondary">N/A</span>';
                
                // Get status display
                const status = call.statuses?.[0] || (call.closed_flag ? 'Closed' : 'Open');
                let statusBadge = '';
                if (status.toLowerCase().includes('progress') || status.toLowerCase() === 'open') {
                    statusBadge = `<span class="badge bg-warning">${status}</span>`;
                } else if (status.toLowerCase() === 'closed' || call.closed_flag) {
                    statusBadge = `<span class="badge bg-success">${status}</span>`;
                } else if (call.canceled_flag) {
                    statusBadge = '<span class="badge bg-danger">Canceled</span>';
                } else {
                    statusBadge = `<span class="badge bg-info">${status}</span>`;
                }
                
                // Get incident number (use first one if multiple, or call_number as fallback)
                const incidentNumber = call.incident_numbers?.[0] || call.call_number;
                
                return `
                    <tr>
                        <td>${incidentNumber}</td>
                        <td>${Dashboard.formatDateTime(call.create_datetime)}</td>
                        <td>${call.call_types?.[0] || 'N/A'}</td>
                        <td>${call.location?.address || 'N/A'}</td>
                        <td>${call.jurisdictions?.[0] || 'N/A'}</td>
                        <td>${call.agency_types?.[0] || 'N/A'}</td>
                        <td>${priorityBadge}</td>
                        <td>${statusBadge}</td>
                        <td>${call.unit_count || 0}</td>
                        <td>
                            <button class="btn btn-sm btn-primary" onclick="viewCallDetails(${call.id})">
                                <i class="bi bi-eye"></i>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
            
            // Update pagination info
            const start = ((pagination.current_page || 1) - 1) * (pagination.per_page || 30) + 1;
            const end = start + calls.length - 1;
            document.getElementById('pagination-info').textContent = 
                `Showing ${start}-${end} of ${pagination.total || calls.length} calls`;
            
            updatePagination(pagination.current_page || page, pagination.has_more || false);
            
        } catch (error) {
            console.error('Error loading calls:', error);
            Dashboard.showError('Failed to load calls');
        }
    }
    
    /**
     * Update pagination
     */
    function updatePagination(currentPage, hasMore) {
        const pagination = document.getElementById('pagination');
        
        let html = '';
        
        // Previous button
        html += `
            <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="changePage(${currentPage - 1}); return false;">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
        `;
        
        // Page numbers
        for (let i = Math.max(1, currentPage - 2); i <= Math.min(currentPage + 2, currentPage + (hasMore ? 2 : 0)); i++) {
            html += `
                <li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="changePage(${i}); return false;">${i}</a>
                </li>
            `;
        }
        
        // Next button
        html += `
            <li class="page-item ${!hasMore ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="changePage(${currentPage + 1}); return false;">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        `;
        
        pagination.innerHTML = html;
    }
    
    /**
     * Change page
     */
    window.changePage = function(page) {
        loadCalls(page);
    };
    
    /**
     * View call details
     */
    window.viewCallDetails = async function(callId) {
        console.log('[Calls] viewCallDetails called with ID:', callId);
        
        try {
            const modalEl = document.getElementById('call-detail-modal');
            if (!modalEl) {
                console.error('[Calls] Modal element not found');
                return;
            }
            
            const modal = new bootstrap.Modal(modalEl);
            const content = document.getElementById('call-detail-content');
            
            if (!content) {
                console.error('[Calls] Modal content element not found');
                return;
            }
            
            content.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';
            modal.show();
            
            console.log('[Calls] Fetching call details for ID:', callId);
            
            const [call, unitsResponse, narrativesResponse, personsResponse] = await Promise.all([
                Dashboard.apiRequest(`/calls/${callId}`),
                Dashboard.apiRequest(`/calls/${callId}/units`).catch(() => ({ items: [] })),
                Dashboard.apiRequest(`/calls/${callId}/narratives`).catch(() => ({ items: [] })),
                Dashboard.apiRequest(`/calls/${callId}/persons`).catch(() => [])
            ]);
            
            const units = unitsResponse?.items || unitsResponse || [];
            const narratives = narrativesResponse?.items || narrativesResponse || [];
            const persons = personsResponse?.data || personsResponse || [];
            
            // Get latest priority and status from agency contexts
            let latestPriority = 'N/A';
            let latestStatus = 'N/A';
            if (call.agency_contexts && call.agency_contexts.length > 0) {
                // Sort by created_datetime descending to get latest
                const sortedContexts = [...call.agency_contexts].sort((a, b) => 
                    new Date(b.created_datetime) - new Date(a.created_datetime)
                );
                latestPriority = sortedContexts[0].priority || 'N/A';
                latestStatus = sortedContexts[0].status || 'N/A';
            }
            
            content.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h5>Call Information</h5>
                        <table class="table table-sm">
                            <tr><th>Call ID:</th><td>${call.id}</td></tr>
                            <tr><th>Call Number:</th><td>${call.call_number || 'N/A'}</td></tr>
                            <tr><th>Call Source:</th><td>${call.call_source || 'N/A'}</td></tr>
                            <tr><th>Nature of Call:</th><td>${call.nature_of_call || 'N/A'}</td></tr>
                            <tr><th>Priority:</th><td>${Dashboard.getPriorityBadge(latestPriority)}</td></tr>
                            <tr><th>Status:</th><td>${Dashboard.getStatusBadge(latestStatus)}</td></tr>
                            <tr><th>Received:</th><td>${Dashboard.formatDateTime(call.received_time)}</td></tr>
                            <tr><th>Created:</th><td>${Dashboard.formatDateTime(call.create_datetime)}</td></tr>
                            <tr><th>Closed:</th><td>${call.close_datetime ? Dashboard.formatDateTime(call.close_datetime) : 'N/A'}</td></tr>
                            <tr><th>Created By:</th><td>${call.created_by || 'N/A'}</td></tr>
                            <tr><th>Alarm Level:</th><td>${call.alarm_level || 'N/A'}</td></tr>
                            <tr><th>EMD Code:</th><td>${call.emd_code || 'N/A'}</td></tr>
                            <tr><th>Closed:</th><td>${call.closed_flag ? '<span class="badge bg-secondary">Yes</span>' : '<span class="badge bg-success">No</span>'}</td></tr>
                            <tr><th>Canceled:</th><td>${call.canceled_flag ? '<span class="badge bg-warning">Yes</span>' : '<span class="badge bg-success">No</span>'}</td></tr>
                        </table>
                        
                        ${call.caller && (call.caller.name || call.caller.phone) ? `
                            <h5 class="mt-3">Caller Information</h5>
                            <table class="table table-sm">
                                ${call.caller.name ? `<tr><th>Name:</th><td>${call.caller.name}</td></tr>` : ''}
                                ${call.caller.phone ? `<tr><th>Phone:</th><td>${call.caller.phone}</td></tr>` : ''}
                            </table>
                        ` : ''}
                    </div>
                    <div class="col-md-6">
                        <h5>Location</h5>
                        <table class="table table-sm">
                            <tr><th>Full Address:</th><td>${call.location?.full_address || 'N/A'}</td></tr>
                            ${call.location?.house_number ? `<tr><th>House Number:</th><td>${call.location.house_number}</td></tr>` : ''}
                            ${call.location?.prefix_directional ? `<tr><th>Direction:</th><td>${call.location.prefix_directional}</td></tr>` : ''}
                            ${call.location?.street_name ? `<tr><th>Street Name:</th><td>${call.location.street_name}</td></tr>` : ''}
                            ${call.location?.street_type ? `<tr><th>Street Type:</th><td>${call.location.street_type}</td></tr>` : ''}
                            <tr><th>City:</th><td>${call.location?.city || 'N/A'}</td></tr>
                            ${call.location?.state ? `<tr><th>State:</th><td>${call.location.state}</td></tr>` : ''}
                            ${call.location?.zip ? `<tr><th>ZIP:</th><td>${call.location.zip}</td></tr>` : ''}
                            ${call.location?.common_name ? `<tr><th>Common Name:</th><td>${call.location.common_name}</td></tr>` : ''}
                            ${call.location?.nearest_cross_streets ? `<tr><th>Cross Streets:</th><td>${call.location.nearest_cross_streets}</td></tr>` : ''}
                            <tr><th>Coordinates:</th><td>${call.location?.coordinates ? `${call.location.coordinates.lat}, ${call.location.coordinates.lng}` : 'N/A'}</td></tr>
                            ${call.location?.police_beat ? `<tr><th>Police Beat:</th><td>${call.location.police_beat}</td></tr>` : ''}
                            ${call.location?.ems_district ? `<tr><th>EMS District:</th><td>${call.location.ems_district}</td></tr>` : ''}
                            ${call.location?.fire_quadrant ? `<tr><th>Fire Quadrant:</th><td>${call.location.fire_quadrant}</td></tr>` : ''}
                        </table>
                    </div>
                </div>
                
                ${(() => {
                    // Deduplicate agency contexts by agency_type (only show one per agency type)
                    if (!call.agency_contexts || call.agency_contexts.length === 0) return '';
                    
                    const uniqueContexts = [];
                    const seenAgencyTypes = new Set();
                    
                    // Get the latest context for each agency type
                    const sortedContexts = [...call.agency_contexts].sort((a, b) => 
                        new Date(b.created_datetime) - new Date(a.created_datetime)
                    );
                    
                    for (const ac of sortedContexts) {
                        if (!seenAgencyTypes.has(ac.agency_type)) {
                            seenAgencyTypes.add(ac.agency_type);
                            uniqueContexts.push(ac);
                        }
                    }
                    
                    return `
                    <div class="row mt-3">
                        <div class="col-12">
                            <h5>Agency Contexts (${uniqueContexts.length})</h5>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr><th>Agency Type</th><th>Call Type</th><th>Priority</th><th>Status</th><th>Dispatcher</th><th>Timestamp</th></tr></thead>
                                    <tbody>
                                        ${uniqueContexts.map(ac => `
                                            <tr>
                                                <td>${ac.agency_type || 'N/A'}</td>
                                                <td>${ac.call_type || 'N/A'}</td>
                                                <td>${ac.priority || 'N/A'}</td>
                                                <td>${Dashboard.getStatusBadge(ac.status)}</td>
                                                <td>${ac.dispatcher || 'N/A'}</td>
                                                <td>${Dashboard.formatDateTime(ac.created_datetime)}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    `;
                })()}
                
                ${(() => {
                    // Deduplicate incidents by jurisdiction (only show one per jurisdiction)
                    if (!call.incidents || call.incidents.length === 0) return '';
                    
                    const uniqueIncidents = [];
                    const seenJurisdictions = new Set();
                    
                    for (const inc of call.incidents) {
                        if (!seenJurisdictions.has(inc.jurisdiction)) {
                            seenJurisdictions.add(inc.jurisdiction);
                            uniqueIncidents.push(inc);
                        }
                    }
                    
                    return `
                    <div class="row mt-3">
                        <div class="col-12">
                            <h5>Incidents (${uniqueIncidents.length})</h5>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr><th>Agency Type</th><th>Incident Number</th><th>Type</th><th>Jurisdiction</th><th>Created</th></tr></thead>
                                    <tbody>
                                        ${uniqueIncidents.map(inc => `
                                            <tr>
                                                <td>${inc.agency_type || 'N/A'}</td>
                                                <td>${inc.incident_number || 'N/A'}</td>
                                                <td>${inc.incident_type || 'N/A'}</td>
                                                <td>${inc.jurisdiction || 'N/A'}</td>
                                                <td>${Dashboard.formatDateTime(inc.create_datetime)}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    `;
                })()}
                
                ${(() => {
                    // Create incident summary section - one row per unique jurisdiction
                    if (!call.incidents || call.incidents.length === 0) return '';
                    
                    const uniqueIncidents = [];
                    const seenJurisdictions = new Set();
                    
                    for (const inc of call.incidents) {
                        if (!seenJurisdictions.has(inc.jurisdiction)) {
                            seenJurisdictions.add(inc.jurisdiction);
                            uniqueIncidents.push(inc);
                        }
                    }
                    
                    return `
                    <div class="row mt-3">
                        <div class="col-12">
                            <h5>Incident Summary</h5>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr><th>Agency Type</th><th>Jurisdiction</th><th>Incident Number</th></tr></thead>
                                    <tbody>
                                        ${uniqueIncidents.map(inc => `
                                            <tr>
                                                <td>${inc.agency_type || 'N/A'}</td>
                                                <td>${inc.jurisdiction || 'N/A'}</td>
                                                <td>${inc.incident_number || 'N/A'}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    `;
                })()}
                
                <div class="row mt-3">
                    <div class="col-12">
                        <h5>Assigned Units (${call.counts?.units || units.length})</h5>
                        ${units.length > 0 ? `
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr><th>Unit</th><th>Type</th><th>Status</th><th>Assigned</th><th>Enroute</th><th>Arrived</th><th>Primary</th></tr></thead>
                                    <tbody>
                                        ${units.map(u => {
                                            const status = u.timestamps?.clear ? 'Clear' : 
                                                          u.timestamps?.arrive ? 'On Scene' :
                                                          u.timestamps?.enroute ? 'Enroute' :
                                                          u.timestamps?.dispatch ? 'Dispatched' :
                                                          u.timestamps?.assigned ? 'Assigned' : 'Unknown';
                                            return `
                                            <tr>
                                                <td>${u.unit_number || u.unit_id || 'N/A'}</td>
                                                <td>${u.unit_type || 'N/A'}</td>
                                                <td>${Dashboard.getStatusBadge(status)}</td>
                                                <td>${Dashboard.formatDateTime(u.timestamps?.assigned || u.assigned_datetime || u.assigned_time)}</td>
                                                <td>${u.timestamps?.enroute ? Dashboard.formatDateTime(u.timestamps.enroute) : '-'}</td>
                                                <td>${u.timestamps?.arrive ? Dashboard.formatDateTime(u.timestamps.arrive) : '-'}</td>
                                                <td>${u.is_primary ? '<span class="badge bg-primary">Yes</span>' : ''}</td>
                                            </tr>
                                            `;
                                        }).join('')}
                                    </tbody>
                                </table>
                            </div>
                        ` : '<p class="text-muted">No units assigned</p>'}
                    </div>
                </div>
                
                ${(() => {
                    // Persons section - deduplicate by name and role
                    if (!persons || persons.length === 0) return '';
                    
                    const uniquePersons = [];
                    const seenPersons = new Map();
                    
                    for (const person of persons) {
                        const fullName = [person.first_name, person.middle_name, person.last_name, person.name_suffix]
                            .filter(Boolean).join(' ');
                        const key = `${fullName}-${person.role}`;
                        
                        if (!seenPersons.has(key)) {
                            seenPersons.set(key, person);
                            uniquePersons.push(person);
                        }
                    }
                    
                    return `
                    <div class="row mt-3">
                        <div class="col-12">
                            <h5>Persons Involved (${uniquePersons.length})</h5>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Role</th>
                                            <th>Phone</th>
                                            <th>DOB</th>
                                            <th>Sex</th>
                                            <th>Race</th>
                                            <th>Primary Caller</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${uniquePersons.map(p => {
                                            const fullName = [p.first_name, p.middle_name, p.last_name, p.name_suffix]
                                                .filter(Boolean).join(' ');
                                            return `
                                            <tr>
                                                <td>${fullName || 'N/A'}</td>
                                                <td><span class="badge bg-info">${p.role || 'N/A'}</span></td>
                                                <td>${p.contact_phone || '-'}</td>
                                                <td>${p.date_of_birth ? Dashboard.formatDateTime(p.date_of_birth) : '-'}</td>
                                                <td>${p.sex || '-'}</td>
                                                <td>${p.race || '-'}</td>
                                                <td>${p.primary_caller_flag ? '<span class="badge bg-primary">Yes</span>' : ''}</td>
                                            </tr>
                                            `;
                                        }).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    `;
                })()}
                
                <div class="row mt-3">
                    <div class="col-12">
                        <h5>Narratives (${call.counts?.narratives || narratives.length})</h5>
                        ${narratives.length > 0 ? 
                            narratives.map(n => `
                                <div class="card mb-2">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <small class="text-muted">${Dashboard.formatDateTime(n.create_datetime)}</small>
                                            ${n.create_user ? `<small class="text-muted">By: ${n.create_user}</small>` : ''}
                                            ${n.narrative_type ? `<span class="badge bg-info ms-2">${n.narrative_type}</span>` : ''}
                                        </div>
                                        <p class="mb-0 mt-2">${n.text}</p>
                                    </div>
                                </div>
                            `).join('') 
                            : '<p class="text-muted">No narratives</p>'}
                    </div>
                </div>
                
            `;
            
        } catch (error) {
            console.error('Error loading call details:', error);
            content.innerHTML = '<div class="alert alert-danger">Failed to load call details</div>';
        }
    };
    
    /**
     * Export calls to CSV
     */
    document.getElementById('export-csv')?.addEventListener('click', async function() {
        try {
            const filters = filterManager.getFilters();
            const apiFilters = filterManager.translateForAPI(filters);
            const params = { ...apiFilters, per_page: 1000 };
            const calls = await Dashboard.apiRequest('/calls' + Dashboard.buildQueryString(params));
            
            const exportData = calls.map(call => ({
                'Call ID': call.id,
                'Date/Time': call.received_time,
                'Type': call.call_type,
                'Priority': call.priority,
                'Status': call.status,
                'Address': call.address,
                'Agency': call.agency
            }));
            
            Dashboard.exportToCSV(exportData, `calls-${new Date().toISOString().split('T')[0]}.csv`);
            
        } catch (error) {
            Dashboard.showError('Failed to export calls');
        }
    });
    
    /**
     * Filter form handlers - FilterManager handles all of this
     */
    // FilterManager automatically sets up all event handlers
    
    /**
     * Handle refresh button (if exists)
     */
    document.getElementById('refresh-calls')?.addEventListener('click', function() {
        loadCalls(currentPage);
    });
    
    // Initialize FilterManager
    filterManager = new FilterManager({
        formId: 'dashboard-filter-form',
        onFilterChange: onFilterChange,
        searchDebounceMs: 300
    });
    
    await filterManager.init();
    displayActiveFilters();
    
    // Initial data load
    await updateCallStatistics();
    await loadCalls(1);
    
    // Setup auto-refresh
    Dashboard.setupAutoRefresh(async () => {
        await updateCallStatistics();
        await loadCalls(currentPage);
    });
    
    /**
     * Update call statistics cards
     */
    async function updateCallStatistics() {
        try {
            // Get filters from FilterManager
            const filters = filterManager.getFilters();
            const apiFilters = filterManager.translateForAPI(filters);
            const queryString = Dashboard.buildQueryString(apiFilters);
            
            // Use /stats endpoint which calculates across ALL filtered records
            const stats = await Dashboard.apiRequest('/stats' + queryString);
            
            const totalCalls = stats.total_calls || 0;
            const activeCalls = stats.calls_by_status?.open || 0;
            const closedCalls = stats.calls_by_status?.closed || 0;
            const avgMin = stats.response_times?.average_minutes || stats.avg_response_time_minutes;
            
            // Update cards
            const updateCard = (id, value) => {
                const el = document.getElementById(id);
                if (el) el.textContent = value;
            };
            
            updateCard('calls-stat-total', totalCalls);
            updateCard('calls-stat-active', activeCalls);
            updateCard('calls-stat-closed', closedCalls);
            updateCard('calls-stat-response', avgMin ? `${Math.round(avgMin)}m` : 'N/A');
            
        } catch (error) {
            console.error('[Calls] Error updating statistics:', error);
        }
    }
    
    // Check for hash to auto-open call details
    if (window.location.hash && window.location.hash.startsWith('#call-')) {
        const callId = parseInt(window.location.hash.replace('#call-', ''));
        if (callId) {
            console.log('[Calls] Auto-opening call details for ID:', callId);
            setTimeout(async () => {
                try {
                    await viewCallDetails(callId);
                } catch (error) {
                    console.error('[Calls] Failed to auto-open call:', error);
                    if (Dashboard.showError) {
                        Dashboard.showError(`Call #${callId} not found or no longer available`);
                    }
                }
                // Clear the hash
                history.replaceState(null, null, ' ');
            }, 500);
        }
    }
    
})();
