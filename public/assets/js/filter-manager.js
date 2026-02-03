/**
 * NWS CAD FilterManager - Centralized Filter Management
 * Handles all filtering logic, state management, URL params, and UI updates
 */

class FilterManager {
    constructor(options = {}) {
        this.formId = options.formId || 'dashboard-filter-form';
        this.storageKey = 'nws_cad_filters';
        this.onFilterChange = options.onFilterChange || null;
        this.searchDebounceMs = options.searchDebounceMs || 300;
        this.searchDebounceTimer = null;
        this.currentFilters = {};
        
        console.log('[FilterManager] Initialized with formId:', this.formId);
    }
    
    /**
     * Initialize the filter manager
     */
    async init() {
        // Load filters from URL first (takes precedence)
        const urlFilters = this.loadFromURL();
        
        // Fall back to sessionStorage if no URL params
        const savedFilters = urlFilters || this.loadFromStorage();
        
        if (savedFilters) {
            this.currentFilters = savedFilters;
            
            // Recalculate dates if using a quick period to ensure fresh dates
            if (this.currentFilters.quick_period && this.currentFilters.quick_period !== '') {
                const dates = this.calculateDateRange(this.currentFilters.quick_period);
                this.currentFilters.date_from = dates.from;
                this.currentFilters.date_to = dates.to;
                console.log('[FilterManager] Recalculated dates for quick period:', this.currentFilters.quick_period, dates);
            }
            
            this.applyToForm(this.currentFilters);
        } else {
            // No saved filters - apply default: Today
            this.currentFilters = {
                quick_period: 'today'
            };
            // Calculate today's dates
            const dates = this.calculateDateRange('today');
            this.currentFilters.date_from = dates.from;
            this.currentFilters.date_to = dates.to;
            console.log('[FilterManager] No saved filters, using default: Today with dates:', dates);
        }
        
        // Setup event handlers
        this.setupFormHandlers();
        this.setupQuickPeriodHandler();
        this.setupSearchHandler();
        
        // Load dynamic options
        await this.loadJurisdictions();
        await this.loadAgencies();
        
        console.log('[FilterManager] Initialized with filters:', this.currentFilters);
        
        return this.currentFilters;
    }
    
    /**
     * Get current filters
     */
    getFilters() {
        return { ...this.currentFilters };
    }
    
    /**
     * Set filters programmatically
     */
    setFilters(filters, apply = true) {
        this.currentFilters = { ...filters };
        
        if (apply) {
            this.applyToForm(filters);
            this.save();
            this.triggerChange();
        }
    }
    
    /**
     * Clear all filters
     */
    clear() {
        this.currentFilters = {};
        
        const form = document.getElementById(this.formId);
        if (form) {
            form.reset();
        }
        
        this.save();
        this.updateURL();
        this.triggerChange();
        
        console.log('[FilterManager] Filters cleared');
    }
    
    /**
     * Save filters to sessionStorage
     */
    save() {
        sessionStorage.setItem(this.storageKey, JSON.stringify(this.currentFilters));
        console.log('[FilterManager] Saved to storage:', this.currentFilters);
    }
    
    /**
     * Load filters from sessionStorage
     */
    loadFromStorage() {
        const saved = sessionStorage.getItem(this.storageKey);
        if (saved) {
            try {
                return JSON.parse(saved);
            } catch (e) {
                console.error('[FilterManager] Error loading from storage:', e);
            }
        }
        return null;
    }
    
    /**
     * Load filters from URL parameters
     */
    loadFromURL() {
        const params = new URLSearchParams(window.location.search);
        const filters = {};
        
        // Known filter parameters
        const filterParams = [
            'quick_period', 'date_from', 'date_to', 'status', 
            'agency_type', 'jurisdiction', 'priority', 'search'
        ];
        
        filterParams.forEach(param => {
            const value = params.get(param);
            if (value) {
                filters[param] = value;
            }
        });
        
        if (Object.keys(filters).length > 0) {
            console.log('[FilterManager] Loaded from URL:', filters);
            return filters;
        }
        
        return null;
    }
    
    /**
     * Update URL with current filters (for sharing)
     */
    updateURL() {
        const params = new URLSearchParams();
        
        Object.entries(this.currentFilters).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                params.set(key, value);
            }
        });
        
        const newURL = params.toString() 
            ? `${window.location.pathname}?${params.toString()}`
            : window.location.pathname;
        
        window.history.replaceState({}, '', newURL);
    }
    
    /**
     * Apply filters to form
     */
    applyToForm(filters) {
        const form = document.getElementById(this.formId);
        if (!form) return;
        
        Object.entries(filters).forEach(([key, value]) => {
            const field = form.querySelector(`[name="${key}"]`);
            if (field) {
                field.value = value;
            }
        });
        
        // Trigger quick period change to show/hide date fields
        const quickPeriod = document.getElementById('dashboard-quick-period');
        if (quickPeriod) {
            quickPeriod.dispatchEvent(new Event('change'));
        }
    }
    
    /**
     * Get filters from form
     */
    getFromForm() {
        const form = document.getElementById(this.formId);
        if (!form) return {};
        
        const formData = new FormData(form);
        const filters = {};
        
        for (const [key, value] of formData.entries()) {
            if (value !== '' && value !== null && value !== undefined) {
                filters[key] = value;
            }
        }
        
        return filters;
    }
    
    /**
     * Translate UI filters to API parameters
     */
    translateForAPI(filters = null) {
        const sourceFilters = filters || this.currentFilters;
        const apiParams = {};
        
        Object.entries(sourceFilters).forEach(([key, value]) => {
            if (key === 'status') {
                // Translate status to closed_flag
                if (value === 'closed') {
                    apiParams.closed_flag = 'true';
                } else if (value === 'active') {
                    apiParams.closed_flag = 'false';
                }
            } else if (key !== 'quick_period') {
                // Skip quick_period (UI-only)
                apiParams[key] = value;
            }
        });
        
        return apiParams;
    }
    
    /**
     * Build query string from filters
     */
    toQueryString(filters = null) {
        const apiParams = this.translateForAPI(filters);
        
        const params = new URLSearchParams();
        Object.entries(apiParams).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                params.append(key, value);
            }
        });
        
        return params.toString() ? '?' + params.toString() : '';
    }
    
    /**
     * Setup form submission handler
     */
    setupFormHandlers() {
        const form = document.getElementById(this.formId);
        if (!form) return;
        
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            
            console.log('[FilterManager] Form submitted');
            
            this.currentFilters = this.getFromForm();
            console.log('[FilterManager] Current filters from form:', this.currentFilters);
            
            this.save();
            this.updateURL();
            
            console.log('[FilterManager] Triggering onFilterChange callback...');
            this.triggerChange();
            
            // Close modal if it exists
            const modal = bootstrap.Modal.getInstance(document.getElementById('filterModal'));
            if (modal) {
                console.log('[FilterManager] Closing filter modal');
                modal.hide();
            }
        });
        
        // Clear filters button
        const clearBtn = document.getElementById('clear-filters');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                this.clear();
            });
        }
    }
    
    /**
     * Setup quick period dropdown handler
     */
    setupQuickPeriodHandler() {
        const quickPeriod = document.getElementById('dashboard-quick-period');
        const dateFromField = document.getElementById('date-from-field');
        const dateToField = document.getElementById('date-to-field');
        const dateFromInput = document.getElementById('dashboard-date-from');
        const dateToInput = document.getElementById('dashboard-date-to');
        
        if (!quickPeriod) return;
        
        // Toggle date fields visibility
        const toggleDateFields = () => {
            const showCustom = quickPeriod.value === '';
            if (dateFromField) dateFromField.style.display = showCustom ? 'block' : 'none';
            if (dateToField) dateToField.style.display = showCustom ? 'block' : 'none';
        };
        
        // Initial state
        toggleDateFields();
        
        // Listen for changes
        quickPeriod.addEventListener('change', () => {
            toggleDateFields();
            
            const period = quickPeriod.value;
            if (period && period !== '') {
                const dates = this.calculateDateRange(period);
                if (dateFromInput && dates.from) dateFromInput.value = dates.from;
                if (dateToInput && dates.to) dateToInput.value = dates.to;
                
                // Update current filters with new dates BEFORE reloading dropdowns
                this.currentFilters.quick_period = period;
                this.currentFilters.date_from = dates.from;
                this.currentFilters.date_to = dates.to;
            } else if (period === '') {
                // Clear custom period - remove date filters
                this.currentFilters.quick_period = '';
                delete this.currentFilters.date_from;
                delete this.currentFilters.date_to;
            }
            
            // Auto-reload dropdowns when quick period changes (now with updated dates)
            console.log('[FilterManager] Quick period changed to:', period, 'Dates:', this.currentFilters.date_from, '-', this.currentFilters.date_to);
            this.loadJurisdictions();
            this.loadAgencies();
            
            // Trigger change to update dashboard/analytics
            this.save();
            this.updateURL();
            this.triggerChange();
        });
    }
    
    /**
     * Setup real-time search with debouncing
     */
    setupSearchHandler() {
        const searchInput = document.querySelector(`#${this.formId} [name="search"]`);
        if (!searchInput) return;
        
        searchInput.addEventListener('input', (e) => {
            clearTimeout(this.searchDebounceTimer);
            
            this.searchDebounceTimer = setTimeout(() => {
                const newSearch = e.target.value.trim();
                
                if (newSearch !== this.currentFilters.search) {
                    this.currentFilters.search = newSearch || undefined;
                    this.save();
                    this.updateURL();
                    this.triggerChange();
                    
                    console.log('[FilterManager] Real-time search:', newSearch);
                }
            }, this.searchDebounceMs);
        });
    }
    
    /**
     * Calculate date range from quick period
     */
    calculateDateRange(period) {
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0, 0);
        let from, to;
        
        switch(period) {
            case 'today':
                from = new Date(today);
                to = new Date(today);
                break;
            case 'yesterday':
                from = new Date(today);
                from.setDate(from.getDate() - 1);
                to = new Date(today); // Include today to show yesterday through now
                break;
            case '7days':
                from = new Date(today);
                from.setDate(from.getDate() - 7);
                to = new Date(today);
                break;
            case '30days':
                from = new Date(today);
                from.setDate(from.getDate() - 30);
                to = new Date(today);
                break;
            case 'thismonth':
                from = new Date(today.getFullYear(), today.getMonth(), 1);
                to = new Date(today);
                break;
            case 'lastmonth':
                from = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                to = new Date(today.getFullYear(), today.getMonth(), 1);
                to.setDate(to.getDate() - 1); // Last day of previous month
                break;
            default:
                return { from: null, to: null };
        }
        
        return {
            from: from.toISOString().split('T')[0],
            to: to.toISOString().split('T')[0]
        };
    }
    
    /**
     * Load jurisdictions from filtered results (descending sort)
     */
    async loadJurisdictions() {
        try {
            // Get current filters (excluding jurisdiction)
            const filters = { ...this.currentFilters };
            delete filters.jurisdiction;
            
            const queryString = this.toQueryString(filters);
            const stats = await Dashboard.apiRequest(`/stats${queryString}`);
            
            const select = document.getElementById('dashboard-jurisdiction');
            if (!select || !stats.calls_by_jurisdiction) return;
            
            // Save current value
            const currentValue = select.value;
            
            // Clear existing options except "All"
            while (select.options.length > 1) {
                select.remove(1);
            }
            
            // Sort descending by jurisdiction name
            const jurisdictions = stats.calls_by_jurisdiction
                .map(j => j.jurisdiction)
                .filter(j => j)
                .sort((a, b) => b.localeCompare(a));
            
            // Remove duplicates
            const uniqueJurisdictions = [...new Set(jurisdictions)];
            
            uniqueJurisdictions.forEach(jurisdiction => {
                const option = document.createElement('option');
                option.value = jurisdiction;
                option.textContent = jurisdiction;
                select.appendChild(option);
            });
            
            // Restore previous value if it still exists
            if (currentValue && uniqueJurisdictions.includes(currentValue)) {
                select.value = currentValue;
            }
            
            console.log('[FilterManager] Loaded', uniqueJurisdictions.length, 'jurisdictions');
        } catch (error) {
            console.error('[FilterManager] Error loading jurisdictions:', error);
        }
    }
    
    /**
     * Load agencies from filtered results (descending sort)
     */
    async loadAgencies() {
        try {
            const queryString = this.toQueryString();
            const stats = await Dashboard.apiRequest(`/stats${queryString}`);
            
            const select = document.getElementById('dashboard-agency-type');
            if (!select || !stats.calls_by_agency) return;
            
            const currentValue = select.value;
            
            // Clear existing options except "All"
            while (select.options.length > 1) {
                select.remove(1);
            }
            
            // Sort descending
            const agencies = stats.calls_by_agency
                .map(a => a.agency_type)
                .filter(a => a)
                .sort((a, b) => b.localeCompare(a));
            
            const uniqueAgencies = [...new Set(agencies)];
            
            uniqueAgencies.forEach(agency => {
                const option = document.createElement('option');
                option.value = agency;
                option.textContent = agency;
                select.appendChild(option);
            });
            
            if (currentValue && uniqueAgencies.includes(currentValue)) {
                select.value = currentValue;
            }
            
            console.log('[FilterManager] Loaded', uniqueAgencies.length, 'agencies');
        } catch (error) {
            console.error('[FilterManager] Error loading agencies:', error);
        }
    }
    
    /**
     * Trigger filter change callback
     */
    triggerChange() {
        console.log('[FilterManager] triggerChange() called');
        console.log('[FilterManager] onFilterChange type:', typeof this.onFilterChange);
        
        if (typeof this.onFilterChange === 'function') {
            console.log('[FilterManager] Calling onFilterChange with filters:', this.getFilters());
            this.onFilterChange(this.getFilters());
        } else {
            console.warn('[FilterManager] onFilterChange is not a function!');
        }
    }
    
    /**
     * Display active filters as badges
     */
    displayActiveFilters(containerId = 'active-filters') {
        const container = document.getElementById(containerId);
        if (!container) return;
        
        container.innerHTML = '';
        
        const filters = this.currentFilters;
        const filterLabels = {
            quick_period: 'Period',
            date_from: 'From',
            date_to: 'To',
            status: 'Status',
            agency_type: 'Agency',
            jurisdiction: 'Jurisdiction',
            priority: 'Priority',
            search: 'Search'
        };
        
        Object.entries(filters).forEach(([key, value]) => {
            if (value && key !== 'quick_period') {
                const badge = document.createElement('span');
                badge.className = 'badge bg-secondary me-2 mb-2';
                badge.innerHTML = `
                    ${filterLabels[key] || key}: ${value}
                    <button type="button" class="btn-close btn-close-white ms-1" 
                            style="font-size: 0.7rem;" 
                            data-filter-key="${key}"></button>
                `;
                
                badge.querySelector('.btn-close').addEventListener('click', () => {
                    this.removeFilter(key);
                });
                
                container.appendChild(badge);
            }
        });
        
        if (Object.keys(filters).length > 0) {
            const clearAll = document.createElement('button');
            clearAll.className = 'btn btn-sm btn-outline-secondary mb-2';
            clearAll.textContent = 'Clear All';
            clearAll.addEventListener('click', () => this.clear());
            container.appendChild(clearAll);
        }
    }
    
    /**
     * Remove a single filter
     */
    removeFilter(key) {
        delete this.currentFilters[key];
        
        const form = document.getElementById(this.formId);
        if (form) {
            const field = form.querySelector(`[name="${key}"]`);
            if (field) {
                field.value = '';
            }
        }
        
        this.save();
        this.updateURL();
        this.triggerChange();
        this.displayActiveFilters();
    }
}

// Make FilterManager globally available
window.FilterManager = FilterManager;
