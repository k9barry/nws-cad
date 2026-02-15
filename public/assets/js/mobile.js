/**
 * NWS CAD Dashboard - Mobile JavaScript
 * Mobile-specific functionality and optimizations
 * 
 * @module MobileDashboard
 * @version 1.0.0
 */

'use strict';

// Constants
const MOBILE_CONFIG = {
    REFRESH_INTERVAL_MS: 30000,
    PULL_TO_REFRESH_THRESHOLD: 80,
    DEFAULT_PER_PAGE: 15,
    MAP_CENTER: [40.1184, -85.6900],
    MAP_BOUNDS: [[39.90, -85.90], [40.35, -85.45]],
    MAP_MIN_ZOOM: 10.5,
    MAP_MAX_ZOOM: 21,
    MAP_MARKERS_LIMIT: 100
};

/**
 * Mobile Dashboard Controller
 */
const MobileDashboard = {
    config: window.APP_CONFIG || {},
    currentPage: 1,
    perPage: MOBILE_CONFIG.DEFAULT_PER_PAGE,
    totalCalls: 0,
    refreshInterval: null,
    filters: {},
    
    /**
     * Initialize mobile dashboard
     */
    init() {
        console.log('[Mobile] Initializing mobile dashboard');
        
        // Load saved filters
        this.filters = Dashboard.filters.load() || this.getDefaultFilters();
        
        // Initialize components
        this.initBottomNav();
        this.initPullToRefresh();
        this.initLiveUpdates();
        this.initCleanup();
        
        // Load initial data
        this.loadCallsList();
        this.loadStats();
        
        // Set up auto-refresh
        this.startAutoRefresh();
        
        console.log('[Mobile] Mobile dashboard initialized');
    },
    
    /**
     * Initialize cleanup handlers for memory management
     */
    initCleanup() {
        window.addEventListener('beforeunload', () => {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
        });
    },
    
    /**
     * Get default filters
     * @returns {Object} Default filter configuration
     */
    getDefaultFilters() {
        return {
            date_from: '',
            date_to: '',
            quick_select: '7days',
            jurisdiction: '',
            agency: '',
            call_type: '',
            status: '',
            priority: ''
        };
    },
    
    /**
     * Initialize bottom navigation
     */
    initBottomNav() {
        const navItems = document.querySelectorAll('.mobile-nav-item');
        navItems.forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const action = item.dataset.action;
                this.handleNavAction(action, e.currentTarget);
            });
        });
    },
    
    /**
     * Handle navigation actions
     * @param {string} action - Navigation action
     * @param {HTMLElement} targetElement - Clicked element
     */
    handleNavAction(action, targetElement) {
        console.log('[Mobile] Nav action:', action);
        
        // Update active state
        document.querySelectorAll('.mobile-nav-item').forEach(item => {
            item.classList.remove('active');
        });
        if (targetElement) {
            targetElement.classList.add('active');
        }
        
        switch(action) {
            case 'calls':
                this.showCallsList();
                break;
            case 'map':
                this.showMap();
                break;
            case 'filters':
                this.openFiltersModal();
                break;
            case 'analytics':
                this.openAnalyticsModal();
                break;
            case 'refresh':
                this.refreshData();
                break;
        }
    },
    
    /**
     * Show calls list view
     */
    showCallsList() {
        const callsView = document.getElementById('mobile-calls-view');
        const mapView = document.getElementById('mobile-map-view');
        
        if (callsView) callsView.style.display = 'block';
        if (mapView) mapView.style.display = 'none';
        
        this.loadCallsList();
    },
    
    /**
     * Show map view
     */
    showMap() {
        const callsView = document.getElementById('mobile-calls-view');
        const mapView = document.getElementById('mobile-map-view');
        
        if (callsView) callsView.style.display = 'none';
        if (mapView) mapView.style.display = 'block';
        
        // Initialize or refresh map
        if (typeof MobileMaps !== 'undefined' && MobileMaps.initMap) {
            MobileMaps.initMap();
        }
    },
    
    /**
     * Load calls list from API
     */
    async loadCallsList() {
        const container = document.getElementById('mobile-calls-list');
        if (!container) return;
        
        // Show loading
        container.innerHTML = `
            <div class="mobile-loading">
                <div class="spinner-border text-primary"></div>
                <p class="text-muted mt-2">Loading calls...</p>
            </div>
        `;
        
        try {
            // Build query params with sort order
            const params = {
                page: this.currentPage,
                per_page: this.perPage,
                sort: 'create_datetime',
                order: 'desc',
                ...this.buildFilterParams()
            };
            
            // Default to active calls if no status filter (match desktop behavior)
            if (!this.filters.status) {
                params.closed_flag = 'false';
            }
            
            const queryString = Dashboard.buildQueryString(params);
            const data = await Dashboard.apiRequest(`/calls${queryString}`);
            
            this.totalCalls = data.pagination?.total || 0;
            this.renderCallsList(data.items || []);
            
        } catch (error) {
            console.error('[Mobile] Error loading calls:', error);
            container.innerHTML = `
                <div class="mobile-empty-state">
                    <i class="bi bi-exclamation-triangle"></i>
                    <h4>Error Loading Calls</h4>
                    <p>${this.escapeHtml(error.message)}</p>
                    <button class="btn btn-primary mt-3" onclick="MobileDashboard.loadCallsList()">
                        Try Again
                    </button>
                </div>
            `;
        }
    },
    
    /**
     * Build filter parameters for API request
     * @returns {Object} Filter parameters
     */
    buildFilterParams() {
        const params = {};
        
        // Handle quick select
        if (this.filters.quick_select && this.filters.quick_select !== 'custom') {
            const dates = this.getQuickSelectDates(this.filters.quick_select);
            if (dates) {
                params.date_from = dates.from;
                params.date_to = dates.to;
            }
        } else if (this.filters.date_from && this.filters.date_to) {
            params.date_from = this.filters.date_from;
            params.date_to = this.filters.date_to;
        }
        
        // Handle status filter (convert to closed_flag)
        if (this.filters.status === 'active') {
            params.closed_flag = 'false';
        } else if (this.filters.status === 'closed') {
            params.closed_flag = 'true';
        }
        
        // Add other filters (excluding status since we handle it above)
        ['jurisdiction', 'agency', 'call_type', 'priority'].forEach(key => {
            if (this.filters[key]) {
                params[key] = this.filters[key];
            }
        });
        
        return params;
    },
    
    /**
     * Get date range from quick select option
     * @param {string} quickSelect - Quick select value
     * @returns {Object|null} Date range with from and to properties
     */
    getQuickSelectDates(quickSelect) {
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        
        const formatDate = (date) => date.toISOString().split('T')[0];
        
        switch(quickSelect) {
            case 'today':
                return {
                    from: formatDate(today) + ' 00:00:00',
                    to: formatDate(today) + ' 23:59:59'
                };
            case 'yesterday': {
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                return {
                    from: formatDate(yesterday) + ' 00:00:00',
                    to: formatDate(yesterday) + ' 23:59:59'
                };
            }
            case '7days': {
                const sevenDaysAgo = new Date(today);
                sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);
                return {
                    from: formatDate(sevenDaysAgo) + ' 00:00:00',
                    to: formatDate(today) + ' 23:59:59'
                };
            }
            case '30days': {
                const thirtyDaysAgo = new Date(today);
                thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
                return {
                    from: formatDate(thirtyDaysAgo) + ' 00:00:00',
                    to: formatDate(today) + ' 23:59:59'
                };
            }
            default:
                return null;
        }
    },
    
    /**
     * Render calls list
     * @param {Array} calls - Array of call objects
     */
    renderCallsList(calls) {
        const container = document.getElementById('mobile-calls-list');
        if (!container) return;
        
        if (calls.length === 0) {
            container.innerHTML = `
                <div class="mobile-empty-state">
                    <i class="bi bi-inbox"></i>
                    <h4>No Calls Found</h4>
                    <p>Try adjusting your filters</p>
                </div>
            `;
            return;
        }
        
        container.innerHTML = calls.map(call => this.renderCallItem(call)).join('');
        
        // Update showing text
        this.updateShowingText();
    },
    
    /**
     * Render single call item
     * @param {Object} call - Call object
     * @returns {string} HTML string
     */
    renderCallItem(call) {
        const callType = Array.isArray(call.call_types) ? call.call_types[0] : (call.nature_of_call || 'Unknown');
        const priority = Array.isArray(call.priorities) ? call.priorities[0] : '';
        const locationText = call.location?.address || call.location?.city || 'No location';
        
        // Use closed_flag like desktop
        const statusBadge = call.closed_flag 
            ? '<span class="badge bg-success">Closed</span>' 
            : '<span class="badge bg-warning text-dark">Open</span>';
        
        const callId = parseInt(call.id, 10);
        if (isNaN(callId)) {
            console.warn('[Mobile] Invalid call ID:', call.id);
            return '';
        }
        
        return `
            <div class="mobile-call-item" onclick="MobileDashboard.openCallDetails(${callId})">
                <div class="mobile-call-header">
                    <span class="mobile-call-id">#${this.escapeHtml(String(call.call_number || call.call_id))}</span>
                    <span class="mobile-call-time">${this.formatTime(call.create_datetime)}</span>
                </div>
                <div class="mobile-call-type">${this.escapeHtml(callType)}</div>
                <div class="mobile-call-location">
                    <i class="bi bi-geo-alt"></i>
                    <span>${this.escapeHtml(locationText)}</span>
                </div>
                <div class="mobile-call-footer">
                    ${priority ? `<span class="badge bg-danger">${this.escapeHtml(priority)}</span>` : ''}
                    ${statusBadge}
                    ${call.unit_count ? `<span class="badge bg-info">${parseInt(call.unit_count, 10)} Units</span>` : ''}
                </div>
            </div>
        `;
    },
    
    /**
     * Format time for display
     * @param {string} datetime - ISO datetime string
     * @returns {string} Formatted time string
     */
    formatTime(datetime) {
        if (!datetime) return 'Unknown';
        
        const date = new Date(datetime);
        if (isNaN(date.getTime())) return 'Invalid date';
        
        const now = new Date();
        const diff = now - date;
        const hours = Math.floor(diff / (1000 * 60 * 60));
        const minutes = Math.floor(diff / (1000 * 60));
        
        if (minutes < 1) return 'Just now';
        if (minutes < 60) return `${minutes}m ago`;
        if (hours < 24) return `${hours}h ago`;
        
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    },
    
    /**
     * Escape HTML to prevent XSS
     * @param {string} text - Text to escape
     * @returns {string} Escaped HTML string
     */
    escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    },
    
    /**
     * Update showing text
     */
    updateShowingText() {
        const start = (this.currentPage - 1) * this.perPage + 1;
        const end = Math.min(this.currentPage * this.perPage, this.totalCalls);
        
        const showingEl = document.getElementById('mobile-showing-text');
        if (showingEl) {
            showingEl.textContent = `Showing ${start}-${end} of ${this.totalCalls}`;
        }
    },
    
    /**
     * Load stats from API
     */
    async loadStats() {
        try {
            const params = this.buildFilterParams();
            const queryString = Dashboard.buildQueryString(params);
            const stats = await Dashboard.apiRequest(`/stats${queryString}`);
            
            const statTotal = document.getElementById('stat-total');
            const statActive = document.getElementById('stat-active');
            const statClosed = document.getElementById('stat-closed');
            
            if (statTotal) statTotal.textContent = stats.total_calls || 0;
            if (statActive) statActive.textContent = stats.calls_by_status?.open || 0;
            if (statClosed) statClosed.textContent = stats.calls_by_status?.closed || 0;
            
        } catch (error) {
            console.error('[Mobile] Error loading stats:', error);
        }
    },
    
    /**
     * Open call details modal
     * @param {number} callId - Call ID to display
     */
    async openCallDetails(callId) {
        // Validate callId
        const validCallId = parseInt(callId, 10);
        if (isNaN(validCallId) || validCallId <= 0) {
            console.error('[Mobile] Invalid call ID:', callId);
            return;
        }
        
        console.log('[Mobile] Opening call details:', validCallId);
        
        const modalEl = document.getElementById('mobile-call-detail-modal');
        if (!modalEl) {
            console.error('[Mobile] Call detail modal not found');
            return;
        }
        
        // Show modal
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
        
        // Load call details
        const modalBody = modalEl.querySelector('.modal-body');
        if (!modalBody) return;
        
        modalBody.innerHTML = `
            <div class="mobile-loading">
                <div class="spinner-border text-primary"></div>
                <p class="text-muted mt-2">Loading details...</p>
            </div>
        `;
        
        try {
            // Fetch all data in parallel like desktop
            const [call, unitsResponse, narrativesResponse, personsResponse] = await Promise.all([
                Dashboard.apiRequest(`/calls/${validCallId}`),
                Dashboard.apiRequest(`/calls/${validCallId}/units`).catch(() => ({ items: [] })),
                Dashboard.apiRequest(`/calls/${validCallId}/narratives`).catch(() => ({ items: [] })),
                Dashboard.apiRequest(`/calls/${validCallId}/persons`).catch(() => [])
            ]);
            
            const units = unitsResponse?.items || unitsResponse || [];
            const narratives = narrativesResponse?.items || narrativesResponse || [];
            const persons = personsResponse?.data || personsResponse || [];
            
            this.renderCallDetails(call, units, narratives, persons);
        } catch (error) {
            console.error('[Mobile] Error loading call details:', error);
            modalBody.innerHTML = `
                <div class="alert alert-danger">
                    Error loading call details: ${this.escapeHtml(error.message)}
                </div>
            `;
        }
    },
    
    /**
     * Render call details in modal
     * @param {Object} call - Call object
     * @param {Array} units - Array of unit objects
     * @param {Array} narratives - Array of narrative objects
     * @param {Array} persons - Array of person objects
     */
    renderCallDetails(call, units, narratives, persons) {
        const modalBody = document.querySelector('#mobile-call-detail-modal .modal-body');
        if (!modalBody) return;
        
        // Get LATEST agency context (sorted by created_datetime descending) - like desktop
        let latestPriority = 'N/A';
        let latestStatus = 'N/A';
        let callType = call.nature_of_call || 'Unknown';
        
        if (call.agency_contexts && call.agency_contexts.length > 0) {
            const sortedContexts = [...call.agency_contexts].sort((a, b) => 
                new Date(b.created_datetime) - new Date(a.created_datetime)
            );
            latestPriority = sortedContexts[0].priority || 'N/A';
            latestStatus = sortedContexts[0].status || (call.closed_flag ? 'Closed' : 'Open');
            callType = sortedContexts[0].call_type || call.nature_of_call || 'Unknown';
        }
        
        modalBody.innerHTML = `
            ${this.renderAgencyContextsSection(call.agency_contexts)}
            ${this.renderCallInfoSection(call, callType, latestPriority, latestStatus)}
            ${this.renderLocationSection(call.location)}
            ${this.renderCallerSection(call.caller)}
            ${this.renderIncidentsSection(call.incidents)}
            ${this.renderUnitsSection(units, call.counts?.units)}
            ${this.renderPersonsSection(persons)}
            ${this.renderNarrativesSection(narratives, call.counts?.narratives)}
        `;
    },
    
    /**
     * Render agency contexts section (deduplicated by agency_type like desktop)
     * @param {Array} agencyContexts - Array of agency context objects
     * @returns {string} HTML string
     */
    renderAgencyContextsSection(agencyContexts) {
        if (!agencyContexts || agencyContexts.length === 0) return '';
        
        // Deduplicate by agency_type - keep latest per agency type (like desktop)
        const uniqueContexts = [];
        const seenAgencyTypes = new Set();
        
        // Sort by created_datetime descending to get latest first
        const sortedContexts = [...agencyContexts].sort((a, b) => 
            new Date(b.created_datetime) - new Date(a.created_datetime)
        );
        
        for (const ac of sortedContexts) {
            if (!seenAgencyTypes.has(ac.agency_type)) {
                seenAgencyTypes.add(ac.agency_type);
                uniqueContexts.push(ac);
            }
        }
        
        return `
            <div class="mobile-detail-section">
                <h6>Agency Contexts (${uniqueContexts.length})</h6>
                ${uniqueContexts.map(ac => `
                    <div class="card mb-2">
                        <div class="card-body p-2">
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Agency</span>
                                <span class="mobile-detail-value">${this.escapeHtml(ac.agency_type || 'N/A')}</span>
                            </div>
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Type</span>
                                <span class="mobile-detail-value">${this.escapeHtml(ac.call_type || 'N/A')}</span>
                            </div>
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Priority</span>
                                <span class="mobile-detail-value"><span class="badge bg-danger">${this.escapeHtml(ac.priority || 'N/A')}</span></span>
                            </div>
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Status</span>
                                <span class="mobile-detail-value"><span class="badge bg-warning">${this.escapeHtml(ac.status || 'N/A')}</span></span>
                            </div>
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Dispatcher</span>
                                <span class="mobile-detail-value">${this.escapeHtml(ac.dispatcher || 'N/A')}</span>
                            </div>
                            ${ac.created_datetime ? `
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Timestamp</span>
                                <span class="mobile-detail-value"><small>${this.escapeHtml(ac.created_datetime)}</small></span>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    },
    
    /**
     * Render call info section
     * @param {Object} call - Call object
     * @param {string} callType - Call type
     * @param {string} priority - Priority
     * @param {string} status - Status
     * @returns {string} HTML string
     */
    renderCallInfoSection(call, callType, priority, status) {
        return `
            <div class="mobile-detail-section">
                <h6>Call Information</h6>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Call ID</span>
                    <span class="mobile-detail-value">${this.escapeHtml(String(call.id || 'N/A'))}</span>
                </div>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Call Number</span>
                    <span class="mobile-detail-value">#${this.escapeHtml(String(call.call_number || call.call_id))}</span>
                </div>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Type</span>
                    <span class="mobile-detail-value">${this.escapeHtml(callType)}</span>
                </div>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Nature</span>
                    <span class="mobile-detail-value">${this.escapeHtml(call.nature_of_call || 'N/A')}</span>
                </div>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Status</span>
                    <span class="mobile-detail-value">
                        <span class="badge ${call.closed_flag ? 'bg-success' : 'bg-warning text-dark'}">${this.escapeHtml(status)}</span>
                    </span>
                </div>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Priority</span>
                    <span class="mobile-detail-value">
                        <span class="badge bg-danger">${this.escapeHtml(priority)}</span>
                    </span>
                </div>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Source</span>
                    <span class="mobile-detail-value">${this.escapeHtml(call.call_source || 'Unknown')}</span>
                </div>
                ${call.received_time ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Received</span>
                    <span class="mobile-detail-value">${this.escapeHtml(call.received_time)}</span>
                </div>
                ` : ''}
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Created</span>
                    <span class="mobile-detail-value">${this.escapeHtml(call.create_datetime || 'Unknown')}</span>
                </div>
                ${call.close_datetime ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Closed</span>
                    <span class="mobile-detail-value">${this.escapeHtml(call.close_datetime)}</span>
                </div>
                ` : ''}
                ${call.created_by ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Created By</span>
                    <span class="mobile-detail-value">${this.escapeHtml(call.created_by)}</span>
                </div>
                ` : ''}
                ${call.alarm_level ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Alarm Level</span>
                    <span class="mobile-detail-value">${this.escapeHtml(call.alarm_level)}</span>
                </div>
                ` : ''}
                ${call.emd_code ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">EMD Code</span>
                    <span class="mobile-detail-value">${this.escapeHtml(call.emd_code)}</span>
                </div>
                ` : ''}
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Closed Flag</span>
                    <span class="mobile-detail-value">${call.closed_flag ? '<span class="badge bg-secondary">Yes</span>' : '<span class="badge bg-success">No</span>'}</span>
                </div>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Canceled Flag</span>
                    <span class="mobile-detail-value">${call.canceled_flag ? '<span class="badge bg-warning">Yes</span>' : '<span class="badge bg-success">No</span>'}</span>
                </div>
            </div>
        `;
    },
    
    /**
     * Render location section
     * @param {Object} location - Location object
     * @returns {string} HTML string
     */
    renderLocationSection(location) {
        if (!location) return '';
        
        return `
            <div class="mobile-detail-section">
                <h6>Location</h6>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Address</span>
                    <span class="mobile-detail-value">${this.escapeHtml(location.full_address || location.city || 'Unknown')}</span>
                </div>
                ${location.house_number ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">House #</span>
                    <span class="mobile-detail-value">${this.escapeHtml(location.house_number)}</span>
                </div>
                ` : ''}
                ${location.prefix_directional ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Direction</span>
                    <span class="mobile-detail-value">${this.escapeHtml(location.prefix_directional)}</span>
                </div>
                ` : ''}
                ${location.street_name ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Street</span>
                    <span class="mobile-detail-value">${this.escapeHtml(location.street_name)} ${this.escapeHtml(location.street_type || '')}</span>
                </div>
                ` : ''}
                ${location.city ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">City</span>
                    <span class="mobile-detail-value">${this.escapeHtml(location.city)}, ${this.escapeHtml(location.state || '')} ${this.escapeHtml(location.zip || '')}</span>
                </div>
                ` : ''}
                ${location.common_name ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Common Name</span>
                    <span class="mobile-detail-value">${this.escapeHtml(location.common_name)}</span>
                </div>
                ` : ''}
                ${location.nearest_cross_streets ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Cross Streets</span>
                    <span class="mobile-detail-value">${this.escapeHtml(location.nearest_cross_streets)}</span>
                </div>
                ` : ''}
                ${location.coordinates ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Coordinates</span>
                    <span class="mobile-detail-value">${this.escapeHtml(String(location.coordinates.lat))}, ${this.escapeHtml(String(location.coordinates.lng))}</span>
                </div>
                ` : ''}
                ${location.police_beat ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Police Beat</span>
                    <span class="mobile-detail-value">${this.escapeHtml(location.police_beat)}</span>
                </div>
                ` : ''}
                ${location.ems_district ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">EMS District</span>
                    <span class="mobile-detail-value">${this.escapeHtml(location.ems_district)}</span>
                </div>
                ` : ''}
                ${location.fire_quadrant ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Fire Quadrant</span>
                    <span class="mobile-detail-value">${this.escapeHtml(location.fire_quadrant)}</span>
                </div>
                ` : ''}
            </div>
        `;
    },
    
    /**
     * Render caller section
     * @param {Object} caller - Caller object
     * @returns {string} HTML string
     */
    renderCallerSection(caller) {
        if (!caller?.name && !caller?.phone) return '';
        
        return `
            <div class="mobile-detail-section">
                <h6>Caller Information</h6>
                ${caller.name ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Name</span>
                    <span class="mobile-detail-value">${this.escapeHtml(caller.name)}</span>
                </div>
                ` : ''}
                ${caller.phone ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Phone</span>
                    <span class="mobile-detail-value">${this.escapeHtml(caller.phone)}</span>
                </div>
                ` : ''}
            </div>
        `;
    },
    
    /**
     * Render incidents section (deduplicated by jurisdiction like desktop)
     * @param {Array} incidents - Array of incident objects
     * @returns {string} HTML string
     */
    renderIncidentsSection(incidents) {
        if (!incidents || incidents.length === 0) return '';
        
        // Deduplicate by jurisdiction - only show one per jurisdiction (like desktop)
        const uniqueIncidents = [];
        const seenJurisdictions = new Set();
        
        for (const inc of incidents) {
            if (!seenJurisdictions.has(inc.jurisdiction)) {
                seenJurisdictions.add(inc.jurisdiction);
                uniqueIncidents.push(inc);
            }
        }
        
        return `
            <div class="mobile-detail-section">
                <h6>Incidents (${uniqueIncidents.length})</h6>
                ${uniqueIncidents.map(incident => `
                    <div class="card mb-2">
                        <div class="card-body p-2">
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Number</span>
                                <span class="mobile-detail-value">${this.escapeHtml(incident.incident_number || 'Unknown')}</span>
                            </div>
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Agency</span>
                                <span class="mobile-detail-value">${this.escapeHtml(incident.agency_type || 'N/A')}</span>
                            </div>
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Type</span>
                                <span class="mobile-detail-value">${this.escapeHtml(incident.incident_type || 'N/A')}</span>
                            </div>
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Jurisdiction</span>
                                <span class="mobile-detail-value">${this.escapeHtml(incident.jurisdiction || '')}</span>
                            </div>
                            ${incident.create_datetime ? `
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Created</span>
                                <span class="mobile-detail-value"><small>${this.escapeHtml(incident.create_datetime)}</small></span>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    },
    
    /**
     * Render units section
     * @param {Array} units - Array of unit objects
     * @param {number} totalUnits - Total units count from call.counts
     * @returns {string} HTML string
     */
    renderUnitsSection(units, totalUnits) {
        if (units && units.length > 0) {
            return `
                <div class="mobile-detail-section">
                    <h6>Assigned Units (${units.length})</h6>
                    ${units.map(u => {
                        const status = u.timestamps?.clear ? 'Clear' : 
                                      u.timestamps?.arrive ? 'On Scene' :
                                      u.timestamps?.enroute ? 'Enroute' :
                                      u.timestamps?.dispatch ? 'Dispatched' :
                                      u.timestamps?.assigned ? 'Assigned' : 'Unknown';
                        return `
                        <div class="card mb-2">
                            <div class="card-body p-2">
                                <div class="mobile-detail-row">
                                    <span class="mobile-detail-label">Unit</span>
                                    <span class="mobile-detail-value"><strong>${this.escapeHtml(u.unit_number || u.unit_id || 'N/A')}</strong></span>
                                </div>
                                <div class="mobile-detail-row">
                                    <span class="mobile-detail-label">Type</span>
                                    <span class="mobile-detail-value">${this.escapeHtml(u.unit_type || 'N/A')}</span>
                                </div>
                                <div class="mobile-detail-row">
                                    <span class="mobile-detail-label">Status</span>
                                    <span class="mobile-detail-value"><span class="badge bg-info">${this.escapeHtml(status)}</span></span>
                                </div>
                                ${u.timestamps?.assigned || u.assigned_datetime ? `
                                <div class="mobile-detail-row">
                                    <span class="mobile-detail-label">Assigned</span>
                                    <span class="mobile-detail-value"><small>${this.escapeHtml(u.timestamps?.assigned || u.assigned_datetime)}</small></span>
                                </div>
                                ` : ''}
                                ${u.timestamps?.enroute ? `
                                <div class="mobile-detail-row">
                                    <span class="mobile-detail-label">Enroute</span>
                                    <span class="mobile-detail-value"><small>${this.escapeHtml(u.timestamps.enroute)}</small></span>
                                </div>
                                ` : ''}
                                ${u.timestamps?.arrive ? `
                                <div class="mobile-detail-row">
                                    <span class="mobile-detail-label">Arrived</span>
                                    <span class="mobile-detail-value"><small>${this.escapeHtml(u.timestamps.arrive)}</small></span>
                                </div>
                                ` : ''}
                                ${u.is_primary ? `
                                <div class="mobile-detail-row">
                                    <span class="mobile-detail-label">Primary</span>
                                    <span class="mobile-detail-value"><span class="badge bg-primary">Yes</span></span>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                        `;
                    }).join('')}
                </div>
            `;
        } else if (totalUnits) {
            return `
                <div class="mobile-detail-section">
                    <h6>Units</h6>
                    <p class="text-muted">Total Units: ${parseInt(totalUnits, 10)}</p>
                </div>
            `;
        }
        return '';
    },
    
    /**
     * Render persons section (deduplicated by name + role like desktop)
     * @param {Array} persons - Array of person objects
     * @returns {string} HTML string
     */
    renderPersonsSection(persons) {
        if (!persons || persons.length === 0) return '';
        
        // Deduplicate by name + role combination (like desktop)
        const uniquePersons = [];
        const seenPersons = new Set();
        
        for (const person of persons) {
            const fullName = [person.first_name, person.middle_name, person.last_name, person.name_suffix]
                .filter(Boolean).join(' ');
            const key = `${fullName}-${person.role}`;
            
            if (!seenPersons.has(key)) {
                seenPersons.add(key);
                uniquePersons.push(person);
            }
        }
        
        return `
            <div class="mobile-detail-section">
                <h6>Persons Involved (${uniquePersons.length})</h6>
                ${uniquePersons.map(p => {
                    const fullName = [p.first_name, p.middle_name, p.last_name, p.name_suffix]
                        .filter(Boolean).join(' ');
                    return `
                    <div class="card mb-2">
                        <div class="card-body p-2">
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Name</span>
                                <span class="mobile-detail-value"><strong>${this.escapeHtml(fullName || 'N/A')}</strong></span>
                            </div>
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Role</span>
                                <span class="mobile-detail-value"><span class="badge bg-info">${this.escapeHtml(p.role || 'N/A')}</span></span>
                            </div>
                            ${p.contact_phone ? `
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Phone</span>
                                <span class="mobile-detail-value">${this.escapeHtml(p.contact_phone)}</span>
                            </div>
                            ` : ''}
                            ${p.date_of_birth ? `
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">DOB</span>
                                <span class="mobile-detail-value">${this.escapeHtml(p.date_of_birth)}</span>
                            </div>
                            ` : ''}
                            ${p.sex ? `
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Sex</span>
                                <span class="mobile-detail-value">${this.escapeHtml(p.sex)}</span>
                            </div>
                            ` : ''}
                            ${p.race ? `
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Race</span>
                                <span class="mobile-detail-value">${this.escapeHtml(p.race)}</span>
                            </div>
                            ` : ''}
                            ${p.primary_caller_flag ? `
                            <div class="mobile-detail-row">
                                <span class="mobile-detail-label">Primary Caller</span>
                                <span class="mobile-detail-value"><span class="badge bg-primary">Yes</span></span>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                    `;
                }).join('')}
            </div>
        `;
    },
    
    /**
     * Render narratives section
     * @param {Array} narratives - Array of narrative objects
     * @param {number} totalNarratives - Total narratives count from call.counts
     * @returns {string} HTML string
     */
    renderNarrativesSection(narratives, totalNarratives) {
        if (narratives && narratives.length > 0) {
            return `
                <div class="mobile-detail-section">
                    <h6>Narratives (${narratives.length})</h6>
                    ${narratives.map(n => `
                        <div class="card mb-2">
                            <div class="card-body p-2">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <small class="text-muted">${this.escapeHtml(n.create_datetime || 'N/A')}</small>
                                    ${n.narrative_type ? `<span class="badge bg-info">${this.escapeHtml(n.narrative_type)}</span>` : ''}
                                </div>
                                ${n.create_user ? `<small class="text-muted">By: ${this.escapeHtml(n.create_user)}</small><br>` : ''}
                                <p class="mb-0 mt-2">${this.escapeHtml(n.text || 'No text')}</p>
                            </div>
                        </div>
                    `).join('')}
                </div>
            `;
        } else if (totalNarratives) {
            return `
                <div class="mobile-detail-section">
                    <h6>Narratives</h6>
                    <p class="text-muted">Total Narratives: ${parseInt(totalNarratives, 10)}</p>
                </div>
            `;
        }
        return '';
    },
    
    /**
     * Open filters modal
     */
    openFiltersModal() {
        const modalEl = document.getElementById('mobile-filters-modal');
        if (!modalEl) return;
        
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
        
        // Populate current filter values
        this.populateFiltersModal();
    },
    
    /**
     * Populate filters modal with current values
     */
    populateFiltersModal() {
        // Set quick select
        const quickSelectBtns = document.querySelectorAll('.mobile-quick-select .btn');
        quickSelectBtns.forEach(btn => {
            if (btn.dataset.period === this.filters.quick_select) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
        
        // Set other filters
        ['jurisdiction', 'agency', 'call_type', 'status', 'priority'].forEach(key => {
            const input = document.getElementById(`mobile-filter-${key}`);
            if (input) {
                input.value = this.filters[key] || '';
            }
        });
    },
    
    /**
     * Apply filters from modal
     */
    applyFilters() {
        // Get quick select
        const activeQuickSelect = document.querySelector('.mobile-quick-select .btn.active');
        this.filters.quick_select = activeQuickSelect ? activeQuickSelect.dataset.period : '7days';
        
        // Get other filters
        ['jurisdiction', 'agency', 'call_type', 'status', 'priority'].forEach(key => {
            const input = document.getElementById(`mobile-filter-${key}`);
            if (input) {
                this.filters[key] = input.value;
            }
        });
        
        // Save filters
        Dashboard.filters.save(this.filters);
        
        // Reset page and reload
        this.currentPage = 1;
        this.refreshData();
        
        // Close modal
        const modalEl = document.getElementById('mobile-filters-modal');
        if (modalEl) {
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
        }
        
        Dashboard.showToast('Filters applied', 'success');
    },
    
    /**
     * Reset filters to defaults
     */
    resetFilters() {
        this.filters = this.getDefaultFilters();
        Dashboard.filters.save(this.filters);
        this.currentPage = 1;
        this.populateFiltersModal();
        this.refreshData();
        Dashboard.showToast('Filters reset', 'info');
    },
    
    /**
     * Open analytics modal
     */
    openAnalyticsModal() {
        const modalEl = document.getElementById('mobile-analytics-modal');
        if (!modalEl) return;
        
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
        
        // Load analytics if needed
        if (typeof MobileAnalytics !== 'undefined' && MobileAnalytics.loadCharts) {
            MobileAnalytics.loadCharts();
        }
    },
    
    /**
     * Filter by status
     * @param {string} status - Status to filter by ('active', 'closed', or 'all')
     */
    filterByStatus(status) {
        console.log('[Mobile] Filtering by status:', status);
        
        // Update filters
        if (status === 'all') {
            delete this.filters.status;
        } else {
            this.filters.status = status;
        }
        
        // Save filters
        Dashboard.filters.save(this.filters);
        
        // Refresh data
        this.refreshData();
    },
    
    /**
     * Refresh all data
     */
    refreshData() {
        console.log('[Mobile] Refreshing data');
        this.loadCallsList();
        this.loadStats();
        this.updateLiveIndicator();
    },
    
    /**
     * Start auto-refresh interval
     */
    startAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
        }
        
        this.refreshInterval = setInterval(() => {
            this.refreshData();
        }, MOBILE_CONFIG.REFRESH_INTERVAL_MS);
    },
    
    /**
     * Initialize pull to refresh
     */
    initPullToRefresh() {
        let startY = 0;
        let currentY = 0;
        let isPulling = false;
        
        const content = document.querySelector('.mobile-content');
        const indicator = document.getElementById('mobile-refresh-indicator');
        
        if (!content || !indicator) return;
        
        content.addEventListener('touchstart', (e) => {
            if (content.scrollTop === 0) {
                startY = e.touches[0].pageY;
                isPulling = true;
            }
        }, { passive: true });
        
        content.addEventListener('touchmove', (e) => {
            if (!isPulling) return;
            
            currentY = e.touches[0].pageY;
            const diff = currentY - startY;
            
            if (diff > MOBILE_CONFIG.PULL_TO_REFRESH_THRESHOLD) {
                indicator.classList.add('show');
            }
        }, { passive: true });
        
        content.addEventListener('touchend', () => {
            if (isPulling && currentY - startY > MOBILE_CONFIG.PULL_TO_REFRESH_THRESHOLD) {
                this.refreshData();
                indicator.classList.add('show');
                setTimeout(() => {
                    indicator.classList.remove('show');
                }, 2000);
            }
            isPulling = false;
            startY = 0;
            currentY = 0;
        });
    },
    
    /**
     * Initialize live updates indicator
     */
    initLiveUpdates() {
        this.updateLiveIndicator();
    },
    
    /**
     * Update live indicator with pulse animation
     */
    updateLiveIndicator() {
        const indicator = document.querySelector('.mobile-live-indicator');
        if (indicator) {
            indicator.classList.add('pulse');
            setTimeout(() => {
                indicator.classList.remove('pulse');
            }, 2000);
        }
    }
};

/**
 * Mobile Analytics Controller
 */
const MobileAnalytics = {
    charts: {},
    
    /**
     * Load all charts
     */
    async loadCharts() {
        try {
            const params = MobileDashboard.buildFilterParams();
            const queryString = Dashboard.buildQueryString(params);
            const stats = await Dashboard.apiRequest(`/stats${queryString}`);
            
            this.createCallVolumeChart(stats);
            this.createCallTypesChart(stats);
            this.createPriorityChart(stats);
            this.createStatusChart(stats);
        } catch (error) {
            console.error('[Mobile Analytics] Error loading charts:', error);
        }
    },
    
    /**
     * Destroy chart if exists
     * @param {string} chartKey - Chart key
     */
    destroyChart(chartKey) {
        if (this.charts[chartKey]) {
            this.charts[chartKey].destroy();
            delete this.charts[chartKey];
        }
    },
    
    /**
     * Create call volume chart
     * @param {Object} stats - Stats object
     */
    createCallVolumeChart(stats) {
        const canvas = document.getElementById('mobile-chart-call-volume');
        if (!canvas) return;
        
        this.destroyChart('call-volume');
        
        const jurisdictions = stats.calls_by_jurisdiction?.slice(0, 10) || [];
        
        this.charts['call-volume'] = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: jurisdictions.map(j => j.jurisdiction),
                datasets: [{
                    label: 'Calls by Jurisdiction',
                    data: jurisdictions.map(j => j.count),
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.7)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } }
            }
        });
    },
    
    /**
     * Create call types chart
     * @param {Object} stats - Stats object
     */
    createCallTypesChart(stats) {
        const canvas = document.getElementById('mobile-chart-call-types');
        if (!canvas) return;
        
        this.destroyChart('call-types');
        
        const types = stats.top_call_types || [];
        this.charts['call-types'] = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: types.map(t => t.call_type),
                datasets: [{
                    label: 'Count',
                    data: types.map(t => t.count),
                    backgroundColor: '#198754'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                indexAxis: 'y'
            }
        });
    },
    
    /**
     * Create priority chart
     * @param {Object} stats - Stats object
     */
    createPriorityChart(stats) {
        const canvas = document.getElementById('mobile-chart-priority');
        if (!canvas) return;
        
        this.destroyChart('priority');
        
        const jurisdictions = stats.calls_by_jurisdiction?.slice(0, 5) || [];
        this.charts['priority'] = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: jurisdictions.map(j => j.jurisdiction),
                datasets: [{
                    data: jurisdictions.map(j => j.count),
                    backgroundColor: ['#dc3545', '#ffc107', '#198754', '#0d6efd', '#6610f2']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    },
    
    /**
     * Create status chart
     * @param {Object} stats - Stats object
     */
    createStatusChart(stats) {
        const canvas = document.getElementById('mobile-chart-status');
        if (!canvas) return;
        
        this.destroyChart('status');
        
        const statusData = stats.calls_by_status || {};
        this.charts['status'] = new Chart(canvas, {
            type: 'pie',
            data: {
                labels: ['Open', 'Closed', 'Canceled'],
                datasets: [{
                    data: [statusData.open || 0, statusData.closed || 0, statusData.canceled || 0],
                    backgroundColor: ['#ffc107', '#198754', '#6c757d']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }
};

/**
 * Mobile Maps Controller
 */
const MobileMaps = {
    map: null,
    markers: [],
    
    /**
     * Initialize map
     */
    async initMap() {
        if (!this.map) {
            const mapDiv = document.getElementById('calls-map');
            if (!mapDiv) return;
            
            this.map = L.map('calls-map', {
                maxBounds: MOBILE_CONFIG.MAP_BOUNDS,
                maxBoundsViscosity: 1.0,
                minZoom: MOBILE_CONFIG.MAP_MIN_ZOOM,
                maxZoom: MOBILE_CONFIG.MAP_MAX_ZOOM,
                zoomSnap: 0.5,
                zoomDelta: 0.5
            }).setView(MOBILE_CONFIG.MAP_CENTER, MOBILE_CONFIG.MAP_MIN_ZOOM);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxZoom: MOBILE_CONFIG.MAP_MAX_ZOOM,
                minZoom: MOBILE_CONFIG.MAP_MIN_ZOOM
            }).addTo(this.map);
        }
        
        await this.loadCallMarkers();
    },
    
    /**
     * Load call markers onto map
     */
    async loadCallMarkers() {
        if (!this.map) return;
        
        try {
            const params = {
                ...MobileDashboard.buildFilterParams(),
                per_page: MOBILE_CONFIG.MAP_MARKERS_LIMIT
            };
            const queryString = Dashboard.buildQueryString(params);
            const data = await Dashboard.apiRequest(`/calls${queryString}`);
            
            // Clear existing markers
            this.markers.forEach(marker => this.map.removeLayer(marker));
            this.markers = [];
            
            // Add new markers
            const calls = data.items || [];
            calls.forEach(call => {
                const lat = parseFloat(call.location?.coordinates?.lat);
                const lng = parseFloat(call.location?.coordinates?.lng);
                
                if (isNaN(lat) || isNaN(lng)) return;
                
                const callType = Array.isArray(call.call_types) ? call.call_types[0] : 'Unknown';
                const statusBadge = call.closed_flag ? 'Closed' : 'Open';
                const locationText = call.location?.address || 'No location';
                const callNumber = call.call_number || call.call_id;
                const callId = parseInt(call.id, 10);
                
                if (isNaN(callId)) return;
                
                const popupContent = `
                    <div style="min-width: 200px;">
                        <strong style="font-size: 1rem;">${MobileDashboard.escapeHtml(callType)}</strong><br>
                        <small style="color: #6c757d;">#${MobileDashboard.escapeHtml(String(callNumber))}</small><br>
                        <div style="margin: 8px 0;">
                            <i class="bi bi-geo-alt"></i> ${MobileDashboard.escapeHtml(locationText)}
                        </div>
                        <div style="margin-bottom: 8px;">
                            <span class="badge ${call.closed_flag ? 'bg-success' : 'bg-warning text-dark'}">${statusBadge}</span>
                        </div>
                        <button 
                            class="btn btn-primary btn-sm w-100" 
                            onclick="MobileDashboard.openCallDetails(${callId}); return false;">
                            <i class="bi bi-info-circle"></i> View Details
                        </button>
                    </div>
                `;
                
                const marker = L.marker([lat, lng])
                    .bindPopup(popupContent);
                marker.addTo(this.map);
                this.markers.push(marker);
            });
            
            console.log(`[Mobile Maps] Loaded ${this.markers.length} markers`);
        } catch (error) {
            console.error('[Mobile Maps] Error loading markers:', error);
        }
    }
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if (document.body.classList.contains('mobile-view')) {
        MobileDashboard.init();
    }
});
