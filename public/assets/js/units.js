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
            
            const units = result.data?.items || [];
            console.log('[Units] Loaded', units.length, 'units');
            
            // Update count
            const countEl = document.getElementById('units-count');
            if (countEl) {
                countEl.textContent = units.length;
            }
            
            // Update stats
            let available = 0, enroute = 0, onscene = 0, offduty = 0;
            units.forEach(u => {
                if (u.timestamps?.clear) offduty++;
                else if (u.timestamps?.arrive && !u.timestamps?.clear) onscene++;
                else if (u.timestamps?.enroute && !u.timestamps?.arrive) enroute++;
                else if (u.timestamps?.dispatch) available++;
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
            const hasClearTime = unit.timestamps?.clear;
            const statusClass = hasClearTime ? 'secondary' : 'success';
            const status = hasClearTime ? 'Clear' : 'Active';
            
            return `
                <tr>
                    <td><strong>${escapeHtml(unit.unit_number || 'N/A')}</strong></td>
                    <td>${escapeHtml(unit.unit_type || 'N/A')}</td>
                    <td>${escapeHtml(unit.jurisdiction || 'N/A')}</td>
                    <td><span class="badge bg-${statusClass}">${status}</span></td>
                    <td>${unit.call ? `Call #${unit.call.call_number}` : 'None'}</td>
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
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
