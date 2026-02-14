/**
 * NWS CAD Dashboard - Mobile JavaScript
 * Mobile-specific functionality and optimizations
 */

const MobileDashboard = {
    config: window.APP_CONFIG || {},
    currentPage: 1,
    perPage: 15,
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
        
        // Load initial data
        this.loadCallsList();
        this.loadStats();
        
        // Set up auto-refresh
        this.startAutoRefresh();
        
        console.log('[Mobile] Mobile dashboard initialized');
    },
    
    /**
     * Get default filters
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
                this.handleNavAction(action);
            });
        });
    },
    
    /**
     * Handle navigation actions
     */
    handleNavAction(action) {
        console.log('[Mobile] Nav action:', action);
        
        // Update active state
        document.querySelectorAll('.mobile-nav-item').forEach(item => {
            item.classList.remove('active');
        });
        event.currentTarget?.classList.add('active');
        
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
        document.getElementById('mobile-calls-view').style.display = 'block';
        document.getElementById('mobile-map-view').style.display = 'none';
        this.loadCallsList();
    },
    
    /**
     * Show map view
     */
    showMap() {
        document.getElementById('mobile-calls-view').style.display = 'none';
        document.getElementById('mobile-map-view').style.display = 'block';
        
        // Initialize or refresh map
        if (typeof MobileMaps !== 'undefined' && MobileMaps.initMap) {
            MobileMaps.initMap();
        }
    },
    
    /**
     * Load calls list
     */
    async loadCallsList() {
        const container = document.getElementById('mobile-calls-list');
        
        // Show loading
        container.innerHTML = `
            <div class="mobile-loading">
                <div class="spinner-border text-primary"></div>
                <p class="text-muted mt-2">Loading calls...</p>
            </div>
        `;
        
        try {
            // Build query params
            const params = {
                page: this.currentPage,
                per_page: this.perPage,
                ...this.buildFilterParams()
            };
            
            const queryString = Dashboard.buildQueryString(params);
            const data = await Dashboard.apiRequest(`/calls${queryString}`);
            
            this.totalCalls = data.total || 0;
            this.renderCallsList(data.calls || []);
            
        } catch (error) {
            console.error('[Mobile] Error loading calls:', error);
            container.innerHTML = `
                <div class="mobile-empty-state">
                    <i class="bi bi-exclamation-triangle"></i>
                    <h4>Error Loading Calls</h4>
                    <p>${error.message}</p>
                    <button class="btn btn-primary mt-3" onclick="MobileDashboard.loadCallsList()">
                        Try Again
                    </button>
                </div>
            `;
        }
    },
    
    /**
     * Build filter parameters
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
        
        // Add other filters
        ['jurisdiction', 'agency', 'call_type', 'status', 'priority'].forEach(key => {
            if (this.filters[key]) {
                params[key] = this.filters[key];
            }
        });
        
        return params;
    },
    
    /**
     * Get date range from quick select
     */
    getQuickSelectDates(quickSelect) {
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        
        switch(quickSelect) {
            case 'today':
                return {
                    from: today.toISOString().split('T')[0] + ' 00:00:00',
                    to: today.toISOString().split('T')[0] + ' 23:59:59'
                };
            case 'yesterday':
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                return {
                    from: yesterday.toISOString().split('T')[0] + ' 00:00:00',
                    to: yesterday.toISOString().split('T')[0] + ' 23:59:59'
                };
            case '7days':
                const sevenDaysAgo = new Date(today);
                sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);
                return {
                    from: sevenDaysAgo.toISOString().split('T')[0] + ' 00:00:00',
                    to: today.toISOString().split('T')[0] + ' 23:59:59'
                };
            case '30days':
                const thirtyDaysAgo = new Date(today);
                thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
                return {
                    from: thirtyDaysAgo.toISOString().split('T')[0] + ' 00:00:00',
                    to: today.toISOString().split('T')[0] + ' 23:59:59'
                };
            default:
                return null;
        }
    },
    
    /**
     * Render calls list
     */
    renderCallsList(calls) {
        const container = document.getElementById('mobile-calls-list');
        
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
     */
    renderCallItem(call) {
        const priorityClass = call.priority ? `priority-${call.priority}` : 'bg-secondary';
        const statusClass = call.status ? `status-${call.status.toLowerCase()}` : 'bg-secondary';
        
        return `
            <div class="mobile-call-item" onclick="MobileDashboard.openCallDetails('${call.call_id}')">
                <div class="mobile-call-header">
                    <span class="mobile-call-id">#${call.call_id}</span>
                    <span class="mobile-call-time">${this.formatTime(call.received_dt)}</span>
                </div>
                <div class="mobile-call-type">${this.escapeHtml(call.call_type || 'Unknown')}</div>
                <div class="mobile-call-location">
                    <i class="bi bi-geo-alt"></i>
                    <span>${this.escapeHtml(call.location || 'No location')}</span>
                </div>
                <div class="mobile-call-footer">
                    ${call.priority ? `<span class="badge ${priorityClass}">P${call.priority}</span>` : ''}
                    ${call.status ? `<span class="badge ${statusClass}">${call.status}</span>` : ''}
                    ${call.unit_count ? `<span class="badge bg-info">${call.unit_count} Units</span>` : ''}
                </div>
            </div>
        `;
    },
    
    /**
     * Format time for display
     */
    formatTime(datetime) {
        if (!datetime) return 'Unknown';
        
        const date = new Date(datetime);
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
     * Escape HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
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
     * Load stats
     */
    async loadStats() {
        try {
            const params = this.buildFilterParams();
            const queryString = Dashboard.buildQueryString(params);
            const stats = await Dashboard.apiRequest(`/stats${queryString}`);
            
            document.getElementById('stat-total').textContent = stats.total_calls || 0;
            document.getElementById('stat-active').textContent = stats.active_calls || 0;
            document.getElementById('stat-closed').textContent = stats.closed_calls || 0;
            
        } catch (error) {
            console.error('[Mobile] Error loading stats:', error);
        }
    },
    
    /**
     * Open call details modal
     */
    async openCallDetails(callId) {
        console.log('[Mobile] Opening call details:', callId);
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('mobile-call-detail-modal'));
        modal.show();
        
        // Load call details
        const modalBody = document.querySelector('#mobile-call-detail-modal .modal-body');
        modalBody.innerHTML = `
            <div class="mobile-loading">
                <div class="spinner-border text-primary"></div>
                <p class="text-muted mt-2">Loading details...</p>
            </div>
        `;
        
        try {
            const call = await Dashboard.apiRequest(`/calls/${callId}`);
            this.renderCallDetails(call);
        } catch (error) {
            console.error('[Mobile] Error loading call details:', error);
            modalBody.innerHTML = `
                <div class="alert alert-danger">
                    Error loading call details: ${error.message}
                </div>
            `;
        }
    },
    
    /**
     * Render call details
     */
    renderCallDetails(call) {
        const modalBody = document.querySelector('#mobile-call-detail-modal .modal-body');
        
        modalBody.innerHTML = `
            <div class="mobile-detail-section">
                <h6>Call Information</h6>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Call ID</span>
                    <span class="mobile-detail-value">${call.call_id}</span>
                </div>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Type</span>
                    <span class="mobile-detail-value">${call.call_type || 'Unknown'}</span>
                </div>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Status</span>
                    <span class="mobile-detail-value">
                        <span class="badge ${call.status ? 'status-' + call.status.toLowerCase() : ''}">${call.status || 'Unknown'}</span>
                    </span>
                </div>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Priority</span>
                    <span class="mobile-detail-value">
                        <span class="badge ${call.priority ? 'priority-' + call.priority : ''}">${call.priority || 'N/A'}</span>
                    </span>
                </div>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Received</span>
                    <span class="mobile-detail-value">${call.received_dt || 'Unknown'}</span>
                </div>
            </div>
            
            ${call.location ? `
            <div class="mobile-detail-section">
                <h6>Location</h6>
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Address</span>
                    <span class="mobile-detail-value">${call.location}</span>
                </div>
                ${call.cross_street ? `
                <div class="mobile-detail-row">
                    <span class="mobile-detail-label">Cross Street</span>
                    <span class="mobile-detail-value">${call.cross_street}</span>
                </div>
                ` : ''}
            </div>
            ` : ''}
            
            ${call.units && call.units.length > 0 ? `
            <div class="mobile-detail-section">
                <h6>Assigned Units (${call.units.length})</h6>
                ${call.units.map(unit => `
                    <div class="mobile-detail-row">
                        <span class="mobile-detail-label">${unit.unit_number}</span>
                        <span class="mobile-detail-value">
                            <span class="badge bg-info">${unit.status || 'Unknown'}</span>
                        </span>
                    </div>
                `).join('')}
            </div>
            ` : ''}
        `;
    },
    
    /**
     * Open filters modal
     */
    openFiltersModal() {
        const modal = new bootstrap.Modal(document.getElementById('mobile-filters-modal'));
        modal.show();
        
        // Populate current filter values
        this.populateFiltersModal();
    },
    
    /**
     * Populate filters modal
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
     * Apply filters
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
        const modal = bootstrap.Modal.getInstance(document.getElementById('mobile-filters-modal'));
        if (modal) modal.hide();
        
        Dashboard.showToast('Filters applied', 'success');
    },
    
    /**
     * Reset filters
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
        const modal = new bootstrap.Modal(document.getElementById('mobile-analytics-modal'));
        modal.show();
        
        // Load analytics if needed
        if (typeof MobileAnalytics !== 'undefined' && MobileAnalytics.loadCharts) {
            MobileAnalytics.loadCharts();
        }
    },
    
    /**
     * Refresh data
     */
    refreshData() {
        console.log('[Mobile] Refreshing data');
        this.loadCallsList();
        this.loadStats();
        this.updateLiveIndicator();
    },
    
    /**
     * Start auto-refresh
     */
    startAutoRefresh() {
        this.refreshInterval = setInterval(() => {
            this.refreshData();
        }, 30000); // 30 seconds
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
        });
        
        content.addEventListener('touchmove', (e) => {
            if (!isPulling) return;
            
            currentY = e.touches[0].pageY;
            const diff = currentY - startY;
            
            if (diff > 80) {
                indicator.classList.add('show');
            }
        });
        
        content.addEventListener('touchend', () => {
            if (isPulling && currentY - startY > 80) {
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
     * Initialize live updates
     */
    initLiveUpdates() {
        this.updateLiveIndicator();
    },
    
    /**
     * Update live indicator
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

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if (document.body.classList.contains('mobile-view')) {
        MobileDashboard.init();
    }
});
