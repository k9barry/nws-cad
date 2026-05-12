<?php

declare(strict_types=1);

/** @var bool $isMobile */
?>
<style>
/* === Notifications page polish (banner/live-pill come from dashboard.css) === */

/* Lock the notifications page to exactly one viewport tall so the
   channel cards fill all available space without overflowing.
   - min-height: 100vh on body alone wasn't enough: it's only a floor,
     so the cards' natural content (10 log entries each) pushed body
     past 100vh and forced a scrollbar.
   - The footer's page-default .mt-5 and main's .py-4 bottom padding
     use !important (Bootstrap utilities), so the overrides do too. */
@media (min-width: 768px) {
    body:has(#notifications-channels-container) {
        height: 100vh;
        overflow: hidden;
    }
}
body:has(#notifications-channels-container) {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}
body:has(#notifications-channels-container) > main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
    padding-bottom: 0 !important;
}
body:has(#notifications-channels-container) > footer {
    margin-top: 0 !important;
}
#notifications-channels-container {
    flex: 1;
    align-items: stretch;
    min-height: 0;
}
/* Bootstrap rows are flex-wrap: wrap; with a single line, the wrap'd line's
   cross-axis size collapses to its tallest item rather than stretching to
   the row's full height (per CSS Align spec). Switch to nowrap above sm
   where the two cols sit side-by-side so .channel-card can stretch. */
@media (min-width: 768px) {
    #notifications-channels-container { flex-wrap: nowrap; }
}
#notifications-channels-container > [class*="col-"] {
    display: flex;
}

/* Channel cards — gradient header strip mirrors the dashboard's stat cards */
.channel-card {
    border: none;
    border-radius: 0.75rem;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.channel-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
}
.channel-card .card-header {
    border-bottom: none;
    padding: 0.65rem 0.95rem;
    color: #fff;
    background: linear-gradient(135deg, #475569, #1e293b);
}
.channel-card .card-body {
    padding: 0.75rem 0.95rem;
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
}
.channel-card[data-type="ntfy"] .card-header {
    background: linear-gradient(135deg, #2563eb, #4f46e5);
}
.channel-card[data-type="pushover"] .card-header {
    background: linear-gradient(135deg, #db2777, #c026d3);
}
.channel-card .channel-type {
    background: rgba(255,255,255,0.25) !important;
    color: #fff;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-size: 0.65rem;
}
.channel-card .form-check-input {
    cursor: pointer;
}
.channel-card.is-disabled .card-header {
    background: linear-gradient(135deg, #94a3b8, #64748b);
}

.channel-state-badge {
    display: inline-block;
    padding: 0.2rem 0.55rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.channel-state-badge.is-on  { background: #d1fae5; color: #065f46; }
.channel-state-badge.is-off { background: #e2e8f0; color: #475569; }

/* Log entries — flex-grow inside the card body and scroll internally */
.channel-log {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
}
.channel-log .list-group-item {
    border-left: 4px solid transparent;
    padding: 0.5rem 0.65rem;
    transition: background 0.15s ease;
}
.channel-log .list-group-item.row-success {
    border-left-color: #10b981;
    background: linear-gradient(90deg, #ecfdf5, transparent 60%);
}
.channel-log .list-group-item.row-failed {
    border-left-color: #ef4444;
    background: linear-gradient(90deg, #fef2f2, transparent 60%);
}
.channel-log .list-group-item.row-new {
    animation: notif-flash 1.2s ease;
}
@keyframes notif-flash {
    0%   { background-color: #fef9c3; }
    100% { background-color: transparent; }
}
.channel-log .status-icon {
    width: 1.4rem; height: 1.4rem;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.75rem;
    color: #fff;
    flex-shrink: 0;
}
.channel-log .status-icon.ok   { background: #10b981; }
.channel-log .status-icon.fail { background: #ef4444; }
.channel-log .http-pill {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.7rem;
    padding: 0.15rem 0.5rem;
    border-radius: 0.35rem;
    background: #f1f5f9;
    color: #475569;
}
.channel-log .http-pill.ok   { background: #d1fae5; color: #065f46; }
.channel-log .http-pill.fail { background: #fee2e2; color: #991b1b; }

.channel-log .dismiss-btn {
    border: none;
    background: transparent;
    color: #94a3b8;
    padding: 0.15rem 0.4rem;
    border-radius: 0.35rem;
    line-height: 1;
}
.channel-log .dismiss-btn:hover { color: #ef4444; background: #fee2e2; }

.channel-error {
    background: #fef2f2;
    border-left: 3px solid #ef4444;
    border-radius: 0.35rem;
    padding: 0.5rem 0.75rem;
    color: #991b1b;
}

.clear-failed-btn { font-size: 0.75rem; }

/* Outbox queue card */
#outbox-queue-card {
    margin-top: 1rem;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
}
#outbox-queue-card .card-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}
#outbox-queue-table {
    font-size: 0.85rem;
}
#outbox-queue-table th {
    font-weight: 600;
    color: #475569;
    text-transform: uppercase;
    font-size: 0.7rem;
    letter-spacing: 0.04em;
}
#outbox-queue-table td.error-cell {
    max-width: 24em;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-size: 0.75rem;
    color: #991b1b;
}
.outbox-status-pill {
    display: inline-block;
    font-size: 0.7rem;
    padding: 0.15rem 0.5rem;
    border-radius: 999px;
    font-weight: 600;
    text-transform: uppercase;
}
.outbox-status-pill.pending   { background: #fef3c7; color: #92400e; }
.outbox-status-pill.in_flight { background: #dbeafe; color: #1e40af; }
.outbox-status-pill.done      { background: #dcfce7; color: #166534; }
.outbox-status-pill.failed    { background: #fee2e2; color: #991b1b; }
</style>

<div class="dashboard-banner d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h2><i class="bi bi-bell-fill"></i> Notifications</h2>
        <div class="subtitle">Live channel status &middot; updates every <span id="notif-poll-secs">5</span>s</div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="live-pill" id="notif-live-pill">
            <span class="dot"></span>
            <span id="notif-live-text">Live</span>
        </span>
        <button type="button" class="btn btn-sm btn-light" id="notif-pause-btn" title="Pause/resume live updates">
            <i class="bi bi-pause-fill" id="notif-pause-icon"></i>
        </button>
    </div>
</div>

<p class="text-muted small mb-3">
    Manage notification channels. Add new channel types with <code>php bin/notifications.php enable &lt;type&gt;</code>.
</p>

<div id="notifications-channels-container" class="row g-3"></div>

<div id="outbox-queue-card" class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <strong><i class="bi bi-inbox"></i> Outbox queue</strong>
            <div class="btn-group btn-group-sm" role="group" aria-label="Filter by status" id="outbox-status-tabs">
                <button type="button" class="btn btn-outline-secondary active" data-status="pending">Pending</button>
                <button type="button" class="btn btn-outline-secondary" data-status="in_flight">In flight</button>
                <button type="button" class="btn btn-outline-secondary" data-status="failed">Failed</button>
                <button type="button" class="btn btn-outline-secondary" data-status="done">Done</button>
                <button type="button" class="btn btn-outline-secondary" data-status="all">All</button>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span id="outbox-row-count" class="small text-muted"></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="outbox-refresh-btn" title="Refresh">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger" id="outbox-clear-btn" hidden>
                <i class="bi bi-trash"></i> Clear all <span id="outbox-clear-status-label"></span>
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="outbox-queue-table" class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Status</th>
                        <th>Channel</th>
                        <th>Call</th>
                        <th>Intent</th>
                        <th>Attempts</th>
                        <th>Next attempt</th>
                        <th>Last error</th>
                        <th>Updated</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="outbox-queue-tbody">
                    <tr><td colspan="10" class="text-center text-muted py-3 small">Loading&hellip;</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<template id="channel-card-template">
    <div class="col-md-6">
        <div class="card channel-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <strong class="channel-name"></strong>
                    <span class="badge channel-type ms-2"></span>
                </span>
                <div class="form-check form-switch m-0">
                    <input class="form-check-input channel-toggle" type="checkbox" role="switch">
                </div>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted">
                        <i class="bi bi-link-45deg"></i>
                        <code class="channel-base-url"></code>
                    </small>
                    <span class="channel-state-badge is-off">off</span>
                </div>
                <p class="channel-status mb-1 small text-muted"></p>
                <div class="channel-error small mb-2" hidden>
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <span class="channel-error-time fw-semibold"></span>
                            <span class="channel-error-message"></span>
                        </div>
                        <button type="button" class="dismiss-btn channel-error-dismiss" title="Dismiss this error">
                            <i class="bi bi-x-circle"></i>
                        </button>
                    </div>
                </div>
                <div class="channel-inline-error alert alert-warning small py-2 mb-2" hidden></div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <button type="button" class="btn btn-sm btn-outline-primary channel-test-btn" disabled>
                        <i class="bi bi-send"></i> Send test
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger clear-failed-btn" hidden>
                        <i class="bi bi-trash"></i> Clear failed (<span class="failed-count">0</span>)
                    </button>
                </div>
                <h6 class="mt-3 mb-2 small text-uppercase text-muted">
                    <i class="bi bi-clock-history"></i> Recent sends
                </h6>
                <ul class="list-group list-group-flush channel-log small"></ul>
            </div>
        </div>
    </div>
</template>

<div class="modal fade" id="disable-confirm-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Disable channel?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">Disable <strong id="disable-confirm-name"></strong>? Notifications will stop firing for this channel until it is re-enabled.</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="disable-confirm-btn">Disable</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="test-result-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Test send result</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p><span id="test-result-icon"></span> <strong id="test-result-summary"></strong></p>
        <dl class="row small mb-0">
          <dt class="col-4">HTTP status</dt><dd class="col-8" id="test-result-status"></dd>
          <dt class="col-4">Duration</dt><dd class="col-8" id="test-result-duration"></dd>
          <dt class="col-4">Topic</dt><dd class="col-8" id="test-result-topic"></dd>
          <dt class="col-4">Error</dt><dd class="col-8" id="test-result-error"></dd>
        </dl>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
(function() {
    const POLL_INTERVAL_MS = 5000;
    const KNOWN = [
        { type: 'ntfy',     name: 'ntfy_primary' },
        { type: 'pushover', name: 'pushover_primary' },
    ];

    function start() {
    const apiBase = window.APP_CONFIG?.apiBaseUrl ?? '/api';
    const container = document.getElementById('notifications-channels-container');
    const tpl = document.getElementById('channel-card-template');
    const livePill = document.getElementById('notif-live-pill');
    const liveText = document.getElementById('notif-live-text');
    const pauseBtn = document.getElementById('notif-pause-btn');
    const pauseIcon = document.getElementById('notif-pause-icon');
    document.getElementById('notif-poll-secs').textContent = String(Math.round(POLL_INTERVAL_MS / 1000));

    // Map of channel name → {card, ch, knownTypes:Set<number>}
    const cards = new Map();
    let pollTimer = null;
    let paused = false;
    let inFlight = false;

    const apiCall = (path, options) => {
        const opts = Object.assign({ headers: { 'Accept': 'application/json' } }, options || {});
        return fetch(`${apiBase}${path}`, opts).then(r => r.json());
    };

    function setText(node, sel, value) {
        const el = node.querySelector(sel);
        if (el) el.textContent = value ?? '';
    }

    function fmtSentAtLocal(s) {
        if (!s) return '';
        const d = new Date(s.replace(' ', 'T') + 'Z');
        return isNaN(d) ? String(s) : d.toLocaleString();
    }

    function summarizeRow(row) {
        const parts = [];
        if (row.call_number) parts.push(`#${row.call_number}`);
        if (row.call_type)   parts.push(row.call_type);
        const intent = row.intent ?? '';
        const topic  = row.topic ? `→ ${row.topic}` : '';
        if (intent || topic) parts.push(`${intent} ${topic}`.trim());
        return parts.join(' · ');
    }

    function detailLine(row) {
        const bits = [fmtSentAtLocal(row.created_at)];
        const where = row.full_address || row.common_name;
        if (where) bits.push(where);
        if (row.nature_of_call) bits.push(row.nature_of_call);
        return bits.filter(Boolean).join(' · ');
    }

    async function fetchAllChannels() {
        const resp = await apiCall('/notifications/channels');
        const byName = {};
        if (resp.success && resp.data && Array.isArray(resp.data.items)) {
            for (const ch of resp.data.items) byName[ch.name] = ch;
        }
        return byName;
    }

    async function fetchLog(channelId) {
        const resp = await apiCall(`/notifications/log?channel=${encodeURIComponent(channelId)}&limit=10`);
        return (resp.success && resp.data.items) ? resp.data.items : [];
    }

    function renderLog(ul, items, knownIds, onDismiss) {
        const seen = new Set();
        let failedCount = 0;
        ul.replaceChildren();
        if (!items.length) {
            const li = document.createElement('li');
            li.className = 'list-group-item text-muted text-center py-3';
            li.textContent = 'no recent sends';
            ul.appendChild(li);
            return { knownIds: new Set(), failedCount: 0 };
        }
        for (const row of items) {
            const isOk = !!Number(row.ok);
            const isNew = knownIds && !knownIds.has(row.id);
            seen.add(row.id);
            if (!isOk) failedCount++;

            const li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-start ' +
                (isOk ? 'row-success' : 'row-failed') + (isNew ? ' row-new' : '');

            const left = document.createElement('div');
            left.className = 'd-flex align-items-start gap-2 me-2 flex-grow-1';

            const icon = document.createElement('span');
            icon.className = 'status-icon ' + (isOk ? 'ok' : 'fail');
            icon.innerHTML = isOk
                ? '<i class="bi bi-check-lg"></i>'
                : '<i class="bi bi-x-lg"></i>';
            left.appendChild(icon);

            const text = document.createElement('div');
            const main = document.createElement('div');
            main.className = 'fw-semibold';
            main.textContent = summarizeRow(row) || '(no details)';
            text.appendChild(main);

            const sub = document.createElement('div');
            sub.className = 'text-muted small';
            sub.textContent = detailLine(row);
            text.appendChild(sub);

            if (!isOk && row.error) {
                const errLine = document.createElement('div');
                errLine.className = 'small text-danger mt-1';
                errLine.innerHTML = '<i class="bi bi-exclamation-circle"></i> ';
                errLine.appendChild(document.createTextNode(String(row.error).slice(0, 200)));
                text.appendChild(errLine);
            }

            left.appendChild(text);
            li.appendChild(left);

            const right = document.createElement('div');
            right.className = 'd-flex align-items-center gap-2 text-nowrap';

            const pill = document.createElement('span');
            pill.className = 'http-pill ' + (isOk ? 'ok' : 'fail');
            pill.textContent = `${row.http_status ?? '—'} · ${row.duration_ms ?? 0}ms`;
            right.appendChild(pill);

            const dismiss = document.createElement('button');
            dismiss.type = 'button';
            dismiss.className = 'dismiss-btn';
            dismiss.title = 'Dismiss this entry';
            dismiss.innerHTML = '<i class="bi bi-x-circle"></i>';
            dismiss.addEventListener('click', () => onDismiss(row.id, li));
            right.appendChild(dismiss);

            li.appendChild(right);
            ul.appendChild(li);
        }
        return { knownIds: seen, failedCount };
    }

    function showInlineError(card, message) {
        const div = card.querySelector('.channel-inline-error');
        if (div._hideTimer) clearTimeout(div._hideTimer);
        div.textContent = message;
        div.hidden = false;
        div._hideTimer = setTimeout(() => {
            div.hidden = true;
            div.textContent = '';
            div._hideTimer = null;
        }, 8000);
    }

    function showTestResult(payload) {
        const icon = document.getElementById('test-result-icon');
        const summary = document.getElementById('test-result-summary');
        if (payload.ok) {
            icon.textContent = '✓';
            icon.className = 'text-success fs-4';
            summary.textContent = 'Success';
        } else {
            icon.textContent = '✗';
            icon.className = 'text-danger fs-4';
            summary.textContent = 'Failed';
        }
        document.getElementById('test-result-status').textContent   = payload.http_status ?? '—';
        document.getElementById('test-result-duration').textContent = `${payload.duration_ms ?? 0} ms`;
        document.getElementById('test-result-topic').textContent    = payload.topic ?? '—';
        document.getElementById('test-result-error').textContent    = payload.error ?? '—';

        const modalEl = document.getElementById('test-result-modal');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function confirmDisable(name) {
        return new Promise((resolve) => {
            const modalEl = document.getElementById('disable-confirm-modal');
            document.getElementById('disable-confirm-name').textContent = name;
            const btn = document.getElementById('disable-confirm-btn');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            const onConfirm = () => { cleanup(); modal.hide(); resolve(true); };
            const onHide = () => { cleanup(); resolve(false); };
            const cleanup = () => {
                btn.removeEventListener('click', onConfirm);
                modalEl.removeEventListener('hidden.bs.modal', onHide);
            };
            btn.addEventListener('click', onConfirm, { once: true });
            modalEl.addEventListener('hidden.bs.modal', onHide, { once: true });
            modal.show();
        });
    }

    async function dismissEntry(known, logId, liEl) {
        const card = cards.get(known.name)?.card;
        try {
            const resp = await apiCall(`/notifications/log/${logId}`, { method: 'DELETE' });
            if (!resp.success) {
                if (card) showInlineError(card, resp.error || 'Failed to dismiss entry');
                return;
            }
            liEl.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
            liEl.style.opacity = '0';
            liEl.style.transform = 'translateX(20px)';
            setTimeout(() => refreshCard(known), 260);
        } catch (e) {
            if (card) showInlineError(card, 'Network error dismissing entry');
        }
    }

    async function clearChannelErrorBanner(known) {
        const card = cards.get(known.name)?.card;
        try {
            const resp = await apiCall(
                `/notifications/channels/${known.type}/clear-error`,
                { method: 'POST' }
            );
            if (!resp.success) {
                if (card) showInlineError(card, resp.error || 'Failed to clear channel error');
                return;
            }
            await refreshCard(known);
        } catch (e) {
            if (card) showInlineError(card, 'Network error clearing channel error');
        }
    }

    async function clearAllFailed(known) {
        const card = cards.get(known.name)?.card;
        try {
            const resp = await apiCall(
                `/notifications/log/clear-failed?channel=${encodeURIComponent(known.name)}`,
                { method: 'POST' }
            );
            if (!resp.success) {
                if (card) showInlineError(card, resp.error || 'Failed to clear failed entries');
                return;
            }
            await refreshCard(known);
        } catch (e) {
            if (card) showInlineError(card, 'Network error clearing entries');
        }
    }

    function applyChannelState(card, known, ch) {
        const enabled = !!(ch && Number(ch.enabled));
        card.dataset.type = known.type;
        card.classList.toggle('is-disabled', !enabled);

        card.querySelector('.channel-base-url').textContent = ch ? ch.base_url : '(not configured)';
        card.querySelector('.channel-status').textContent   = ch ? '' : 'Not yet configured. Enable to create.';
        const stateBadge = card.querySelector('.channel-state-badge');
        stateBadge.textContent = enabled ? 'on' : 'off';
        stateBadge.classList.toggle('is-on', enabled);
        stateBadge.classList.toggle('is-off', !enabled);

        const toggle = card.querySelector('.channel-toggle');
        const testBtn = card.querySelector('.channel-test-btn');
        toggle.checked = enabled;
        testBtn.disabled = !enabled;

        const errEl = card.querySelector('.channel-error');
        const errTimeEl = card.querySelector('.channel-error-time');
        const errMsgEl = card.querySelector('.channel-error-message');
        if (ch && ch.last_error_at) {
            errEl.hidden = false;
            errTimeEl.textContent = fmtSentAtLocal(ch.last_error_at) + ' — ';
            errMsgEl.textContent = ch.last_error_message || '';
        } else {
            errEl.hidden = true;
            errTimeEl.textContent = '';
            errMsgEl.textContent = '';
        }
    }

    async function refreshCard(known) {
        const entry = cards.get(known.name);
        if (!entry) return;
        const byName = await fetchAllChannels();
        const ch = byName[known.name] || null;
        entry.ch = ch;
        applyChannelState(entry.card, known, ch);
        const items = ch ? await fetchLog(ch.id) : [];
        const result = renderLog(
            entry.card.querySelector('.channel-log'),
            items,
            entry.knownIds,
            (id, liEl) => dismissEntry(known, id, liEl)
        );
        entry.knownIds = result.knownIds;

        const clearBtn = entry.card.querySelector('.clear-failed-btn');
        const failedCountEl = entry.card.querySelector('.failed-count');
        failedCountEl.textContent = String(result.failedCount);
        clearBtn.hidden = result.failedCount === 0;
    }

    async function buildCard(known, byName) {
        const node = tpl.content.cloneNode(true);
        const card = node.querySelector('.channel-card');
        const ch = byName[known.name] || null;

        setText(node, '.channel-name', known.name);
        setText(node, '.channel-type', known.type);
        applyChannelState(card, known, ch);

        const toggle = card.querySelector('.channel-toggle');
        const testBtn = card.querySelector('.channel-test-btn');
        const clearBtn = card.querySelector('.clear-failed-btn');

        const items = ch ? await fetchLog(ch.id) : [];
        const result = renderLog(
            card.querySelector('.channel-log'),
            items,
            null,
            (id, liEl) => dismissEntry(known, id, liEl)
        );
        const failedCountEl = card.querySelector('.failed-count');
        failedCountEl.textContent = String(result.failedCount);
        clearBtn.hidden = result.failedCount === 0;

        toggle.addEventListener('change', async () => {
            toggle.disabled = true;
            try {
                const wantEnable = toggle.checked;
                if (wantEnable) {
                    const resp = await apiCall(`/notifications/channels/${known.type}/enable`, { method: 'POST' });
                    if (!resp.success) {
                        toggle.checked = false;
                        showInlineError(card, resp.error || 'Failed to enable channel');
                        return;
                    }
                } else {
                    const ok = await confirmDisable(known.name);
                    if (!ok) {
                        toggle.checked = true;
                        return;
                    }
                    const resp = await apiCall(`/notifications/channels/${known.type}/disable`, { method: 'POST' });
                    if (!resp.success) {
                        toggle.checked = true;
                        showInlineError(card, resp.error || 'Failed to disable channel');
                        return;
                    }
                }
                await refreshCard(known);
            } finally {
                toggle.disabled = false;
                testBtn.disabled = !toggle.checked;
            }
        });

        testBtn.addEventListener('click', async () => {
            testBtn.disabled = true;
            try {
                const resp = await apiCall(`/notifications/channels/${known.type}/test`, { method: 'POST' });
                if (!resp.success) {
                    showTestResult({ ok: false, error: resp.error });
                } else {
                    showTestResult(resp.data);
                }
                await refreshCard(known);
            } finally {
                testBtn.disabled = !toggle.checked;
            }
        });

        clearBtn.addEventListener('click', async () => {
            clearBtn.disabled = true;
            try {
                await clearAllFailed(known);
            } finally {
                clearBtn.disabled = false;
            }
        });

        const errorDismissBtn = card.querySelector('.channel-error-dismiss');
        errorDismissBtn.addEventListener('click', async () => {
            errorDismissBtn.disabled = true;
            try {
                await clearChannelErrorBanner(known);
            } finally {
                errorDismissBtn.disabled = false;
            }
        });

        cards.set(known.name, { card, ch, knownIds: result.knownIds });
        container.appendChild(node);
    }

    function setLiveStatus(state) {
        livePill.classList.remove('is-paused', 'is-error');
        if (state === 'paused') {
            livePill.classList.add('is-paused');
            liveText.textContent = 'Paused';
        } else if (state === 'error') {
            livePill.classList.add('is-error');
            liveText.textContent = 'Connection error';
        } else {
            liveText.textContent = 'Live';
        }
    }

    async function pollOnce() {
        if (paused || inFlight || document.hidden) return;
        inFlight = true;
        try {
            await Promise.all(KNOWN.map(k => refreshCard(k)));
            setLiveStatus('live');
        } catch (e) {
            setLiveStatus('error');
        } finally {
            inFlight = false;
        }
    }

    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(pollOnce, POLL_INTERVAL_MS);
    }

    pauseBtn.addEventListener('click', () => {
        paused = !paused;
        if (paused) {
            pauseIcon.className = 'bi bi-play-fill';
            setLiveStatus('paused');
        } else {
            pauseIcon.className = 'bi bi-pause-fill';
            setLiveStatus('live');
            pollOnce();
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && !paused) pollOnce();
    });

    (async function init() {
        try {
            const byName = await fetchAllChannels();
            for (const known of KNOWN) {
                await buildCard(known, byName);
            }
            setLiveStatus('live');
            startPolling();
        } catch (e) {
            const div = document.createElement('div');
            div.className = 'col-12';
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger';
            alert.textContent = 'Failed to load notification channels. Check the API.';
            div.appendChild(alert);
            container.replaceChildren(div);
            setLiveStatus('error');
        }
    })();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();

/* === Outbox queue card === */
(function () {
    const apiBase = window.APP_CONFIG?.apiBaseUrl ?? '/api';
    let currentStatus = 'pending';

    function init() {
        const card = document.getElementById('outbox-queue-card');
        if (!card) return;

        card.querySelectorAll('#outbox-status-tabs button').forEach((btn) => {
            btn.addEventListener('click', () => {
                card.querySelectorAll('#outbox-status-tabs button').forEach((b) => b.classList.remove('active'));
                btn.classList.add('active');
                currentStatus = btn.dataset.status;
                refresh();
            });
        });
        document.getElementById('outbox-refresh-btn').addEventListener('click', refresh);
        document.getElementById('outbox-clear-btn').addEventListener('click', clearAll);

        refresh();
    }

    async function refresh() {
        const tbody = document.getElementById('outbox-queue-tbody');
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-3 small">Loading&hellip;</td></tr>';

        try {
            const data = await Dashboard.apiRequest(`/notifications/outbox?status=${encodeURIComponent(currentStatus)}&limit=100`);
            const items = data?.items ?? [];
            renderRows(items);
            updateClearButton();
            document.getElementById('outbox-row-count').textContent = items.length === 0 ? '' : `${items.length} row${items.length === 1 ? '' : 's'}`;
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="10" class="text-center text-danger small py-3">${Dashboard.escapeHtml(err?.message ?? 'Failed to load outbox')}</td></tr>`;
        }
    }

    function renderRows(items) {
        const tbody = document.getElementById('outbox-queue-tbody');
        tbody.replaceChildren();

        if (items.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 10;
            td.className = 'text-center text-muted py-3 small';
            td.textContent = 'No rows';
            tr.appendChild(td);
            tbody.appendChild(tr);
            return;
        }

        items.forEach((row) => tbody.appendChild(buildRow(row)));
    }

    function buildRow(row) {
        const tr = document.createElement('tr');
        tr.dataset.id = row.id;

        appendText(tr, '#' + row.id);
        appendStatus(tr, row.status);
        appendText(tr, row.channel_name ?? `#${row.channel_id}`);
        appendText(tr, row.call_number ?? `#${row.db_call_id}`);
        appendText(tr, row.intent);
        appendText(tr, String(row.attempts));
        appendText(tr, row.next_attempt_at ? Dashboard.formatTime(row.next_attempt_at) : '—');
        appendError(tr, row.last_error);
        appendText(tr, Dashboard.formatTime(row.updated_at));
        appendActions(tr, row);

        return tr;
    }

    function appendText(tr, text) {
        const td = document.createElement('td');
        td.textContent = text;
        tr.appendChild(td);
    }

    function appendStatus(tr, status) {
        const td = document.createElement('td');
        const span = document.createElement('span');
        span.className = `outbox-status-pill ${status}`;
        span.textContent = status;
        td.appendChild(span);
        tr.appendChild(td);
    }

    function appendError(tr, err) {
        const td = document.createElement('td');
        td.className = 'error-cell';
        if (err) {
            td.title = err;
            td.textContent = err;
        } else {
            td.textContent = '—';
        }
        tr.appendChild(td);
    }

    function appendActions(tr, row) {
        const td = document.createElement('td');
        td.className = 'text-end';

        if (row.status === 'failed') {
            const retryBtn = document.createElement('button');
            retryBtn.type = 'button';
            retryBtn.className = 'btn btn-sm btn-outline-primary me-1';
            retryBtn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Retry';
            retryBtn.addEventListener('click', () => retryRow(row.id));
            td.appendChild(retryBtn);
        }

        const dismissBtn = document.createElement('button');
        dismissBtn.type = 'button';
        dismissBtn.className = 'btn btn-sm btn-outline-secondary';
        dismissBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
        dismissBtn.title = 'Dismiss this row';
        dismissBtn.addEventListener('click', () => dismissRow(row.id));
        td.appendChild(dismissBtn);

        tr.appendChild(td);
    }

    async function retryRow(id) {
        try {
            await Dashboard.apiRequest(`/notifications/outbox/${id}/retry`, { method: 'POST' });
            refresh();
        } catch (err) {
            alert('Retry failed: ' + (err?.message ?? 'unknown'));
        }
    }

    async function dismissRow(id) {
        if (!confirm(`Dismiss outbox row #${id}?`)) return;
        try {
            await Dashboard.apiRequest(`/notifications/outbox/${id}`, { method: 'DELETE' });
            refresh();
        } catch (err) {
            alert('Dismiss failed: ' + (err?.message ?? 'unknown'));
        }
    }

    function updateClearButton() {
        const btn = document.getElementById('outbox-clear-btn');
        const label = document.getElementById('outbox-clear-status-label');
        if (currentStatus === 'done' || currentStatus === 'failed') {
            label.textContent = currentStatus;
            btn.hidden = false;
        } else {
            btn.hidden = true;
        }
    }

    async function clearAll() {
        if (currentStatus !== 'done' && currentStatus !== 'failed') return;
        if (!confirm(`Clear all ${currentStatus} outbox rows?`)) return;
        try {
            const data = await Dashboard.apiRequest(
                `/notifications/outbox/clear?status=${encodeURIComponent(currentStatus)}`,
                { method: 'POST' },
            );
            const deleted = data?.deleted ?? 0;
            alert(`${deleted} row${deleted === 1 ? '' : 's'} deleted.`);
            refresh();
        } catch (err) {
            alert('Clear failed: ' + (err?.message ?? 'unknown'));
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
