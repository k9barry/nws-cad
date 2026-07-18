/**
 * System Status modal controller.
 *
 * Populates #system-status-modal from GET /api/health/system when the modal is
 * opened (via the navbar live pill, the footer API-status badge, or the footer
 * version label). All CAD/host-derived strings are rendered through
 * Dashboard.safeHtml so nothing is interpolated into the DOM unescaped.
 *
 * @version 1.0.0
 */
(function () {
    'use strict';

    const modal = document.getElementById('system-status-modal');
    if (!modal || typeof Dashboard === 'undefined') {
        return;
    }

    const els = {
        loading: document.getElementById('system-status-loading'),
        content: document.getElementById('system-status-content'),
        error: document.getElementById('system-status-error'),
        overall: document.getElementById('system-status-overall'),
        timestamp: document.getElementById('system-status-timestamp'),
        refresh: document.getElementById('system-status-refresh'),
    };

    // status keyword -> Bootstrap badge/meter colour + label
    const STATUS_META = {
        ok:       { cls: 'bg-success',   bar: 'bg-success',   label: 'OK' },
        warn:     { cls: 'bg-warning text-dark', bar: 'bg-warning', label: 'Warning' },
        critical: { cls: 'bg-danger',    bar: 'bg-danger',    label: 'Critical' },
        unknown:  { cls: 'bg-secondary', bar: 'bg-secondary', label: 'Unknown' },
    };

    function statusMeta(status) {
        return STATUS_META[status] || STATUS_META.unknown;
    }

    function formatBytes(bytes) {
        if (bytes === null || bytes === undefined || isNaN(bytes)) return '—';
        if (bytes === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        const value = bytes / Math.pow(1024, i);
        return `${value.toFixed(value >= 10 || i === 0 ? 0 : 1)} ${units[i]}`;
    }

    function statusBadge(status) {
        const meta = statusMeta(status);
        return Dashboard.safeHtml`<span class="badge ${meta.cls}">${meta.label}</span>`;
    }

    // A labelled usage meter (disk / memory).
    function meterRow(label, sub, usedPct, status) {
        const meta = statusMeta(status);
        const pct = (usedPct === null || usedPct === undefined) ? null : Math.max(0, Math.min(100, usedPct));
        const bar = pct === null
            ? Dashboard.safeHtml`<div class="text-muted small">Not available</div>`
            : Dashboard.safeHtml`
                <div class="progress system-status-meter" role="progressbar"
                     aria-valuenow="${String(pct)}" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar ${Dashboard.raw(meta.bar)}" style="width: ${String(pct)}%"></div>
                </div>`;
        return Dashboard.safeHtml`
            <div class="system-status-item">
                <div class="d-flex justify-content-between align-items-baseline">
                    <span class="fw-semibold">${label}</span>
                    <span>${Dashboard.raw(statusBadge(status))}</span>
                </div>
                <div class="text-muted small mb-1">${sub}</div>
                ${Dashboard.raw(bar)}
                <div class="text-muted small mt-1">${pct === null ? '—' : pct + '% used'}</div>
            </div>`;
    }

    function kvRow(label, value, status) {
        const badge = status ? Dashboard.raw(statusBadge(status)) : '';
        return Dashboard.safeHtml`
            <div class="d-flex justify-content-between align-items-baseline system-status-kv">
                <span class="text-muted">${label}</span>
                <span class="fw-semibold">${value} ${badge}</span>
            </div>`;
    }

    function render(data) {
        const parts = [];

        // Version / app block
        const app = data.app || {};
        parts.push(Dashboard.safeHtml`
            <div class="system-status-section">
                <h6 class="system-status-heading"><i class="bi bi-tag"></i> Version</h6>
                ${Dashboard.raw(kvRow('Application', 'v' + (app.version || 'unknown')))}
                ${Dashboard.raw(kvRow('PHP', app.php_version || '—'))}
                ${Dashboard.raw(kvRow('Environment', app.environment || '—'))}
            </div>`);

        // Disks
        const disks = Array.isArray(data.disks) ? data.disks : [];
        const diskMeters = disks.length
            ? disks.map(d => meterRow(
                d.label || 'Disk',
                `${formatBytes(d.free_bytes)} free of ${formatBytes(d.total_bytes)}`,
                d.used_pct,
                d.status
            )).join('')
            : Dashboard.safeHtml`<div class="text-muted small">No disk information available.</div>`;
        parts.push(Dashboard.safeHtml`
            <div class="system-status-section">
                <h6 class="system-status-heading"><i class="bi bi-hdd"></i> Disk</h6>
                ${Dashboard.raw(diskMeters)}
            </div>`);

        // Memory
        const mem = data.memory || {};
        const memSub = mem.limit_bytes
            ? `${formatBytes(mem.php_usage_bytes)} of ${formatBytes(mem.limit_bytes)} (peak ${formatBytes(mem.php_peak_bytes)})`
            : `${formatBytes(mem.php_usage_bytes)} used (peak ${formatBytes(mem.php_peak_bytes)}, no limit)`;
        parts.push(Dashboard.safeHtml`
            <div class="system-status-section">
                <h6 class="system-status-heading"><i class="bi bi-memory"></i> Memory (PHP)</h6>
                ${Dashboard.raw(meterRow('PHP process', memSub, mem.used_pct, mem.status))}
            </div>`);

        // Server: db, load, watcher, outbox
        const db = data.db || {};
        const dbValue = db.latency_ms !== null && db.latency_ms !== undefined
            ? `${db.latency_ms} ms`
            : 'unreachable';
        const serverRows = [kvRow('Database', dbValue, db.status)];

        const load = data.load;
        if (load) {
            const cpus = load.cpus ? ` (${load.cpus} CPU${load.cpus === 1 ? '' : 's'})` : '';
            serverRows.push(kvRow('Load avg', `${load['1m']} / ${load['5m']} / ${load['15m']}${cpus}`));
        }

        const watcher = data.watcher || {};
        const watcherValue = watcher.heartbeat_age_seconds === null || watcher.heartbeat_age_seconds === undefined
            ? 'no heartbeat'
            : `${watcher.heartbeat_age_seconds}s ago`;
        serverRows.push(kvRow('File watcher', watcherValue, watcher.status));

        const outbox = data.outbox;
        if (outbox) {
            serverRows.push(kvRow(
                'Notification outbox',
                `${outbox.pending} pending · ${outbox.in_flight} in-flight · ${outbox.failed} failed`
            ));
        }

        parts.push(Dashboard.safeHtml`
            <div class="system-status-section">
                <h6 class="system-status-heading"><i class="bi bi-hdd-network"></i> Server</h6>
                ${Dashboard.raw(serverRows.join(''))}
            </div>`);

        els.content.innerHTML = parts.join('');

        // Overall badge + timestamp
        const meta = statusMeta(data.status);
        els.overall.className = `badge ${meta.cls}`;
        els.overall.textContent = meta.label;
        if (data.timestamp) {
            const dt = new Date(data.timestamp);
            els.timestamp.textContent = isNaN(dt.getTime())
                ? ''
                : `Updated ${dt.toLocaleTimeString()}`;
        }
    }

    function setLoading() {
        els.loading.classList.remove('d-none');
        els.content.classList.add('d-none');
        els.error.classList.add('d-none');
        els.overall.className = 'badge bg-secondary';
        els.overall.textContent = 'Loading…';
        els.timestamp.textContent = '';
    }

    async function load() {
        setLoading();
        try {
            const data = await Dashboard.apiRequest('/health/system');
            if (!data) throw new Error('empty response');
            render(data);
            els.loading.classList.add('d-none');
            els.content.classList.remove('d-none');
        } catch (err) {
            console.error('[SystemStatus] load failed:', err);
            els.loading.classList.add('d-none');
            els.content.classList.add('d-none');
            els.error.classList.remove('d-none');
            els.overall.className = 'badge bg-danger';
            els.overall.textContent = 'Error';
        }
    }

    modal.addEventListener('show.bs.modal', load);
    if (els.refresh) {
        els.refresh.addEventListener('click', load);
    }
})();
