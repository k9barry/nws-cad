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
        
        try {
            const response = await fetch(url, {
                method: options.method || 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    ...options.headers
                },
                body: options.body ? JSON.stringify(options.body) : undefined
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
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
        const badges = {
            '1': '<span class="badge bg-danger">Priority 1</span>',
            '2': '<span class="badge bg-warning">Priority 2</span>',
            '3': '<span class="badge bg-info">Priority 3</span>',
            '4': '<span class="badge bg-secondary">Priority 4</span>'
        };
        return badges[priority] || '<span class="badge bg-secondary">Unknown</span>';
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
    }
};

// Export Dashboard object
window.Dashboard = Dashboard;

console.log('Dashboard core loaded', Dashboard.config);
