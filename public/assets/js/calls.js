/**
 * Calls Page Script
 * Handles the calls list and filtering
 */

(async function() {
    'use strict';
    
    let currentPage = 1;
    let currentFilters = {};
    
    /**
     * Load calls list
     */
    async function loadCalls(page = 1) {
        currentPage = page;
        
        const params = {
            page: page,
            per_page: 30,
            ...currentFilters
        };
        
        try {
            const response = await Dashboard.apiRequest('/calls' + Dashboard.buildQueryString(params));
            const calls = response?.items || [];
            const pagination = response?.pagination || {};
            
            const tbody = document.getElementById('calls-table-body');
            
            if (!calls || calls.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="9" class="text-center py-4">
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>
                                <p>No calls found</p>
                            </div>
                        </td>
                    </tr>
                `;
                document.getElementById('calls-count').textContent = '0';
                return;
            }
            
            tbody.innerHTML = calls.map(call => `
                <tr>
                    <td>${call.call_number}</td>
                    <td>${Dashboard.formatDateTime(call.create_datetime)}</td>
                    <td>${call.call_types?.[0] || 'N/A'}</td>
                    <td>${call.location?.address || 'N/A'}</td>
                    <td>${call.agency_types?.[0] || 'N/A'}</td>
                    <td><span class="badge bg-info">Normal</span></td>
                    <td><span class="badge ${call.closed_flag ? 'bg-success' : 'bg-warning'}">${call.closed_flag ? 'Closed' : 'Open'}</span></td>
                    <td>${call.unit_count || 0}</td>
                    <td>
                        <button class="btn btn-sm btn-primary" onclick="viewCallDetails(${call.id})">
                            <i class="bi bi-eye"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
            
            document.getElementById('calls-count').textContent = pagination.total || calls.length;
            
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
        const modal = new bootstrap.Modal(document.getElementById('call-detail-modal'));
        const content = document.getElementById('call-detail-content');
        
        content.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';
        modal.show();
        
        try {
            const [call, units, narratives] = await Promise.all([
                Dashboard.apiRequest(`/calls/${callId}`),
                Dashboard.apiRequest(`/calls/${callId}/units`).catch(() => []),
                Dashboard.apiRequest(`/calls/${callId}/narratives`).catch(() => [])
            ]);
            
            content.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h5>Call Information</h5>
                        <table class="table table-sm">
                            <tr><th>Call ID:</th><td>${call.id}</td></tr>
                            <tr><th>Call Number:</th><td>${call.call_number || 'N/A'}</td></tr>
                            <tr><th>Call Source:</th><td>${call.call_source || 'N/A'}</td></tr>
                            <tr><th>Type:</th><td>${call.call_type || 'N/A'}</td></tr>
                            <tr><th>Nature of Call:</th><td>${call.nature_of_call || 'N/A'}</td></tr>
                            <tr><th>Priority:</th><td>${Dashboard.getPriorityBadge(call.priority)}</td></tr>
                            <tr><th>Status:</th><td>${Dashboard.getStatusBadge(call.status)}</td></tr>
                            <tr><th>Received:</th><td>${Dashboard.formatDateTime(call.received_time)}</td></tr>
                            <tr><th>Created:</th><td>${Dashboard.formatDateTime(call.create_datetime)}</td></tr>
                            <tr><th>Closed:</th><td>${call.close_datetime ? Dashboard.formatDateTime(call.close_datetime) : 'N/A'}</td></tr>
                            <tr><th>Created By:</th><td>${call.created_by || 'N/A'}</td></tr>
                            <tr><th>Agency:</th><td>${call.agency || 'N/A'}</td></tr>
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
                
                ${call.agency_contexts && call.agency_contexts.length > 0 ? `
                    <div class="row mt-3">
                        <div class="col-12">
                            <h5>Agency Contexts (${call.agency_contexts.length})</h5>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr><th>Agency</th><th>Context</th><th>Type</th></tr></thead>
                                    <tbody>
                                        ${call.agency_contexts.map(ac => `
                                            <tr>
                                                <td>${ac.agency || 'N/A'}</td>
                                                <td>${ac.agency_context || 'N/A'}</td>
                                                <td>${ac.agency_context_type || 'N/A'}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                ` : ''}
                
                ${call.incidents && call.incidents.length > 0 ? `
                    <div class="row mt-3">
                        <div class="col-12">
                            <h5>Incidents (${call.incidents.length})</h5>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr><th>Type</th><th>Subtype</th><th>Code</th></tr></thead>
                                    <tbody>
                                        ${call.incidents.map(inc => `
                                            <tr>
                                                <td>${inc.incident_type || 'N/A'}</td>
                                                <td>${inc.incident_subtype || 'N/A'}</td>
                                                <td>${inc.incident_code || 'N/A'}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                ` : ''}
                
                <div class="row mt-3">
                    <div class="col-12">
                        <h5>Assigned Units (${call.counts?.units || units.length})</h5>
                        ${units.length > 0 ? `
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr><th>Unit</th><th>Type</th><th>Status</th><th>Assigned</th><th>Primary</th></tr></thead>
                                    <tbody>
                                        ${units.map(u => `
                                            <tr>
                                                <td>${u.unit_number || u.unit_id || 'N/A'}</td>
                                                <td>${u.unit_type || 'N/A'}</td>
                                                <td>${Dashboard.getStatusBadge(u.status)}</td>
                                                <td>${Dashboard.formatDateTime(u.assigned_datetime || u.assigned_time)}</td>
                                                <td>${u.is_primary ? '<span class="badge bg-primary">Yes</span>' : ''}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        ` : '<p class="text-muted">No units assigned</p>'}
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-12">
                        <h5>Narratives (${call.counts?.narratives || narratives.length})</h5>
                        ${narratives.length > 0 ? 
                            narratives.map(n => `
                                <div class="card mb-2">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <small class="text-muted">${Dashboard.formatDateTime(n.created_at || n.narrative_datetime)}</small>
                                            ${n.entry_by ? `<small class="text-muted">By: ${n.entry_by}</small>` : ''}
                                        </div>
                                        <p class="mb-0 mt-2">${n.narrative_text}</p>
                                    </div>
                                </div>
                            `).join('') 
                            : '<p class="text-muted">No narratives</p>'}
                    </div>
                </div>
                
                ${call.counts?.persons > 0 ? `
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="bi bi-people-fill"></i> ${call.counts.persons} person(s) associated with this call
                            </div>
                        </div>
                    </div>
                ` : ''}
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
            const params = { ...currentFilters, per_page: 1000 };
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
     * Handle filter form submission
     */
    document.getElementById('calls-filter-form')?.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        currentFilters = {};
        
        for (const [key, value] of formData.entries()) {
            if (value) {
                currentFilters[key] = value;
            }
        }
        
        loadCalls(1);
    });
    
    /**
     * Handle filter reset
     */
    document.getElementById('reset-filters')?.addEventListener('click', function() {
        currentFilters = {};
        loadCalls(1);
    });
    
    /**
     * Handle refresh button
     */
    document.getElementById('refresh-calls')?.addEventListener('click', function() {
        loadCalls(currentPage);
    });
    
    // Initial load
    await loadCalls(1);
    
    // Setup auto-refresh
    Dashboard.setupAutoRefresh(() => loadCalls(currentPage));
    
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
