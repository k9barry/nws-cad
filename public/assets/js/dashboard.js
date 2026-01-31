/**
 * NWS CAD Dashboard - Core JavaScript
 * Common utilities and API functions
 */

// Global Dashboard object
const Dashboard = {
    config: window.APP_CONFIG || {},
    charts: {},
    maps: {},
    refreshTimers: {},
    
    /**
     * Make API request
     */
    async apiRequest(endpoint, options = {}) {
        const url = `${this.config.apiBaseUrl}${endpoint}`;
        
        console.log('[Dashboard] API Request:', url);
        
        try {
            const response = await fetch(url, {
                method: options.method || 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    ...options.headers
                },
                body: options.body ? JSON.stringify(options.body) : undefined
            });
            
            console.log('[Dashboard] API Response status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            console.log('[Dashboard] API Response data:', data);
            
            if (data.success) {
                return data.data;
            } else {
                throw new Error(data.error || 'API request failed');
            }
        } catch (error) {
            console.error('API Request Error:', error);
            this.showError(`API Error: ${error.message}`);
            throw error;
        }
    },
    
    /**
     * Build query string from object
     */
    buildQueryString(params) {
        const filtered = Object.entries(params)
            .filter(([_, value]) => value !== '' && value !== null && value !== undefined)
            .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
            .join('&');
        return filtered ? `?${filtered}` : '';
    },
    
    /**
     * Show toast notification
     */
    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        toast.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 5000);
    },
    
    /**
     * Centralized Filter Management
     */
    filters: {
        /**
         * Save current filters to session storage
         */
        save(filters) {
            sessionStorage.setItem('nws_cad_filters', JSON.stringify(filters));
            console.log('[Dashboard] Filters saved:', filters);
        },
        
        /**
         * Load filters from session storage
         */
        load() {
            const saved = sessionStorage.getItem('nws_cad_filters');
            if (saved) {
                try {
                    const filters = JSON.parse(saved);
                    console.log('[Dashboard] Filters loaded:', filters);
                    return filters;
                } catch (e) {
                    console.error('[Dashboard] Error loading filters:', e);
                    return null;
                }
            }
            return null;
        },
        
        /**
         * Clear saved filters
         */
        clear() {
            sessionStorage.removeItem('nws_cad_filters');
            console.log('[Dashboard] Filters cleared');
        },
        
        /**
         * Translate user-friendly filter names to API parameter names
         * @param {Object} filters - User filters from form
         * @returns {Object} - API-compatible parameters
         */
        translateForAPI(filters) {
            const apiParams = {};
            
            for (const [key, value] of Object.entries(filters)) {
                if (key === 'status') {
                    // Translate status to closed_flag
                    // API expects 'true' or 'false' as strings
                    if (value === 'closed') {
                        apiParams.closed_flag = 'true';
                    } else if (value === 'active') {
                        apiParams.closed_flag = 'false';
                    }
                    // If status is empty, don't add closed_flag filter
                } else if (key !== 'quick_period') {
                    // Copy other filters (skip quick_period as it's UI-only)
                    apiParams[key] = value;
                }
            }
            
            return apiParams;
        },
        
        /**
         * Get current filters from form
         */
        getFromForm(formId) {
            const form = document.getElementById(formId);
            if (!form) return {};
            
            const formData = new FormData(form);
            const filters = {};
            
            for (const [key, value] of formData.entries()) {
                if (value !== '') {
                    filters[key] = value;
                }
            }
            
            return filters;
        },
        
        /**
         * Apply filters to form fields
         */
        applyToForm(formId, filters) {
            if (!filters) return;
            
            const form = document.getElementById(formId);
            if (!form) return;
            
            Object.entries(filters).forEach(([key, value]) => {
                const field = form.querySelector(`[name="${key}"]`);
                if (field) {
                    field.value = value;
                }
            });
            
            console.log('[Dashboard] Filters applied to form:', formId);
        },
        
        /**
         * Build query string from filters
         */
        toQueryString(filters) {
            if (!filters || Object.keys(filters).length === 0) return '';
            
            const params = new URLSearchParams();
            Object.entries(filters).forEach(([key, value]) => {
                if (value !== '' && value !== null && value !== undefined) {
                    params.append(key, value);
                }
            });
            
            return params.toString() ? '?' + params.toString() : '';
        }
    },
    
    /**
     * Show error message
     */
    showError(message) {
        this.showToast(message, 'danger');
    },
    
    /**
     * Show success message
     */
    showSuccess(message) {
        this.showToast(message, 'success');
    },
    
    /**
     * Format date/time
     */
    formatDateTime(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    },
    
    /**
     * Format date only
     */
    formatDate(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    },
    
    /**
     * Format time only
     */
    formatTime(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit'
        });
    },
    
    /**
     * Calculate time ago
     */
    timeAgo(dateString) {
        if (!dateString) return 'N/A';
        
        const date = new Date(dateString);
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);
        
        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
        if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;
        
        return this.formatDate(dateString);
    },
    
    /**
     * Format duration
     */
    formatDuration(seconds) {
        if (!seconds || seconds < 0) return 'N/A';
        
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = Math.floor(seconds % 60);
        
        if (hours > 0) {
            return `${hours}h ${minutes}m`;
        } else if (minutes > 0) {
            return `${minutes}m ${secs}s`;
        } else {
            return `${secs}s`;
        }
    },
    
    /**
     * Get priority badge HTML
     */
    getPriorityBadge(priority) {
        if (!priority) return '<span class="badge bg-secondary">Unknown</span>';
        
        // Extract number from priority string (e.g., "2 - Police Medium" -> "2")
        const priorityNum = String(priority).match(/^(\d+)/)?.[1];
        
        const badges = {
            '1': '<span class="badge bg-danger">Priority 1</span>',
            '2': '<span class="badge bg-warning">Priority 2</span>',
            '3': '<span class="badge bg-info">Priority 3</span>',
            '4': '<span class="badge bg-secondary">Priority 4</span>',
            '5': '<span class="badge bg-secondary">Priority 5</span>'
        };
        
        // If we have the full priority string, show it; otherwise show generic
        if (priorityNum && badges[priorityNum]) {
            return `<span class="badge bg-${priorityNum === '1' ? 'danger' : priorityNum === '2' ? 'warning' : priorityNum === '3' ? 'info' : 'secondary'}">${priority}</span>`;
        }
        
        return badges[priorityNum] || badges[priority] || '<span class="badge bg-secondary">' + priority + '</span>';
    },
    
    /**
     * Get status badge HTML
     */
    getStatusBadge(status) {
        const badges = {
            'open': '<span class="badge bg-warning">Open</span>',
            'active': '<span class="badge bg-primary">Active</span>',
            'closed': '<span class="badge bg-success">Closed</span>',
            'cancelled': '<span class="badge bg-secondary">Cancelled</span>'
        };
        return badges[status] || '<span class="badge bg-secondary">' + status + '</span>';
    },
    
    /**
     * Setup auto-refresh for a function
     */
    setupAutoRefresh(refreshFunction, interval = null) {
        interval = interval || this.config.refreshInterval || 30000;
        
        console.log('[Dashboard] setupAutoRefresh called for:', refreshFunction.name, 'interval:', interval);
        
        // Clear existing timer if any
        if (this.refreshTimers[refreshFunction.name]) {
            clearInterval(this.refreshTimers[refreshFunction.name]);
        }
        
        // Set new timer
        this.refreshTimers[refreshFunction.name] = setInterval(() => {
            console.log('Auto-refreshing...', refreshFunction.name);
            refreshFunction();
        }, interval);
        
        console.log(`Auto-refresh enabled (${interval / 1000}s)`);
        console.log('[Dashboard] Active refresh timers:', Object.keys(this.refreshTimers));
        
        // Update live indicator
        console.log('[Dashboard] Calling updateLiveIndicator(true)...');
        this.updateLiveIndicator(true);
    },
    
    /**
     * Stop auto-refresh
     */
    stopAutoRefresh(functionName = null) {
        if (functionName && this.refreshTimers[functionName]) {
            clearInterval(this.refreshTimers[functionName]);
            delete this.refreshTimers[functionName];
        } else {
            // Stop all timers
            Object.values(this.refreshTimers).forEach(timer => clearInterval(timer));
            this.refreshTimers = {};
        }
        
        // Update live indicator
        const hasActiveTimers = Object.keys(this.refreshTimers).length > 0;
        this.updateLiveIndicator(hasActiveTimers);
    },
    
    /**
     * Update live indicator status
     */
    updateLiveIndicator(isLive) {
        const indicator = document.getElementById('live-indicator');
        if (!indicator) {
            console.error('[Dashboard] Live indicator element not found!');
            return;
        }
        
        if (isLive) {
            // Bright green badge with white text
            indicator.innerHTML = '<span style="background: #00ff00; color: white; border-radius: 50%; padding: 4px 8px; font-size: 0.75em; display: inline-block; line-height: 1; font-weight: 500; box-shadow: 0 0 8px rgba(0, 255, 0, 0.5);" class="pulse">●</span> <span style="color: white;">Live</span>';
        } else {
            // Gray badge when paused
            indicator.innerHTML = '<span style="background: #6c757d; color: white; border-radius: 50%; padding: 4px 8px; font-size: 0.75em; display: inline-block; line-height: 1; font-weight: 500;">●</span> <span style="color: rgba(255,255,255,0.7);">Paused</span>';
        }
        
        console.log('[Dashboard] Live indicator updated:', isLive ? 'Live (green badge pulsing)' : 'Paused (gray badge)');
    }
};

// Export Dashboard object
window.Dashboard = Dashboard;

console.log('Dashboard core loaded', Dashboard.config);

// Check API status on load (wait for DOM to be ready)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', checkApiStatus);
} else {
    checkApiStatus();
}

async function checkApiStatus() {
    const statusEl = document.getElementById('api-status');
    if (!statusEl) {
        console.warn('[Dashboard] API status element not found');
        return;
    }
    
    try {
        console.log('[Dashboard] Checking API status...');
        const response = await fetch(Dashboard.config.apiBaseUrl + '/stats');
        
        if (response.ok) {
            const data = await response.json();
            if (data.success) {
                statusEl.textContent = 'Online';
                statusEl.className = 'badge bg-success';
                console.log('[Dashboard] API is online');
                
                // Update live indicator immediately
                Dashboard.updateLiveIndicator(true);
                
                // Start heartbeat monitoring
                startHeartbeat();
            } else {
                throw new Error('API returned error');
            }
        } else {
            throw new Error(`HTTP ${response.status}`);
        }
    } catch (error) {
        console.error('[Dashboard] API status check failed:', error);
        statusEl.textContent = 'Offline';
        statusEl.className = 'badge bg-danger';
        Dashboard.updateLiveIndicator(false);
    }
}

/**
 * Start heartbeat monitoring to check API connectivity
 */
function startHeartbeat() {
    const statusEl = document.getElementById('api-status');
    
    // Check API every 30 seconds
    setInterval(async () => {
        try {
            const response = await fetch(Dashboard.config.apiBaseUrl + '/stats', {
                method: 'GET',
                cache: 'no-cache'
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    if (statusEl) {
                        statusEl.textContent = 'Online';
                        statusEl.className = 'badge bg-success';
                    }
                    // Always show live indicator when API is online
                    Dashboard.updateLiveIndicator(true);
                } else {
                    throw new Error('Invalid response');
                }
            } else {
                throw new Error(`HTTP ${response.status}`);
            }
        } catch (error) {
            console.warn('[Dashboard] Heartbeat failed:', error);
            if (statusEl) {
                statusEl.textContent = 'Offline';
                statusEl.className = 'badge bg-danger';
            }
            Dashboard.updateLiveIndicator(false);
        }
    }, 30000); // Every 30 seconds
}

