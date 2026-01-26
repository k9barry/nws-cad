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
            const [call, location, units, narratives] = await Promise.all([
                Dashboard.apiRequest(`/calls/${callId}`),
                Dashboard.apiRequest(`/calls/${callId}/location`).catch(() => null),
                Dashboard.apiRequest(`/calls/${callId}/units`).catch(() => []),
                Dashboard.apiRequest(`/calls/${callId}/narratives`).catch(() => [])
            ]);
            
            content.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h5>Call Information</h5>
                        <table class="table table-sm">
                            <tr><th>Call ID:</th><td>${call.id}</td></tr>
                            <tr><th>Type:</th><td>${call.call_type || 'N/A'}</td></tr>
                            <tr><th>Priority:</th><td>${Dashboard.getPriorityBadge(call.priority)}</td></tr>
                            <tr><th>Status:</th><td>${Dashboard.getStatusBadge(call.status)}</td></tr>
                            <tr><th>Received:</th><td>${Dashboard.formatDateTime(call.received_time)}</td></tr>
                            <tr><th>Agency:</th><td>${call.agency || 'N/A'}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h5>Location</h5>
                        <table class="table table-sm">
                            <tr><th>Address:</th><td>${location?.address || 'N/A'}</td></tr>
                            <tr><th>City:</th><td>${location?.city || 'N/A'}</td></tr>
                            <tr><th>Coordinates:</th><td>${location?.latitude && location?.longitude ? `${location.latitude}, ${location.longitude}` : 'N/A'}</td></tr>
                        </table>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <h5>Assigned Units (${units.length})</h5>
                        ${units.length > 0 ? `
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr><th>Unit</th><th>Status</th><th>Assigned</th></tr></thead>
                                    <tbody>
                                        ${units.map(u => `
                                            <tr>
                                                <td>${u.unit_id}</td>
                                                <td>${Dashboard.getStatusBadge(u.status)}</td>
                                                <td>${Dashboard.formatDateTime(u.assigned_time)}</td>
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
                        <h5>Narratives (${narratives.length})</h5>
                        ${narratives.length > 0 ? 
                            narratives.map(n => `
                                <div class="card mb-2">
                                    <div class="card-body">
                                        <small class="text-muted">${Dashboard.formatDateTime(n.created_at)}</small>
                                        <p class="mb-0">${n.narrative_text}</p>
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
    
})();
