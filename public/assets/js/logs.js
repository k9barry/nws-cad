/**
 * Logs Page Script
 * Handles the system logs viewer page
 */

(function() {
    'use strict';
    
    console.log('[Logs] Script loaded');
    
    let currentFile = null;
    let currentPage = 1;
    let currentLevel = null;
    let currentMode = 'file'; // 'file' or 'recent'
    
    async function init() {
        if (typeof Dashboard === 'undefined') {
            console.error('[Logs] Dashboard object not found, retrying...');
            setTimeout(init, 100);
            return;
        }
        
        console.log('[Logs] Initializing logs page...');
        
        // Load log files list
        await loadLogFiles();
        
        // Setup event listeners
        setupEventListeners();
        
        console.log('[Logs] Initialization complete');
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    function setupEventListeners() {
        // Refresh button
        document.getElementById('refresh-btn').addEventListener('click', () => {
            if (currentMode === 'recent') {
                // Refresh recent view with same parameters
                const level = currentLevel;
                viewRecent(50, level);
            } else if (currentFile) {
                loadLogFile(currentFile, currentPage);
            } else {
                loadLogFiles();
            }
        });
        
        // Quick view buttons
        document.getElementById('view-recent-btn').addEventListener('click', () => {
            viewRecent(50, null);
        });
        
        document.getElementById('view-errors-btn').addEventListener('click', () => {
            viewRecent(50, 'ERROR');
        });
        
        document.getElementById('view-warnings-btn').addEventListener('click', () => {
            viewRecent(50, 'WARNING');
        });
        
        // Level filter
        document.getElementById('level-filter').addEventListener('change', (e) => {
            currentLevel = e.target.value || null;
            currentPage = 1;
            if (currentFile) {
                loadLogFile(currentFile, currentPage);
            }
        });
        
        // Per page selector
        document.getElementById('per-page').addEventListener('change', () => {
            currentPage = 1;
            if (currentFile) {
                loadLogFile(currentFile, currentPage);
            }
        });
        
        // Cleanup button
        document.getElementById('confirm-cleanup-btn').addEventListener('click', async () => {
            const days = document.getElementById('cleanup-days').value;
            await cleanupLogs(days);
        });
    }
    
    async function loadLogFiles() {
        console.log('[Logs] Loading log files...');
        
        try {
            const files = await Dashboard.apiRequest('/logs');
            console.log('[Logs] Files:', files);
            
            const container = document.getElementById('log-files-list');
            
            if (!files.files || files.files.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-inbox fs-3"></i>
                        <p class="mt-2 mb-0">No log files found</p>
                    </div>
                `;
                return;
            }
            
            container.innerHTML = files.files.map(file => `
                <div class="file-item list-group-item list-group-item-action" data-filename="${file.name}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold">${escapeHtml(file.name)}</div>
                            <small class="text-muted">${formatFileSize(file.size)}</small>
                        </div>
                        <small class="text-muted">${formatDateTime(file.modified_formatted)}</small>
                    </div>
                </div>
            `).join('');
            
            // Add click handlers
            document.querySelectorAll('.file-item').forEach(item => {
                item.addEventListener('click', () => {
                    const filename = item.dataset.filename;
                    selectFile(filename);
                });
            });
            
        } catch (error) {
            console.error('[Logs] Error loading files:', error);
            Dashboard.showError('Failed to load log files');
        }
    }
    
    function selectFile(filename) {
        currentFile = filename;
        currentPage = 1;
        currentMode = 'file';
        
        // Update active state
        document.querySelectorAll('.file-item').forEach(item => {
            item.classList.toggle('active', item.dataset.filename === filename);
        });
        
        // Load file contents
        loadLogFile(filename, currentPage);
    }
    
    async function loadLogFile(filename, page = 1) {
        console.log('[Logs] Loading file:', filename, 'page:', page);
        
        const viewer = document.getElementById('log-viewer');
        const title = document.getElementById('current-log-title');
        const perPage = document.getElementById('per-page').value;
        
        title.textContent = filename;
        viewer.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `;
        
        try {
            const params = {
                page: page,
                per_page: perPage
            };
            
            if (currentLevel) {
                params.level = currentLevel;
            }
            
            const queryString = Dashboard.buildQueryString(params);
            const data = await Dashboard.apiRequest(`/logs/${filename}${queryString}`);
            
            console.log('[Logs] Data:', data);
            
            if (!data.items || data.items.length === 0) {
                viewer.innerHTML = `
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1"></i>
                        <p class="mt-2">No log entries found</p>
                    </div>
                `;
                document.getElementById('log-pagination').style.display = 'none';
                return;
            }
            
            // Render log entries
            viewer.innerHTML = data.items.map(entry => renderLogEntry(entry)).join('');
            
            // Render pagination
            renderPagination(data.pagination);
            
        } catch (error) {
            console.error('[Logs] Error loading file:', error);
            viewer.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                    Failed to load log file: ${escapeHtml(error.message)}
                </div>
            `;
        }
    }
    
    async function viewRecent(lines, level) {
        console.log('[Logs] Viewing recent entries, lines:', lines, 'level:', level);
        
        const viewer = document.getElementById('log-viewer');
        const title = document.getElementById('current-log-title');
        
        currentFile = null;
        currentMode = 'recent';
        currentLevel = level;
        document.querySelectorAll('.file-item').forEach(item => item.classList.remove('active'));
        
        title.textContent = level ? `Recent ${level} Entries` : 'Recent Log Entries';
        viewer.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `;
        
        try {
            let url = `/logs/recent?lines=${lines}`;
            if (level) {
                url += `&level=${level}`;
            }
            
            const data = await Dashboard.apiRequest(url);
            console.log('[Logs] Recent data:', data);
            
            if (!data.entries || data.entries.length === 0) {
                viewer.innerHTML = `
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1"></i>
                        <p class="mt-2">No log entries found</p>
                    </div>
                `;
                document.getElementById('log-pagination').style.display = 'none';
                return;
            }
            
            viewer.innerHTML = data.entries.map(entry => renderLogEntry(entry)).join('');
            document.getElementById('log-pagination').style.display = 'none';
            
        } catch (error) {
            console.error('[Logs] Error viewing recent:', error);
            viewer.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                    Failed to load recent logs: ${escapeHtml(error.message)}
                </div>
            `;
        }
    }
    
    function renderLogEntry(entry) {
        const levelClass = `level-${entry.level}`;
        const levelBadge = getLevelBadge(entry.level);
        
        return `
            <div class="log-entry ${levelClass}">
                <span class="log-timestamp">${entry.timestamp || 'N/A'}</span>
                ${levelBadge}
                ${entry.channel ? `<small class="text-muted">[${escapeHtml(entry.channel)}]</small>` : ''}
                <div class="log-message mt-1">${escapeHtml(entry.message)}</div>
            </div>
        `;
    }
    
    function getLevelBadge(level) {
        const badges = {
            'DEBUG': 'secondary',
            'INFO': 'info',
            'NOTICE': 'primary',
            'WARNING': 'warning',
            'ERROR': 'danger',
            'CRITICAL': 'danger',
            'ALERT': 'danger',
            'EMERGENCY': 'danger',
            'UNKNOWN': 'secondary'
        };
        
        const color = badges[level] || 'secondary';
        return `<span class="badge bg-${color} log-level">${level}</span>`;
    }
    
    function renderPagination(pagination) {
        const container = document.getElementById('log-pagination');
        const ul = container.querySelector('.pagination');
        
        if (!pagination || pagination.total_pages <= 1) {
            container.style.display = 'none';
            return;
        }
        
        container.style.display = 'block';
        
        let html = '';
        
        // Previous button
        html += `
            <li class="page-item ${pagination.current_page === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${pagination.current_page - 1}">Previous</a>
            </li>
        `;
        
        // Page numbers
        const maxPages = 5;
        let startPage = Math.max(1, pagination.current_page - Math.floor(maxPages / 2));
        let endPage = Math.min(pagination.total_pages, startPage + maxPages - 1);
        
        if (endPage - startPage < maxPages - 1) {
            startPage = Math.max(1, endPage - maxPages + 1);
        }
        
        for (let i = startPage; i <= endPage; i++) {
            html += `
                <li class="page-item ${i === pagination.current_page ? 'active' : ''}">
                    <a class="page-link" href="#" data-page="${i}">${i}</a>
                </li>
            `;
        }
        
        // Next button
        html += `
            <li class="page-item ${pagination.current_page === pagination.total_pages ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${pagination.current_page + 1}">Next</a>
            </li>
        `;
        
        ul.innerHTML = html;
        
        // Add click handlers
        ul.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const page = parseInt(link.dataset.page);
                if (page > 0 && page <= pagination.total_pages) {
                    currentPage = page;
                    loadLogFile(currentFile, page);
                }
            });
        });
    }
    
    async function cleanupLogs(days) {
        console.log('[Logs] Cleaning up logs older than', days, 'days');
        
        try {
            const response = await fetch(
                `${Dashboard.config.apiBaseUrl}/logs/cleanup?days=${days}`,
                { method: 'DELETE' }
            );
            
            const result = await response.json();
            
            if (result.success) {
                Dashboard.showSuccess(result.data.message);
                
                // Close modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('cleanupModal'));
                modal.hide();
                
                // Reload file list
                await loadLogFiles();
            } else {
                Dashboard.showError(result.error || 'Cleanup failed');
            }
            
        } catch (error) {
            console.error('[Logs] Cleanup error:', error);
            Dashboard.showError('Failed to cleanup logs');
        }
    }
    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    function formatDateTime(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
