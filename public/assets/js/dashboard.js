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
                throw new Error(data.message || 'API request failed');
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
     * Calculate time ago
     */
    timeAgo(dateString) {
        if (!dateString) return 'N/A';
        
        const date = new Date(dateString);
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);
        
        const intervals = {
            year: 31536000,
            month: 2592000,
            week: 604800,
            day: 86400,
            hour: 3600,
            minute: 60
        };
        
        for (const [unit, value] of Object.entries(intervals)) {
            const interval = Math.floor(seconds / value);
            if (interval >= 1) {
                return `${interval} ${unit}${interval > 1 ? 's' : ''} ago`;
            }
        }
        
        return 'Just now';
    },
    
    /**
     * Get status badge HTML
     */
    getStatusBadge(status) {
        const statusMap = {
            'active': 'status-active',
            'pending': 'status-pending',
            'closed': 'status-closed',
            'available': 'bg-success',
            'enroute': 'bg-warning',
            'onscene': 'bg-danger',
            'offduty': 'bg-secondary'
        };
        
        const className = statusMap[status?.toLowerCase()] || 'bg-secondary';
        return `<span class="badge ${className}">${status || 'Unknown'}</span>`;
    },
    
    /**
     * Get priority badge HTML
     */
    getPriorityBadge(priority) {
        const className = `priority-${priority}`;
        return `<span class="badge ${className}">P${priority}</span>`;
    },
    
    /**
     * Export data to CSV
     */
    exportToCSV(data, filename = 'export.csv') {
        if (!data || data.length === 0) {
            this.showError('No data to export');
            return;
        }
        
        // Get headers from first object
        const headers = Object.keys(data[0]);
        
        // Build CSV content
        let csv = headers.join(',') + '\n';
        
        data.forEach(row => {
            const values = headers.map(header => {
                const value = row[header];
                // Escape commas and quotes
                if (typeof value === 'string' && (value.includes(',') || value.includes('"'))) {
                    return `"${value.replace(/"/g, '""')}"`;
                }
                return value ?? '';
            });
            csv += values.join(',') + '\n';
        });
        
        // Create download link
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        
        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        this.showSuccess('Data exported successfully');
    },
    
    /**
     * Setup auto-refresh
     */
    setupAutoRefresh(callback, interval = null) {
        interval = interval || this.config.refreshInterval || 30000;
        
        if (this.refreshTimers[this.config.currentPage]) {
            clearInterval(this.refreshTimers[this.config.currentPage]);
        }
        
        this.refreshTimers[this.config.currentPage] = setInterval(callback, interval);
    },
    
    /**
     * Stop auto-refresh
     */
    stopAutoRefresh() {
        if (this.refreshTimers[this.config.currentPage]) {
            clearInterval(this.refreshTimers[this.config.currentPage]);
            delete this.refreshTimers[this.config.currentPage];
        }
    },
    
    /**
     * Check API status
     */
    async checkApiStatus() {
        try {
            await this.apiRequest('/');
            const statusEl = document.getElementById('api-status');
            if (statusEl) {
                statusEl.textContent = 'Online';
                statusEl.className = 'badge bg-success';
            }
        } catch (error) {
            const statusEl = document.getElementById('api-status');
            if (statusEl) {
                statusEl.textContent = 'Offline';
                statusEl.className = 'badge bg-danger';
            }
        }
    },
    
    /**
     * Initialize dashboard
     */
    init() {
        console.log('Dashboard initialized');
        this.checkApiStatus();
        
        // Set up print date
        document.body.setAttribute('data-print-date', new Date().toLocaleString());
        
        // Check API status periodically
        setInterval(() => this.checkApiStatus(), 60000);
    }
};

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => Dashboard.init());
} else {
    Dashboard.init();
}

// Make Dashboard globally available
window.Dashboard = Dashboard;
