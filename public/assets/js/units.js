/**
 * Units Page Script
 * Handles the units status and tracking page
 */

(async function() {
    'use strict';
    
    // Initialize map
    const map = MapManager.initMap('units-map');
    let currentFilters = {};
    
    /**
     * Load unit statistics
     */
    async function loadUnitStats() {
        try {
            const stats = await Dashboard.apiRequest('/stats/units');
            
            document.getElementById('units-available').textContent = stats.available_count || 0;
            document.getElementById('units-enroute').textContent = stats.enroute_count || 0;
            document.getElementById('units-onscene').textContent = stats.onscene_count || 0;
            document.getElementById('units-offduty').textContent = stats.offduty_count || 0;
            
        } catch (error) {
            console.error('Error loading unit stats:', error);
        }
    }
    
    /**
     * Load units list
     */
    async function loadUnits() {
        const params = {
            per_page: 100,
            ...currentFilters
        };
        
        try {
            const units = await Dashboard.apiRequest('/units' + Dashboard.buildQueryString(params));
            
            const tbody = document.getElementById('units-table-body');
            
            if (!units || units.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center py-4">
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>
                                <p>No units found</p>
                            </div>
                        </td>
                    </tr>
                `;
                document.getElementById('units-count').textContent = '0';
                return;
            }
            
            tbody.innerHTML = units.map(unit => `
                <tr>
                    <td><strong>${unit.unit_id || unit.badge_number}</strong></td>
                    <td>${unit.unit_type || 'N/A'}</td>
                    <td>${unit.agency || 'N/A'}</td>
                    <td>${Dashboard.getStatusBadge(unit.status)}</td>
                    <td>${unit.current_call || '-'}</td>
                    <td>${unit.personnel_count || 0}</td>
                    <td>${Dashboard.formatDateTime(unit.last_update)}</td>
                    <td>
                        <button class="btn btn-sm btn-primary" onclick="viewUnitDetails(${unit.id})">
                            <i class="bi bi-eye"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
            
            document.getElementById('units-count').textContent = units.length;
            
            // Update map with unit locations
            await loadUnitsMap(units);
            
        } catch (error) {
            console.error('Error loading units:', error);
            Dashboard.showError('Failed to load units');
        }
    }
    
    /**
     * Load units on map
     */
    async function loadUnitsMap(units) {
        try {
            const unitsWithLocation = [];
            
            for (const unit of units.slice(0, 50)) { // Limit to 50 for performance
                // Mock location data - in production, this would come from GPS/AVL
                if (unit.latitude && unit.longitude) {
                    unitsWithLocation.push(unit);
                }
            }
            
            if (unitsWithLocation.length > 0) {
                MapManager.showUnits('units-map', unitsWithLocation);
            }
            
        } catch (error) {
            console.error('Error loading units map:', error);
        }
    }
    
    /**
     * View unit details
     */
    window.viewUnitDetails = async function(unitId) {
        const modal = new bootstrap.Modal(document.getElementById('unit-detail-modal'));
        const content = document.getElementById('unit-detail-content');
        
        content.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';
        modal.show();
        
        try {
            const [unit, logs, personnel] = await Promise.all([
                Dashboard.apiRequest(`/units/${unitId}`),
                Dashboard.apiRequest(`/units/${unitId}/logs`).catch(() => []),
                Dashboard.apiRequest(`/units/${unitId}/personnel`).catch(() => [])
            ]);
            
            content.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h5>Unit Information</h5>
                        <table class="table table-sm">
                            <tr><th>Unit ID:</th><td>${unit.unit_id || unit.badge_number}</td></tr>
                            <tr><th>Type:</th><td>${unit.unit_type || 'N/A'}</td></tr>
                            <tr><th>Status:</th><td>${Dashboard.getStatusBadge(unit.status)}</td></tr>
                            <tr><th>Agency:</th><td>${unit.agency || 'N/A'}</td></tr>
                            <tr><th>Current Call:</th><td>${unit.current_call || 'None'}</td></tr>
                            <tr><th>Last Update:</th><td>${Dashboard.formatDateTime(unit.last_update)}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h5>Personnel (${personnel.length})</h5>
                        ${personnel.length > 0 ? `
                            <table class="table table-sm">
                                <thead><tr><th>Name</th><th>Role</th></tr></thead>
                                <tbody>
                                    ${personnel.map(p => `
                                        <tr>
                                            <td>${p.name}</td>
                                            <td>${p.role || 'N/A'}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        ` : '<p class="text-muted">No personnel assigned</p>'}
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <h5>Activity Log (${logs.length})</h5>
                        ${logs.length > 0 ? 
                            `<div style="max-height: 300px; overflow-y: auto;">
                                ${logs.map(log => `
                                    <div class="card mb-2">
                                        <div class="card-body py-2">
                                            <div class="d-flex justify-content-between">
                                                <strong>${log.action || 'Activity'}</strong>
                                                <small class="text-muted">${Dashboard.formatDateTime(log.timestamp)}</small>
                                            </div>
                                            ${log.details ? `<small>${log.details}</small>` : ''}
                                        </div>
                                    </div>
                                `).join('')}
                            </div>`
                            : '<p class="text-muted">No activity logs</p>'}
                    </div>
                </div>
            `;
            
        } catch (error) {
            console.error('Error loading unit details:', error);
            content.innerHTML = '<div class="alert alert-danger">Failed to load unit details</div>';
        }
    };
    
    /**
     * Export units to CSV
     */
    document.getElementById('export-units-csv')?.addEventListener('click', async function() {
        try {
            const params = { ...currentFilters, per_page: 1000 };
            const units = await Dashboard.apiRequest('/units' + Dashboard.buildQueryString(params));
            
            const exportData = units.map(unit => ({
                'Unit ID': unit.unit_id || unit.badge_number,
                'Type': unit.unit_type,
                'Agency': unit.agency,
                'Status': unit.status,
                'Current Call': unit.current_call,
                'Last Update': unit.last_update
            }));
            
            Dashboard.exportToCSV(exportData, `units-${new Date().toISOString().split('T')[0]}.csv`);
            
        } catch (error) {
            Dashboard.showError('Failed to export units');
        }
    });
    
    /**
     * Handle filter form submission
     */
    document.getElementById('units-filter-form')?.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        currentFilters = {};
        
        for (const [key, value] of formData.entries()) {
            if (value) {
                currentFilters[key] = value;
            }
        }
        
        loadUnits();
    });
    
    /**
     * Handle filter reset
     */
    document.querySelector('#units-filter-form button[type="reset"]')?.addEventListener('click', function() {
        currentFilters = {};
        loadUnits();
    });
    
    /**
     * Handle refresh button
     */
    document.getElementById('refresh-units')?.addEventListener('click', function() {
        loadUnitStats();
        loadUnits();
    });
    
    /**
     * Refresh all data
     */
    async function refreshAll() {
        await Promise.all([
            loadUnitStats(),
            loadUnits()
        ]);
    }
    
    // Initial load
    await refreshAll();
    
    // Setup auto-refresh
    Dashboard.setupAutoRefresh(refreshAll);
    
})();
