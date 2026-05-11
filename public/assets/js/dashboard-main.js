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
        
        // FilterPanel instance and current query string
        let panel = null;
        let currentQs = '';

        /**
         * Handle filter changes
         */
        async function onFilterChange() {
            console.log('[Dashboard Main] Filters changed, qs:', currentQs);
            updateFilterSummary();
            window.dispatchEvent(new CustomEvent('filter-applied', { detail: { qs: currentQs } }));
            await refreshDashboard();
        }
        
        // Initialize map
        let map = null;
        const previousCallIds = new Set();

        function setDashboardLivePill(state) {
            const pill = document.getElementById('dashboard-live-pill');
            const text = document.getElementById('dashboard-live-text');
            if (!pill || !text) return;
            pill.classList.remove('is-paused', 'is-error');
            if (state === 'error') {
                pill.classList.add('is-error');
                text.textContent = 'Connection error';
            } else if (state === 'paused') {
                pill.classList.add('is-paused');
                text.textContent = 'Paused';
            } else {
                text.textContent = 'Live';
            }
        }

        if (managers.MapManager) {
            try {
                const mapEl = document.getElementById('calls-map');
                if (mapEl) {
                    map = MapManager.initMap('calls-map');
                    console.log('[Dashboard Main] Map initialized');

                    // Map container is flex-sized and grows as the right column
                    // populates with API data. Observe the container itself so
                    // Leaflet retiles whenever its size actually changes —
                    // covers initial paint, viewport resize, and post-load growth.
                    let mapResizeTimer = null;
                    const mapResizeObserver = new ResizeObserver(() => {
                        clearTimeout(mapResizeTimer);
                        mapResizeTimer = setTimeout(() => {
                            MapManager.resize('calls-map');
                        }, 150);
                    });
                    mapResizeObserver.observe(mapEl);
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
            const summaryEl = document.getElementById('filter-summary-badge');
            if (!summaryEl || !panel) return;

            const vals = panel.getState().snapshot();
            const chips = [];

            // Date chip (always present — either preset, custom range, or "All Time")
            const presets = {
                today: 'Today', yesterday: 'Yesterday',
                last_7_days: 'Last 7 Days', last_30_days: 'Last 30 Days',
                this_month: 'This Month', last_month: 'Last Month',
            };
            if (vals.preset)               chips.push({ label: presets[vals.preset] || vals.preset, kind: 'accent' });
            else if (vals.from && vals.to) chips.push({ label: `${vals.from} → ${vals.to}`, kind: 'accent' });
            else                           chips.push({ label: 'All Time', kind: 'plain' });

            // Status chips — colored per state
            if (vals.status && vals.status.length) {
                vals.status.forEach(function (s) {
                    chips.push({ label: s.charAt(0).toUpperCase() + s.slice(1), kind: 'status-' + s });
                });
            }

            // Other active filters → compact chips
            const labelMap = {
                call_type: 'Type', incident_type: 'Incident', nature_of_call: 'Nature',
                agency: 'Agency', ori: 'ORI', fdid: 'FDID',
                beat: 'Beat', area: 'Area', city: 'City',
                location: 'Location', call_id: 'Call ID', unit: 'Unit', q: 'Search',
            };
            Object.keys(labelMap).forEach(function (key) {
                const v = vals[key];
                if (!v) return;
                if (Array.isArray(v) && v.length === 0) return;
                const display = Array.isArray(v)
                    ? (v.length > 2 ? `${v.length} ${labelMap[key].toLowerCase()}s` : v.join(', '))
                    : String(v);
                if (display) chips.push({ label: `${labelMap[key]}: ${display}`, kind: 'plain' });
            });

            // Render
            renderChips(summaryEl, chips);

            // Update count badge on the Filters button
            updateFilterCountBadge(chips.length);

            // Mirror the same chips into per-stat-card pills. Active Calls
            // is special-cased: when its numeric value > 0, show a single
            // green "Live" chip instead of the filter summary.
            ['stat-total-pill', 'stat-closed-pill', 'stat-analytics-pill'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) renderChips(el, chips);
            });
            const activePill = document.getElementById('stat-active-pill');
            if (activePill) {
                const activeValEl = document.getElementById('stat-active-calls');
                const activeVal = activeValEl ? parseInt(activeValEl.textContent, 10) : NaN;
                if (Number.isFinite(activeVal) && activeVal > 0) {
                    renderChips(activePill, [{ label: 'Live', kind: 'live' }]);
                } else {
                    renderChips(activePill, chips);
                }
            }
        }

        function renderChips(el, chips) {
            el.innerHTML = '';
            chips.forEach(function (c) {
                const span = document.createElement('span');
                span.className = 'summary-chip summary-chip--' + c.kind;
                span.textContent = c.label;
                el.appendChild(span);
            });
        }

        function updateFilterCountBadge(count) {
            const btn = document.querySelector('[data-bs-target="#filter-drawer"]');
            if (!btn) return;
            let badge = btn.querySelector('.filter-count-badge');
            if (count <= 1) {
                if (badge) badge.remove();
                return;
            }
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'filter-count-badge';
                btn.appendChild(badge);
            }
            badge.textContent = String(count);
        }
        
        /**
         * Load statistics
         */
        async function loadStats() {
            console.log('[Dashboard Main] Loading stats...');
            try {
                const url = '/stats' + (currentQs ? '?' + currentQs : '');
                console.log('[Dashboard Main] Stats API URL:', url);
                const stats = await Dashboard.apiRequest(url);
                console.log('[Dashboard Main] Stats response:', stats);

                // Get units for available count (stats endpoint doesn't provide this)
                const unitsUrl = '/units?' + (currentQs ? currentQs + '&' : '') + 'per_page=1000';
                const units = await Dashboard.apiRequest(unitsUrl)
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
                
                // Update stat cards (only the 4 we kept)
                updateStatCard('stat-total-calls', totalCalls);
                updateStatCard('stat-active-calls', activeCalls);
                updateStatCard('stat-closed-calls', closedCalls);
                updateStatCard('stat-available-units', availableUnits);
                
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
         * Load active calls (status=open) into the table
         */
        let currentCallsPage = 1;
        const callsPerPage = 20;
        let totalCallsPages = 1;
        let currentCallsTotal = 0;
        
        async function loadRecentCalls(page = 1) {
            console.log('[Dashboard Main] Loading recent calls, page:', page);
            currentCallsPage = page;
            try {
                // Build query string from current filter state plus pagination params
                const pagingParams = new URLSearchParams(currentQs);
                pagingParams.set('page', page);
                pagingParams.set('per_page', callsPerPage);
                pagingParams.set('sort', 'create_datetime');
                pagingParams.set('order', 'desc');

                // Update card title based on status filter
                const titleEl = document.getElementById('recent-calls-title');
                if (titleEl) {
                    const statusVal = panel ? panel.getState().get('status') : null;
                    const statusArr = Array.isArray(statusVal) ? statusVal : (statusVal ? [statusVal] : []);
                    if (statusArr.includes('open') && !statusArr.includes('closed')) {
                        titleEl.textContent = 'Recent Active Calls';
                    } else if (statusArr.includes('closed') && !statusArr.includes('open')) {
                        titleEl.textContent = 'Recent Closed Calls';
                    } else {
                        titleEl.textContent = 'Recent Calls';
                    }
                }

                const url = '/calls?' + pagingParams.toString();
                console.log('[Dashboard Main] Recent calls API URL:', url);
                console.log('[Dashboard Main] Query params:', pagingParams.toString());
                
                console.log('[Dashboard Main] Fetching:', Dashboard.config.apiBaseUrl + url);
                
                const response = await fetch(Dashboard.config.apiBaseUrl + url);
                const result = await response.json();
                
                console.log('[Dashboard Main] Calls response:', result);
                
                if (!result.success) {
                    throw new Error(result.error || 'API failed');
                }
                
                const calls = result.data?.items || [];
                const pagination = result.data?.pagination || {};
                currentCallsTotal = pagination.total || calls.length;
                totalCallsPages = pagination.total_pages || 1;
                
                const tableBody = document.getElementById('recent-calls-body');
                
                if (!tableBody) {
                    console.warn('[Dashboard Main] recent-calls-body not found');
                    return;
                }
                
                if (calls.length === 0) {
                    tableBody.innerHTML = `
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <i class="bi bi-inbox fs-1 text-muted"></i>
                                <p class="text-muted mt-2">No calls found</p>
                            </td>
                        </tr>
                    `;
                } else {
                    tableBody.innerHTML = calls.map((call, index) => {
                        const callState = Dashboard.getCallState(call); // 'open' | 'closed' | 'reopened' | 'canceled'
                        const stateLabel = callState.charAt(0).toUpperCase() + callState.slice(1);
                        const stateClass = callState === 'reopened'
                            ? 'is-reopened'
                            : ((callState === 'closed' || callState === 'canceled') ? 'is-closed' : 'is-active');
                        const statusBadge = `<span class="pill-badge ${stateClass}">${Dashboard.escapeHtml(stateLabel)}</span>`;

                        const priorityKey = (call.priority || 'Normal');
                        const priorityClass = priorityKey === 'High'
                            ? 'is-priority-1'
                            : (priorityKey === 'Medium' ? 'is-priority-2' : 'is-priority-3');
                        const priorityBadge = `<span class="pill-badge ${priorityClass}">${Dashboard.escapeHtml(priorityKey)}</span>`;
                        
                        // Check if call has valid coordinates for zoom (reject -361 sentinel and other out-of-range)
                        const rawLat = parseFloat(call.location?.coordinates?.lat);
                        const rawLng = parseFloat(call.location?.coordinates?.lng);
                        const hasCoordinates = Number.isFinite(rawLat) && Number.isFinite(rawLng)
                            && rawLat >= -90 && rawLat <= 90 && rawLng >= -180 && rawLng <= 180;
                        const lat = hasCoordinates ? rawLat : null;
                        const lng = hasCoordinates ? rawLng : null;
                        
                        if (index === 0) {
                            console.log('[Dashboard Main] First call coordinates:', {
                                id: call.id,
                                lat: lat,
                                lng: lng,
                                hasCoordinates: hasCoordinates
                            });
                        }
                        
                        return `
                            <tr class="call-row" data-call-id="${call.id}" style="cursor: pointer;">
                                <td>${Dashboard.escapeHtml(call.call_number || call.id)}</td>
                                <td><small>${Dashboard.formatTime(call.create_datetime)}</small></td>
                                <td>${Dashboard.formatCallTypes(call)}</td>
                                <td>
                                    <small>${Dashboard.escapeHtml(call.location?.address || call.location?.city || 'No address')}</small>
                                </td>
                                <td>${priorityBadge}</td>
                                <td>${statusBadge}</td>
                                <td>
                                    <button class="btn btn-sm btn-outline-info units-btn" 
                                            data-call-id="${call.id}"
                                            ${call.unit_count ? '' : 'disabled'}>
                                        <i class="bi bi-truck"></i> ${call.unit_count || 0}
                                    </button>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-success zoom-call-btn"
                                            data-call-id="${call.id}"
                                            data-lat="${lat || ''}"
                                            data-lng="${lng || ''}"
                                            ${hasCoordinates ? '' : 'disabled'}
                                            title="${hasCoordinates ? 'View location on map' : 'No coordinates available'}">
                                        <i class="bi bi-map"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    }).join('');
                    // Mark rows whose ID wasn't in the previous render so they flash.
                    const currentIds = new Set();
                    calls.forEach(function (c) { currentIds.add(String(c.id)); });
                    tableBody.querySelectorAll('tr.call-row').forEach(function (tr) {
                        const id = tr.getAttribute('data-call-id');
                        if (id && !previousCallIds.has(id)) {
                            tr.classList.add('row-new');
                        }
                    });
                    previousCallIds.clear();
                    currentIds.forEach(function (id) { previousCallIds.add(id); });
                }
                
                console.log('[Dashboard Main] Calls table rendered:', calls.length);
                
                // Setup event delegation for zoom buttons
                setupZoomButtonHandlers();
                
                // Update pagination controls
                updateCallsPagination(pagination);
                
            } catch (error) {
                console.error('[Dashboard Main] Recent calls error:', error);
                const tableBody = document.getElementById('recent-calls-body');
                if (tableBody) {
                    tableBody.innerHTML = `
                        <tr>
                            <td colspan="8" class="text-center text-danger py-4">
                                <i class="bi bi-exclamation-triangle"></i> Failed to load calls
                            </td>
                        </tr>
                    `;
                }
                if (Dashboard.showError) {
                    Dashboard.showError('Failed to load recent calls');
                }
            }
        }
        
        /**
         * Update pagination controls
         */
        function updateCallsPagination(pagination) {
            const container = document.getElementById('calls-pagination-container');
            const prevBtn = document.getElementById('calls-prev-btn');
            const nextBtn = document.getElementById('calls-next-btn');
            const pageInfo = document.getElementById('calls-page-info');
            const showingStart = document.getElementById('calls-showing-start');
            const showingEnd = document.getElementById('calls-showing-end');
            const totalEl = document.getElementById('calls-total');
            
            if (!container || !pagination) return;

            const total = pagination.total || 0;
            const currentPage = pagination.current_page || currentCallsPage;
            const totalPages = pagination.total_pages || totalCallsPages;
            const perPage = pagination.per_page || callsPerPage;

            // Always keep the count text current. Previously these only updated
            // when totalPages > 1, so the static "0-0 of 0" from the HTML stayed
            // in the DOM whenever the result fit on one page — surfacing as
            // "Showing 0 of 0 calls" the moment any code path made the container
            // visible (or read the values directly).
            const start = total === 0 ? 0 : (currentPage - 1) * perPage + 1;
            const end   = Math.min(currentPage * perPage, total);
            if (showingStart) showingStart.textContent = start;
            if (showingEnd)   showingEnd.textContent   = end;
            if (totalEl)      totalEl.textContent      = total;
            if (pageInfo)     pageInfo.textContent     = `Page ${currentPage} of ${Math.max(totalPages, 1)}`;
            if (prevBtn)      prevBtn.disabled         = currentPage <= 1;
            if (nextBtn)      nextBtn.disabled         = currentPage >= totalPages;

            // Show pagination controls only when there's more than one page;
            // when there's just one (or none), we still want the result count
            // visible to the user, so show the container whenever there are
            // results at all.
            container.style.display = total > 0 ? 'flex' : 'none';
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
                // Build query from current filter state plus map-specific params
                const mapParams = new URLSearchParams(currentQs);
                mapParams.set('page', '1');
                mapParams.set('per_page', '100');

                // Default to showing only open calls on map if no status filter set
                if (!mapParams.has('status')) {
                    mapParams.set('status', 'open');
                }

                const url = '/calls?' + mapParams.toString();

                console.log('[Dashboard Main] Fetching calls for map from:', Dashboard.config.apiBaseUrl + url);
                console.log('[Dashboard Main] Map query params:', mapParams.toString());
                const response = await fetch(Dashboard.config.apiBaseUrl + url);
                const result = await response.json();
                
                console.log('[Dashboard Main] Map calls response:', result);
                
                if (result.success && result.data?.items) {
                    const callsWithLoc = result.data.items
                        .map(c => {
                            const lat = parseFloat(c.location?.coordinates?.lat);
                            const lng = parseFloat(c.location?.coordinates?.lng);
                            return { c, lat, lng };
                        })
                        // Aegis emits -361,-361 as a "no GPS" sentinel; reject anything outside legal ranges
                        .filter(({ lat, lng }) => Number.isFinite(lat) && Number.isFinite(lng)
                            && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180)
                        .map(({ c, lat, lng }) => ({
                            ...c,
                            latitude: lat,
                            longitude: lng,
                            address: c.location?.address || c.location?.city
                        }));
                    
                    console.log('[Dashboard Main] Calls with location:', callsWithLoc.length);
                    
                    if (callsWithLoc.length > 0) {
                        // Make sure calls-map element exists
                        const mapElement = document.getElementById('calls-map');
                        if (mapElement) {
                            console.log('[Dashboard Main] Showing', callsWithLoc.length, 'calls on map');
                            MapManager.showCalls('calls-map', callsWithLoc);
                            const markerCountEl = document.getElementById('map-marker-count');
                            if (markerCountEl) {
                                const n = callsWithLoc.length;
                                markerCountEl.textContent = n + ' marker' + (n === 1 ? '' : 's');
                            }
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
            try {
                const url = '/stats' + (currentQs ? '?' + currentQs : '');
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
                            labels: stats.top_call_types.map(t => `${Dashboard.escapeHtml(t.call_type || t.nature_of_call || 'Unknown')} (${t.count})`),
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
         * Setup event delegation for table clicks (rows and buttons)
         * Uses event delegation for performance with dynamic content
         */
        function setupZoomButtonHandlers() {
            const tableBody = document.getElementById('recent-calls-body');
            if (!tableBody) return;
            
            // Remove existing listener if any
            tableBody.removeEventListener('click', handleTableClick);
            
            // Add new listener for all clicks
            tableBody.addEventListener('click', handleTableClick);
            
            console.log('[Dashboard Main] Table click handlers attached');
        }
        
        /**
         * Handle all table clicks (rows and buttons) with event delegation
         */
        function handleTableClick(event) {
            // Check what was clicked - could be button itself or icon inside button
            let target = event.target;
            
            // If clicked on icon, get the button parent
            if (target.tagName === 'I') {
                target = target.parentElement;
            }
            
            // Now check if target IS a button or CONTAINS a button
            let zoomBtn = target.classList?.contains('zoom-call-btn') ? target : target.querySelector('.zoom-call-btn');
            let unitsBtn = target.classList?.contains('units-btn') ? target : target.querySelector('.units-btn');
            const row = target.closest('.call-row');

            console.log('[Dashboard Main] Click target:', target.tagName, target.className);
            console.log('[Dashboard Main] Found buttons:', { zoomBtn: !!zoomBtn, unitsBtn: !!unitsBtn });
            if (zoomBtn) {
                console.log('[Dashboard Main] Zoom button details:', {
                    disabled: zoomBtn.disabled,
                    hasDisabledAttr: zoomBtn.hasAttribute('disabled'),
                    callId: zoomBtn.dataset.callId,
                    lat: zoomBtn.dataset.lat,
                    lng: zoomBtn.dataset.lng
                });
            }
            
            // Priority: buttons first, then row
            if (zoomBtn && !zoomBtn.disabled) {
                // Zoom button clicked
                event.stopPropagation();
                event.preventDefault();
                
                const callId = parseInt(zoomBtn.dataset.callId);
                const lat = parseFloat(zoomBtn.dataset.lat);
                const lng = parseFloat(zoomBtn.dataset.lng);
                
                console.log('[Dashboard Main] Zoom button clicked:', { callId, lat, lng });
                zoomToCallOnMap(callId, lat, lng);
                return; // STOP HERE - don't process row click

            } else if (unitsBtn && !unitsBtn.disabled) {
                // Units button clicked
                event.stopPropagation();
                event.preventDefault();
                
                const callId = parseInt(unitsBtn.dataset.callId);
                console.log('[Dashboard Main] Units button clicked:', callId);
                showUnitsPopover(callId, unitsBtn);
                return; // STOP HERE
                
            } else if (row) {
                // Row clicked (not a button)
                const callId = parseInt(row.dataset.callId);
                console.log('[Dashboard Main] Row clicked:', callId);
                viewCallDetails(callId);
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
                setDashboardLivePill('live');
                updateFilterSummary();
                console.log('[Dashboard Main] === Refresh complete ===');
            } catch (error) {
                setDashboardLivePill('error');
                console.error('[Dashboard Main] Refresh error:', error);
            }
        }
        
    window.viewCallDetails = async function(callId) {
        console.log('[Dashboard Main] viewCallDetails called with ID:', callId);
        
        try {
            const modalEl = document.getElementById('call-detail-modal');
            if (!modalEl) {
                console.error('[Dashboard Main] Modal element not found');
                return;
            }
            
            const modal = new bootstrap.Modal(modalEl);
            const content = document.getElementById('call-detail-content');
            
            if (!content) {
                console.error('[Dashboard Main] Modal content element not found');
                return;
            }
            
            content.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';
            modal.show();
            
            console.log('[Dashboard Main] Fetching call details for ID:', callId);
            
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
                    <div class="row mb-3">
                        <div class="col-12">
                            <h5>Agency Contexts (${uniqueContexts.length})</h5>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr><th>Agency Type</th><th>Call Type</th><th>Priority</th><th>Status</th><th>Dispatcher</th><th>Timestamp</th></tr></thead>
                                    <tbody>
                                        ${uniqueContexts.map(ac => `
                                            <tr>
                                                <td>${Dashboard.escapeHtml(ac.agency_type || 'N/A')}</td>
                                                <td>${Dashboard.escapeHtml(ac.call_type || 'N/A')}</td>
                                                <td>${Dashboard.escapeHtml(ac.priority || 'N/A')}</td>
                                                <td>${Dashboard.getStatusBadge(ac.status)}</td>
                                                <td>${Dashboard.escapeHtml(ac.dispatcher || 'N/A')}</td>
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
                
                <div class="row">
                    <div class="col-md-6">
                        <h5>Call Information</h5>
                        <table class="table table-sm">
                            <tr><th>Call ID:</th><td>${Dashboard.escapeHtml(call.id)}</td></tr>
                            <tr><th>Call Number:</th><td>${Dashboard.escapeHtml(call.call_number || 'N/A')}</td></tr>
                            <tr><th>Call Source:</th><td>${Dashboard.escapeHtml(call.call_source || 'N/A')}</td></tr>
                            <tr><th>Nature of Call:</th><td>${Dashboard.escapeHtml(call.nature_of_call || 'N/A')}</td></tr>
                            <tr><th>Priority:</th><td>${Dashboard.getPriorityBadge(latestPriority)}</td></tr>
                            <tr><th>Status:</th><td>${Dashboard.getStatusBadge(latestStatus)}</td></tr>
                            <tr><th>Received:</th><td>${Dashboard.formatDateTime(call.received_time)}</td></tr>
                            <tr><th>Created:</th><td>${Dashboard.formatDateTime(call.create_datetime)}</td></tr>
                            <tr><th>Closed:</th><td>${call.close_datetime ? Dashboard.formatDateTime(call.close_datetime) : 'N/A'}</td></tr>
                            <tr><th>Created By:</th><td>${Dashboard.escapeHtml(call.created_by || 'N/A')}</td></tr>
                            <tr><th>Alarm Level:</th><td>${Dashboard.escapeHtml(call.alarm_level || 'N/A')}</td></tr>
                            <tr><th>EMD Code:</th><td>${Dashboard.escapeHtml(call.emd_code || 'N/A')}</td></tr>
                            <tr><th>Status:</th><td>${Dashboard.getCallStateBadge(call)}</td></tr>
                            <tr><th>Canceled:</th><td>${call.canceled_flag ? '<span class="badge bg-warning">Yes</span>' : '<span class="badge bg-success">No</span>'}</td></tr>
                        </table>
                        
                        ${call.caller && (call.caller.name || call.caller.phone) ? `
                            <h5 class="mt-3">Caller Information</h5>
                            <table class="table table-sm">
                                ${call.caller.name ? `<tr><th>Name:</th><td>${Dashboard.escapeHtml(call.caller.name)}</td></tr>` : ''}
                                ${call.caller.phone ? `<tr><th>Phone:</th><td>${Dashboard.escapeHtml(call.caller.phone)}</td></tr>` : ''}
                            </table>
                        ` : ''}
                    </div>
                    <div class="col-md-6">
                        <h5>Location</h5>
                        <table class="table table-sm">
                            <tr><th>Full Address:</th><td>${Dashboard.escapeHtml(call.location?.full_address || 'N/A')}</td></tr>
                            ${call.location?.house_number ? `<tr><th>House Number:</th><td>${Dashboard.escapeHtml(call.location.house_number)}</td></tr>` : ''}
                            ${call.location?.prefix_directional ? `<tr><th>Direction:</th><td>${Dashboard.escapeHtml(call.location.prefix_directional)}</td></tr>` : ''}
                            ${call.location?.street_name ? `<tr><th>Street Name:</th><td>${Dashboard.escapeHtml(call.location.street_name)}</td></tr>` : ''}
                            ${call.location?.street_type ? `<tr><th>Street Type:</th><td>${Dashboard.escapeHtml(call.location.street_type)}</td></tr>` : ''}
                            <tr><th>City:</th><td>${Dashboard.escapeHtml(call.location?.city || 'N/A')}</td></tr>
                            ${call.location?.state ? `<tr><th>State:</th><td>${Dashboard.escapeHtml(call.location.state)}</td></tr>` : ''}
                            ${call.location?.zip ? `<tr><th>ZIP:</th><td>${Dashboard.escapeHtml(call.location.zip)}</td></tr>` : ''}
                            ${call.location?.common_name ? `<tr><th>Common Name:</th><td>${Dashboard.escapeHtml(call.location.common_name)}</td></tr>` : ''}
                            ${call.location?.nearest_cross_streets ? `<tr><th>Cross Streets:</th><td>${Dashboard.escapeHtml(call.location.nearest_cross_streets)}</td></tr>` : ''}
                            <tr><th>Coordinates:</th><td>${call.location?.coordinates ? `${Dashboard.escapeHtml(call.location.coordinates.lat)}, ${Dashboard.escapeHtml(call.location.coordinates.lng)}` : 'N/A'}</td></tr>
                            ${call.location?.police_beat ? `<tr><th>Police Beat:</th><td>${Dashboard.escapeHtml(call.location.police_beat)}</td></tr>` : ''}
                            ${call.location?.ems_district ? `<tr><th>EMS District:</th><td>${Dashboard.escapeHtml(call.location.ems_district)}</td></tr>` : ''}
                            ${call.location?.fire_quadrant ? `<tr><th>Fire Quadrant:</th><td>${Dashboard.escapeHtml(call.location.fire_quadrant)}</td></tr>` : ''}
                        </table>
                    </div>
                </div>
                
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
                                                <td>${Dashboard.escapeHtml(inc.agency_type || 'N/A')}</td>
                                                <td>${Dashboard.escapeHtml(inc.incident_number || 'N/A')}</td>
                                                <td>${Dashboard.escapeHtml(inc.incident_type || 'N/A')}</td>
                                                <td>${Dashboard.escapeHtml(inc.jurisdiction || 'N/A')}</td>
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
                                                <td>${Dashboard.escapeHtml(inc.agency_type || 'N/A')}</td>
                                                <td>${Dashboard.escapeHtml(inc.jurisdiction || 'N/A')}</td>
                                                <td>${Dashboard.escapeHtml(inc.incident_number || 'N/A')}</td>
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
                                                <td>${Dashboard.escapeHtml(u.unit_number || u.unit_id || 'N/A')}</td>
                                                <td>${Dashboard.escapeHtml(u.unit_type || 'N/A')}</td>
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
                                                <td>${Dashboard.escapeHtml(fullName || 'N/A')}</td>
                                                <td><span class="badge bg-info">${Dashboard.escapeHtml(p.role || 'N/A')}</span></td>
                                                <td>${Dashboard.escapeHtml(p.contact_phone || '-')}</td>
                                                <td>${p.date_of_birth ? Dashboard.formatDateTime(p.date_of_birth) : '-'}</td>
                                                <td>${Dashboard.escapeHtml(p.sex || '-')}</td>
                                                <td>${Dashboard.escapeHtml(p.race || '-')}</td>
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
                                            ${n.create_user ? `<small class="text-muted">By: ${Dashboard.escapeHtml(n.create_user)}</small>` : ''}
                                            ${n.narrative_type ? `<span class="badge bg-info ms-2">${Dashboard.escapeHtml(n.narrative_type)}</span>` : ''}
                                        </div>
                                        <p class="mb-0 mt-2">${Dashboard.escapeHtml(n.text)}</p>
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
        
        // Global function for filtering dashboard by status
        // Global function for showing units popover
        window.showUnitsPopover = async function(callId, buttonElement) {
            console.log('[Dashboard Main] Showing units for call:', callId);
            
            try {
                // Fetch units for this call
                const response = await fetch(`${Dashboard.config.apiBaseUrl}/calls/${callId}/units`);
                const result = await response.json();
                
                // Handle different response formats (items vs data array)
                const units = result.success ? (result.data || result.items || []) : [];
                
                if (units.length === 0) {
                    // Show "no units" message
                    showPopover(buttonElement, '<div class="text-muted"><i class="bi bi-info-circle"></i> No units assigned</div>');
                    return;
                }
                
                // Build units list HTML
                const unitsHtml = units.map(u => {
                    // Determine status from timestamps
                    const status = u.timestamps?.clear ? 'Clear' : 
                                  u.timestamps?.arrive ? 'On Scene' :
                                  u.timestamps?.enroute ? 'Enroute' :
                                  u.timestamps?.dispatch ? 'Dispatched' :
                                  u.timestamps?.assigned ? 'Assigned' : 'Unknown';
                    
                    const statusBadge = status === 'Clear' ? 'success' :
                                       status === 'On Scene' ? 'primary' :
                                       status === 'Enroute' ? 'warning' :
                                       status === 'Dispatched' ? 'info' :
                                       status === 'Assigned' ? 'secondary' : 'secondary';
                    
                    const primaryBadge = u.is_primary ? '<span class="badge bg-danger ms-1">Primary</span>' : '';
                    
                    return `
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <div>
                                <strong>${Dashboard.escapeHtml(u.unit_number || 'Unknown')}</strong>
                                ${primaryBadge}
                                ${u.unit_type ? `<br><small class="text-muted">${Dashboard.escapeHtml(u.unit_type)}</small>` : ''}
                            </div>
                            <span class="badge bg-${statusBadge}">${Dashboard.escapeHtml(status)}</span>
                        </div>
                    `;
                }).join('');
                
                const popoverContent = `
                    <div style="min-width: 200px; max-width: 300px;">
                        <div class="fw-bold mb-2">
                            <i class="bi bi-truck"></i> Assigned Units (${units.length})
                        </div>
                        ${unitsHtml}
                        <div class="text-center mt-2">
                            <button id="units-full-details-btn" class="btn btn-sm btn-outline-primary" data-call-id="${callId}">
                                <i class="bi bi-eye"></i> Full Details
                            </button>
                        </div>
                    </div>
                `;
                
                showPopover(buttonElement, popoverContent);
                
                // Add event listener to the button after popover is created
                setTimeout(() => {
                    const detailsBtn = document.getElementById('units-full-details-btn');
                    if (detailsBtn) {
                        detailsBtn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            const callId = this.getAttribute('data-call-id');
                            console.log('[Dashboard Main] Full Details clicked for call:', callId);
                            hidePopover();
                            setTimeout(() => {
                                viewCallDetails(parseInt(callId));
                            }, 150);
                        });
                    }
                }, 50);
                
            } catch (error) {
                console.error('[Dashboard Main] Error fetching units:', error);
                showPopover(buttonElement, '<div class="text-danger"><i class="bi bi-exclamation-triangle"></i> Failed to load units</div>');
            }
        };
        
        // Helper function to show popover
        function showPopover(element, content) {
            // Remove any existing popover
            hidePopover();
            
            // Create popover element
            const popover = document.createElement('div');
            popover.id = 'units-popover';
            popover.className = 'popover bs-popover-auto fade show';
            popover.style.cssText = 'position: absolute; z-index: 1070;';
            
            popover.innerHTML = `
                <div class="popover-arrow"></div>
                <div class="popover-body p-3">
                    ${content}
                </div>
            `;
            
            document.body.appendChild(popover);
            
            // Position popover
            const rect = element.getBoundingClientRect();
            popover.style.left = (rect.left - popover.offsetWidth - 10) + 'px';
            popover.style.top = (rect.top - (popover.offsetHeight / 2) + (rect.height / 2)) + 'px';
            
            // Add click listener to close on outside click
            setTimeout(() => {
                document.addEventListener('click', hidePopover);
            }, 100);
        }
        
        // Helper function to hide popover
        window.hidePopover = function() {
            const popover = document.getElementById('units-popover');
            if (popover) {
                popover.remove();
                document.removeEventListener('click', hidePopover);
            }
        };
        
        window.filterDashboard = function(status) {
            console.log('[Dashboard Main] Filtering dashboard to:', status);

            if (!panel) {
                console.error('[Dashboard Main] FilterPanel not initialized');
                return;
            }

            // UI vocabulary 'active' maps to canonical API status 'open'.
            // Without this translation the API rejects the request (VALID_STATUSES = open|closed|canceled).
            if (status === 'active') status = 'open';

            // Update the panel state
            if (status === 'all') {
                panel.getState().merge({ status: [] });
            } else {
                panel.getState().merge({ status: [status] });
            }

            // Sync URL and localStorage (mirrors what FilterPanel._apply() does)
            const qs = panel.getState().toQueryString();
            const newUrl = window.location.pathname + (qs ? '?' + qs : '');
            window.history.replaceState({}, '', newUrl);
            localStorage.setItem('filter-panel:last-state', JSON.stringify(panel.getState().snapshot()));
            currentQs = qs;

            onFilterChange();

            console.log('[Dashboard Main] Dashboard filtered to:', status);
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
        
        // Heal legacy state where status=active leaked into URL/localStorage from
        // an earlier build (API only accepts open|closed|canceled).
        (function migrateLegacyStatus() {
            const params = new URLSearchParams(window.location.search);
            const raw = params.get('status');
            if (raw && raw.split(',').indexOf('active') >= 0) {
                const fixed = raw.split(',').map(function (s) {
                    return s.trim() === 'active' ? 'open' : s.trim();
                }).filter(Boolean);
                params.set('status', fixed.join(','));
                window.history.replaceState({}, '', window.location.pathname + '?' + params.toString());
            }
            const lastRaw = localStorage.getItem('filter-panel:last-state');
            if (lastRaw) {
                try {
                    const last = JSON.parse(lastRaw);
                    if (Array.isArray(last.status) && last.status.indexOf('active') >= 0) {
                        last.status = last.status.map(function (s) { return s === 'active' ? 'open' : s; });
                        localStorage.setItem('filter-panel:last-state', JSON.stringify(last));
                    }
                } catch (_) { /* ignore corrupt entry */ }
            }
        })();

        // Pre-populate URL with sensible defaults (today + open) when there's
        // no existing URL state and no saved state. This is the dispatcher's
        // most common view — start there instead of an empty filter set.
        if (!window.location.search && !localStorage.getItem('filter-panel:last-state')) {
            const url = new URL(window.location);
            url.searchParams.set('preset', 'today');
            url.searchParams.set('status', 'open');
            window.history.replaceState({}, '', url);
        }

        // Initialize FilterPanel
        panel = new FilterPanel({
            root: document.getElementById('filter-panel'),
            onChange: function (state) {
                currentQs = state.toQueryString();
                onFilterChange();
            },
        });
        await panel.mount();
        currentQs = panel.getState().toQueryString();
        updateFilterSummary();
        
        // Initialize pagination event listeners
        const prevBtn = document.getElementById('calls-prev-btn');
        const nextBtn = document.getElementById('calls-next-btn');
        
        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (currentCallsPage > 1) {
                    loadRecentCalls(currentCallsPage - 1);
                }
            });
        }
        
        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                if (currentCallsPage < totalCallsPages) {
                    loadRecentCalls(currentCallsPage + 1);
                }
            });
        }
        
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

/**
 * Global function to open map zoom modal for a specific call
 * Called from Recent Calls table zoom buttons
 * 
 * @param {number} callId - Call ID for reference
 * @param {number} latitude - Call latitude
 * @param {number} longitude - Call longitude
 */
window.zoomToCallOnMap = async function(callId, latitude, longitude) {
    console.log('[Dashboard Main] Opening map zoom modal for call:', { callId, latitude, longitude });
    
    // Validate coordinates
    const lat = parseFloat(latitude);
    const lon = parseFloat(longitude);
    
    if (isNaN(lat) || isNaN(lon)) {
        console.error('[Dashboard Main] Invalid coordinates');
        Dashboard.showError('Invalid call coordinates');
        return;
    }
    
    // Validate MapManager is available
    if (typeof MapManager === 'undefined') {
        console.error('[Dashboard Main] MapManager not available');
        Dashboard.showError('Map functionality not available');
        return;
    }
    
    try {
        // Store call ID globally for "View Full Details" button
        window.currentModalCallId = callId;
        
        // Fetch call details
        console.log('[Dashboard Main] Fetching call details...');
        const call = await Dashboard.apiRequest(`/calls/${callId}`);
        console.log('[Dashboard Main] Call details received:', call);
        
        // Check if modal element exists
        const modalEl = document.getElementById('map-zoom-modal');
        if (!modalEl) {
            console.error('[Dashboard Main] Modal element #map-zoom-modal not found!');
            Dashboard.showError('Map modal not found on page');
            return;
        }
        console.log('[Dashboard Main] Modal element found');
        
        // Check if Bootstrap is available
        if (typeof bootstrap === 'undefined') {
            console.error('[Dashboard Main] Bootstrap not loaded!');
            Dashboard.showError('Bootstrap library not available');
            return;
        }
        console.log('[Dashboard Main] Bootstrap is available');
        
        // Update modal title and info
        const titleEl = document.getElementById('map-modal-call-id');
        const typeEl = document.getElementById('map-modal-call-type');
        const addressEl = document.getElementById('map-modal-address');
        const priorityEl = document.getElementById('map-modal-priority');
        const statusEl = document.getElementById('map-modal-status');
        const timeEl = document.getElementById('map-modal-time');
        
        if (titleEl) titleEl.textContent = `Call #${call.call_number || callId}`;
        if (typeEl) {
            // textContent for the unescaped fallback path; use innerText only via the
            // helper which escapes. Set via textContent of joined string for safety.
            const types = Array.isArray(call.call_types) ? call.call_types.filter(Boolean) : [];
            typeEl.textContent = types.length ? types.join(' / ') : (call.nature_of_call || 'Unknown');
        }
        if (addressEl) addressEl.textContent = call.location?.address || call.location?.city || 'No address';
        if (priorityEl) priorityEl.innerHTML = Dashboard.getPriorityBadge(call.agency_contexts?.[0]?.priority || call.priority || 'Normal');
        if (statusEl) statusEl.innerHTML = Dashboard.getCallStateBadge(call);
        if (timeEl) timeEl.textContent = Dashboard.formatDateTime(call.create_datetime);
        
        console.log('[Dashboard Main] Modal content updated');
        
        // Open the modal
        console.log('[Dashboard Main] Creating Bootstrap modal...');
        const modal = new bootstrap.Modal(modalEl);
        console.log('[Dashboard Main] Calling modal.show()...');
        modal.show();
        console.log('[Dashboard Main] Modal.show() called');
        
        // Initialize map after modal is shown (ensures proper rendering)
        modalEl.addEventListener('shown.bs.modal', function initModalMap() {
            console.log('[Dashboard Main] Modal shown, initializing map...');
            
            // Initialize or get existing map
            const mapId = 'modal-map';
            let map = MapManager.maps[mapId];
            
            if (!map) {
                // Create new map instance
                map = MapManager.initMap(mapId, [lat, lon], 16);
                console.log('[Dashboard Main] Created new modal map');
            } else {
                // Map exists, just update view
                console.log('[Dashboard Main] Reusing existing modal map');
                map.setView([lat, lon], 16);
            }
            
            // Clear existing markers and add new one for this call
            MapManager.clearMarkers(mapId);
            
            // Add marker with call info
            const marker = MapManager.addCallMarker(mapId, {
                ...call,
                latitude: lat,
                longitude: lon,
                id: callId
            });
            
            // Open popup automatically
            if (marker) {
                marker.openPopup();
            }
            
            // Force map to refresh its tiles
            setTimeout(() => {
                map.invalidateSize();
            }, 100);
            
            // Remove this event listener after first use
            modalEl.removeEventListener('shown.bs.modal', initModalMap);
        }, { once: true });
        
        // Cleanup when modal is hidden
        modalEl.addEventListener('hidden.bs.modal', function() {
            console.log('[Dashboard Main] Modal hidden');
            window.currentModalCallId = null;
        }, { once: true });
        
    } catch (error) {
        console.error('[Dashboard Main] Error loading call for map:', error);
        Dashboard.showError('Failed to load call location');
    }
};
