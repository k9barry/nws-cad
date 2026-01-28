/**
 * NWS CAD Dashboard - Chart Integration
 * Chart.js functionality for data visualization
 */

const ChartManager = {
    charts: {},
    defaultColors: [
        '#0d6efd', '#198754', '#ffc107', '#dc3545', '#0dcaf0',
        '#6610f2', '#fd7e14', '#20c997', '#6f42c1', '#d63384'
    ],
    
    /**
     * Destroy a chart if it exists
     */
    destroyChart(chartId) {
        if (this.charts[chartId]) {
            this.charts[chartId].destroy();
            delete this.charts[chartId];
        }
    },
    
    /**
     * Create a line chart
     */
    createLineChart(canvasId, data, options = {}) {
        this.destroyChart(canvasId);
        
        const ctx = document.getElementById(canvasId);
        if (!ctx) return null;
        
        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: options.showLegend !== false,
                    position: 'top'
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        };
        
        this.charts[canvasId] = new Chart(ctx, {
            type: 'line',
            data: data,
            options: { ...defaultOptions, ...options }
        });
        
        return this.charts[canvasId];
    },
    
    /**
     * Create a bar chart
     */
    createBarChart(canvasId, data, options = {}) {
        this.destroyChart(canvasId);
        
        const ctx = document.getElementById(canvasId);
        if (!ctx) return null;
        
        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: options.showLegend !== false,
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        };
        
        this.charts[canvasId] = new Chart(ctx, {
            type: 'bar',
            data: data,
            options: { ...defaultOptions, ...options }
        });
        
        return this.charts[canvasId];
    },
    
    /**
     * Create a pie chart
     */
    createPieChart(canvasId, data, options = {}) {
        this.destroyChart(canvasId);
        
        const ctx = document.getElementById(canvasId);
        if (!ctx) return null;
        
        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: true,
                    position: 'right'
                }
            }
        };
        
        this.charts[canvasId] = new Chart(ctx, {
            type: 'pie',
            data: data,
            options: { ...defaultOptions, ...options }
        });
        
        return this.charts[canvasId];
    },
    
    /**
     * Create a doughnut chart
     */
    createDoughnutChart(canvasId, data, options = {}) {
        this.destroyChart(canvasId);
        
        const ctx = document.getElementById(canvasId);
        if (!ctx) return null;
        
        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: true,
                    position: 'right'
                }
            }
        };
        
        this.charts[canvasId] = new Chart(ctx, {
            type: 'doughnut',
            data: data,
            options: { ...defaultOptions, ...options }
        });
        
        return this.charts[canvasId];
    },
    
    /**
     * Update chart data
     */
    updateChart(chartId, data) {
        if (!this.charts[chartId]) return;
        
        this.charts[chartId].data = data;
        this.charts[chartId].update();
    },
    
    /**
     * Prepare data for call volume trend chart
     */
    prepareCallVolumeData(callStats) {
        if (!callStats || !callStats.timeline) {
            return {
                labels: [],
                datasets: [{
                    label: 'Calls',
                    data: [],
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    tension: 0.3
                }]
            };
        }
        
        return {
            labels: callStats.timeline.map(item => item.label || item.time),
            datasets: [{
                label: 'Call Volume',
                data: callStats.timeline.map(item => item.count),
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                fill: true,
                tension: 0.3
            }]
        };
    },
    
    /**
     * Prepare data for call types distribution chart
     */
    prepareCallTypesData(callStats) {
        if (!callStats || !callStats.by_type) {
            return {
                labels: [],
                datasets: [{
                    data: [],
                    backgroundColor: []
                }]
            };
        }
        
        return {
            labels: callStats.by_type.map(item => `${item.type} (${item.count})`),
            datasets: [{
                data: callStats.by_type.map(item => item.count),
                backgroundColor: this.defaultColors.slice(0, callStats.by_type.length)
            }]
        };
    },
    
    /**
     * Prepare data for unit activity chart
     */
    prepareUnitActivityData(unitStats) {
        if (!unitStats || !unitStats.by_status) {
            return {
                labels: [],
                datasets: [{
                    label: 'Units',
                    data: [],
                    backgroundColor: []
                }]
            };
        }
        
        const statusColors = {
            'available': '#198754',
            'enroute': '#ffc107',
            'onscene': '#dc3545',
            'offduty': '#6c757d'
        };
        
        return {
            labels: unitStats.by_status.map(item => item.status),
            datasets: [{
                label: 'Units',
                data: unitStats.by_status.map(item => item.count),
                backgroundColor: unitStats.by_status.map(item => 
                    statusColors[item.status.toLowerCase()] || '#6c757d'
                )
            }]
        };
    },
    
    /**
     * Prepare data for response time chart
     */
    prepareResponseTimeData(responseStats) {
        if (!responseStats || !responseStats.timeline) {
            return {
                labels: [],
                datasets: [{
                    label: 'Avg Response Time (min)',
                    data: [],
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25, 135, 84, 0.1)'
                }]
            };
        }
        
        return {
            labels: responseStats.timeline.map(item => item.label || item.time),
            datasets: [{
                label: 'Avg Response Time (min)',
                data: responseStats.timeline.map(item => item.avg_time / 60), // Convert to minutes
                borderColor: '#198754',
                backgroundColor: 'rgba(25, 135, 84, 0.1)',
                fill: true,
                tension: 0.3
            }]
        };
    },
    
    /**
     * Prepare data for agency comparison chart
     */
    prepareAgencyData(agencyStats) {
        if (!agencyStats || !agencyStats.by_agency) {
            return {
                labels: [],
                datasets: [{
                    label: 'Calls',
                    data: [],
                    backgroundColor: '#0d6efd'
                }]
            };
        }
        
        return {
            labels: agencyStats.by_agency.map(item => item.agency),
            datasets: [{
                label: 'Call Count',
                data: agencyStats.by_agency.map(item => item.count),
                backgroundColor: this.defaultColors.slice(0, agencyStats.by_agency.length)
            }]
        };
    },
    
    /**
     * Create empty state for chart
     */
    showEmptyChart(canvasId, message = 'No data available') {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;
        
        const parent = ctx.parentElement;
        parent.innerHTML = `
            <div class="empty-state">
                <i class="bi bi-bar-chart"></i>
                <h4>No Data Available</h4>
                <p>${message}</p>
            </div>
        `;
    }
};

// Make globally available
window.ChartManager = ChartManager;
