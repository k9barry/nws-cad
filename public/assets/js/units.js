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
        await loadUnits();
        
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
    
    async function loadUnits() {
        console.log('[Units] Loading units...');
        
        try {
            const filters = {
                page: 1,
                per_page: 50,
                sort: 'unit_id',
                order: 'asc'
            };
            
            const url = '/units' + Dashboard.buildQueryString(filters);
            console.log('[Units] Fetching:', Dashboard.config.apiBaseUrl + url);
            
            const response = await fetch(Dashboard.config.apiBaseUrl + url);
            const result = await response.json();
            
            console.log('[Units] Response:', result);
            
            if (!result.success) {
                throw new Error(result.error || 'Failed to load units');
            }
            
            const units = result.data || [];
            console.log('[Units] Loaded', units.length, 'units');
            
            // Update count
            const countEl = document.getElementById('units-count');
            if (countEl) {
                countEl.textContent = units.length;
            }
            
            // Render table
            renderUnitsTable(units);
            
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
            const status = unit.status || 'unknown';
            const statusClass = status.toLowerCase() === 'available' ? 'success' : 'warning';
            
            return `
                <tr>
                    <td><strong>${escapeHtml(unit.unit_id || 'N/A')}</strong></td>
                    <td><span class="badge bg-${statusClass}">${escapeHtml(status.toUpperCase())}</span></td>
                    <td>${escapeHtml(unit.unit_type || 'N/A')}</td>
                    <td>${escapeHtml(unit.current_location || 'N/A')}</td>
                    <td>${unit.assigned_call_id ? `Call #${unit.assigned_call_id}` : 'None'}</td>
                    <td><small class="text-muted">${unit.last_update ? Dashboard.formatTime(unit.last_update) : 'N/A'}</small></td>
                </tr>
            `;
        }).join('');
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
