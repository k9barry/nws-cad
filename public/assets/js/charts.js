/**
 * NWS CAD Dashboard - Chart Integration
 * Chart.js functionality for data visualization
 */

const ChartManager = {
    charts: {},

    // Okabe-Ito colorblind-safe categorical palette. Used as a hardcoded fallback
    // when the CSS `--cat-1`..`--cat-8` tokens are not present on :root. Order is
    // arranged so no pure green sits directly next to a pure red.
    okabeItoFallback: [
        '#0072b2', '#e69f00', '#009e73', '#cc79a7',
        '#56b4e9', '#d55e00', '#f0e442', '#999999'
    ],
    _categoricalColors: null,

    /**
     * Resolve the colorblind-safe categorical palette. Reads `--cat-1`..`--cat-8`
     * from :root (Okabe-Ito, added by the integrator to dashboard.css) and falls
     * back to the hardcoded Okabe-Ito array per-slot when a token is missing.
     * Cached after first resolution.
     */
    getCategoricalColors() {
        if (this._categoricalColors) return this._categoricalColors;
        const styles = getComputedStyle(document.documentElement);
        const colors = [];
        for (let i = 1; i <= 8; i++) {
            const token = styles.getPropertyValue(`--cat-${i}`).trim();
            colors.push(token || this.okabeItoFallback[i - 1]);
        }
        this._categoricalColors = colors;
        return colors;
    },

    /**
     * Repeat the categorical palette to cover `count` series.
     */
    categoricalColors(count) {
        const base = this.getCategoricalColors();
        const out = [];
        for (let i = 0; i < count; i++) {
            out.push(base[i % base.length]);
        }
        return out;
    },

    /**
     * Populate the accessible name + visually-hidden data summary for a chart canvas.
     * Charts are `role="img"` in the markup; screen readers otherwise get nothing.
     * We compose the canvas `aria-label` from its `data-chart-title` plus the key
     * numbers, and fill a sibling `#<canvasId>-summary` element with a data table.
     */
    applyChartA11y(canvasId, data) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        const title = (canvas.dataset.chartTitle
            || canvas.getAttribute('aria-label')
            || 'Chart').trim();
        const labels = (data && data.labels) || [];
        const datasets = (data && data.datasets) || [];
        const primary = datasets[0] || { data: [] };
        const primaryData = primary.data || [];

        // Short key-numbers sentence for the aria-label.
        let sentence;
        if (!labels.length) {
            sentence = 'No data available';
        } else {
            sentence = labels
                .map((label, i) => `${label}: ${primaryData[i] ?? 0}`)
                .join(', ');
        }
        canvas.setAttribute('aria-label', `${title}. ${sentence}.`);

        // Full data table alternative in the visually-hidden sibling element.
        const summaryEl = document.getElementById(`${canvasId}-summary`);
        if (!summaryEl) return;

        if (!labels.length) {
            summaryEl.textContent = `${title}: no data available.`;
            return;
        }

        const table = document.createElement('table');
        const caption = document.createElement('caption');
        caption.textContent = `${title} (data table)`;
        table.appendChild(caption);

        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');
        const catTh = document.createElement('th');
        catTh.scope = 'col';
        catTh.textContent = 'Category';
        headRow.appendChild(catTh);
        datasets.forEach((ds, i) => {
            const th = document.createElement('th');
            th.scope = 'col';
            th.textContent = ds.label || `Series ${i + 1}`;
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        labels.forEach((label, rowIdx) => {
            const tr = document.createElement('tr');
            const th = document.createElement('th');
            th.scope = 'row';
            th.textContent = label;
            tr.appendChild(th);
            datasets.forEach((ds) => {
                const td = document.createElement('td');
                const val = (ds.data || [])[rowIdx];
                td.textContent = val ?? 0;
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);

        summaryEl.textContent = '';
        summaryEl.appendChild(table);
    },

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

        this.applyChartA11y(canvasId, data);

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

        this.applyChartA11y(canvasId, data);

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

        this.applyChartA11y(canvasId, data);

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

        this.applyChartA11y(canvasId, data);

        return this.charts[canvasId];
    },

    /**
     * Update chart data
     */
    updateChart(chartId, data) {
        if (!this.charts[chartId]) return;

        this.charts[chartId].data = data;
        this.charts[chartId].update();
        this.applyChartA11y(chartId, data);
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
                backgroundColor: this.categoricalColors(callStats.by_type.length)
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
        
        // Colorblind-safe (Okabe-Ito) status colors. No pure green/red pairing:
        // available=bluish-green, enroute=orange, onscene=vermillion, offduty=gray.
        const statusColors = {
            'available': '#009e73',
            'enroute': '#e69f00',
            'onscene': '#d55e00',
            'offduty': '#999999'
        };

        return {
            labels: unitStats.by_status.map(item => item.status),
            datasets: [{
                label: 'Units',
                data: unitStats.by_status.map(item => item.count),
                backgroundColor: unitStats.by_status.map(item =>
                    statusColors[item.status.toLowerCase()] || '#999999'
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
                backgroundColor: this.categoricalColors(agencyStats.by_agency.length)
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
