<?php

declare(strict_types=1);

/** @var bool $isMobile */
?>
<link rel="stylesheet" href="/assets/css/notifications.css?v=<?= time() ?>">

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

<div class="modal fade" id="outbox-inspector-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          Outbox row <span id="inspector-row-id" class="text-muted"></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="inspector-loading" class="text-center text-muted py-4">
          <div class="spinner-border spinner-border-sm"></div> Loading&hellip;
        </div>
        <div id="inspector-content" hidden>
          <dl class="row small inspector-summary mb-3">
            <dt class="col-4">Status</dt>      <dd class="col-8" id="inspector-status"></dd>
            <dt class="col-4">Channel</dt>     <dd class="col-8" id="inspector-channel"></dd>
            <dt class="col-4">Call</dt>        <dd class="col-8" id="inspector-call"></dd>
            <dt class="col-4">Intent</dt>      <dd class="col-8" id="inspector-intent"></dd>
            <dt class="col-4">Attempts</dt>    <dd class="col-8" id="inspector-attempts"></dd>
            <dt class="col-4">Next attempt</dt><dd class="col-8" id="inspector-next-attempt"></dd>
            <dt class="col-4">Created</dt>     <dd class="col-8" id="inspector-created"></dd>
            <dt class="col-4">Updated</dt>     <dd class="col-8" id="inspector-updated"></dd>
          </dl>

          <div id="inspector-error-wrap" class="mb-3" hidden>
            <h6 class="small text-uppercase text-muted mb-1">Last error</h6>
            <div class="inspector-error" id="inspector-error"></div>
          </div>

          <div id="inspector-schedule-wrap" class="mb-3" hidden>
            <h6 class="small text-uppercase text-muted mb-1">Reschedule retry</h6>
            <div class="d-flex gap-2 align-items-center flex-wrap">
              <input type="datetime-local" class="form-control form-control-sm" id="inspector-schedule-input" step="60">
              <button type="button" class="btn btn-sm btn-primary" id="inspector-schedule-btn">
                <i class="bi bi-clock"></i> Save
              </button>
            </div>
            <div class="schedule-feedback text-muted mt-1" id="inspector-schedule-feedback"></div>
          </div>

          <h6 class="small text-uppercase text-muted mb-1">Send-attempt history</h6>
          <div class="table-responsive">
            <table class="table table-sm history-table mb-0">
              <thead>
                <tr>
                  <th>When</th>
                  <th>OK</th>
                  <th>HTTP</th>
                  <th>Topic</th>
                  <th>Duration</th>
                  <th>Error</th>
                </tr>
              </thead>
              <tbody id="inspector-history-tbody">
              </tbody>
            </table>
          </div>
        </div>
        <div id="inspector-load-error" class="alert alert-danger small" hidden></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php $__cspNonce = htmlspecialchars(\NwsCad\Security\SecurityHeaders::nonce(), ENT_QUOTES); ?>
<script nonce="<?= $__cspNonce ?>">
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

        // Inspector save button — bound once; reads currentInspectId at click time.
        const scheduleBtn = document.getElementById('inspector-schedule-btn');
        if (scheduleBtn) {
            scheduleBtn.addEventListener('click', () => saveSchedule(currentInspectId));
        }

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

        const inspectBtn = document.createElement('button');
        inspectBtn.type = 'button';
        inspectBtn.className = 'btn btn-sm btn-outline-secondary me-1';
        inspectBtn.innerHTML = '<i class="bi bi-eye"></i>';
        inspectBtn.title = 'Inspect this row';
        inspectBtn.addEventListener('click', (ev) => {
            ev.stopPropagation();
            openInspector(row.id);
        });
        td.appendChild(inspectBtn);

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

    /* ---------------- Inspector modal ---------------- */

    let currentInspectId = null;

    async function openInspector(id) {
        currentInspectId = Number(id);
        const modalEl = document.getElementById('outbox-inspector-modal');

        document.getElementById('inspector-row-id').textContent = '#' + id;
        document.getElementById('inspector-loading').hidden = false;
        document.getElementById('inspector-content').hidden = true;
        document.getElementById('inspector-load-error').hidden = true;

        bootstrap.Modal.getOrCreateInstance(modalEl).show();

        try {
            const data = await Dashboard.apiRequest(`/notifications/outbox/${id}`);
            renderInspector(data?.row ?? null, data?.history ?? []);
        } catch (err) {
            document.getElementById('inspector-loading').hidden = true;
            const errEl = document.getElementById('inspector-load-error');
            errEl.hidden = false;
            errEl.textContent = err?.message ?? 'Failed to load row';
        }
    }

    function renderInspector(row, history) {
        document.getElementById('inspector-loading').hidden = true;
        if (!row) {
            const errEl = document.getElementById('inspector-load-error');
            errEl.hidden = false;
            errEl.textContent = 'Row not found.';
            return;
        }
        document.getElementById('inspector-content').hidden = false;

        const statusEl = document.getElementById('inspector-status');
        statusEl.textContent = '';
        const pill = document.createElement('span');
        pill.className = `outbox-status-pill ${row.status}`;
        pill.textContent = row.status;
        statusEl.appendChild(pill);

        document.getElementById('inspector-channel').textContent =
            (row.channel_name ?? `#${row.channel_id}`) + (row.channel_type ? ` (${row.channel_type})` : '');
        document.getElementById('inspector-call').textContent =
            row.call_number ?? `#${row.db_call_id}`;
        document.getElementById('inspector-intent').textContent = row.intent ?? '—';
        document.getElementById('inspector-attempts').textContent = String(row.attempts ?? 0);
        document.getElementById('inspector-next-attempt').textContent =
            row.next_attempt_at ? Dashboard.formatTime(row.next_attempt_at) : '—';
        document.getElementById('inspector-created').textContent =
            row.created_at ? Dashboard.formatTime(row.created_at) : '—';
        document.getElementById('inspector-updated').textContent =
            row.updated_at ? Dashboard.formatTime(row.updated_at) : '—';

        const errWrap = document.getElementById('inspector-error-wrap');
        const errEl = document.getElementById('inspector-error');
        if (row.last_error) {
            errWrap.hidden = false;
            errEl.textContent = row.last_error;
        } else {
            errWrap.hidden = true;
            errEl.textContent = '';
        }

        // Reschedule control: only pending or failed rows can be rescheduled.
        const scheduleWrap = document.getElementById('inspector-schedule-wrap');
        const scheduleInput = document.getElementById('inspector-schedule-input');
        const scheduleFb = document.getElementById('inspector-schedule-feedback');
        scheduleFb.textContent = '';
        scheduleFb.classList.remove('text-success', 'text-danger');
        if (row.status === 'pending' || row.status === 'failed') {
            scheduleWrap.hidden = false;
            scheduleInput.value = toLocalDatetimeInputValue(row.next_attempt_at);
        } else {
            scheduleWrap.hidden = true;
        }

        renderHistory(history);
    }

    function renderHistory(history) {
        const tbody = document.getElementById('inspector-history-tbody');
        tbody.replaceChildren();
        if (!history || history.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 6;
            td.className = 'text-center text-muted py-2 small';
            td.textContent = 'No send attempts recorded.';
            tr.appendChild(td);
            tbody.appendChild(tr);
            return;
        }
        history.forEach((h) => {
            const tr = document.createElement('tr');
            appendHistoryCell(tr, Dashboard.formatTime(h.created_at));
            const okTd = document.createElement('td');
            const okSpan = document.createElement('span');
            const isOk = !!Number(h.ok);
            okSpan.className = 'http-pill ' + (isOk ? 'ok' : 'fail');
            okSpan.textContent = isOk ? '✓' : '✗';
            okTd.appendChild(okSpan);
            tr.appendChild(okTd);
            appendHistoryCell(tr, h.http_status != null ? String(h.http_status) : '—');
            appendHistoryCell(tr, h.topic ?? '—');
            appendHistoryCell(tr, `${h.duration_ms ?? 0} ms`);
            const errTd = document.createElement('td');
            errTd.className = 'history-error';
            if (h.error) {
                errTd.title = h.error;
                errTd.textContent = h.error;
            } else {
                errTd.textContent = '—';
            }
            tr.appendChild(errTd);
            tbody.appendChild(tr);
        });
    }

    function appendHistoryCell(tr, text) {
        const td = document.createElement('td');
        td.textContent = text;
        tr.appendChild(td);
    }

    async function saveSchedule(id) {
        if (id == null) return;
        const input = document.getElementById('inspector-schedule-input');
        const fb = document.getElementById('inspector-schedule-feedback');
        fb.classList.remove('text-success', 'text-danger');

        const raw = (input.value ?? '').trim();
        if (!raw) {
            fb.classList.add('text-danger');
            fb.textContent = 'Pick a date and time.';
            return;
        }
        const when = fromLocalDatetimeInputValue(raw);
        const btn = document.getElementById('inspector-schedule-btn');
        btn.disabled = true;
        try {
            // Dashboard.apiRequest JSON.stringifies options.body itself — pass the object.
            await Dashboard.apiRequest(`/notifications/outbox/${id}/schedule`, {
                method: 'POST',
                body: { next_attempt_at: when },
            });
            fb.classList.add('text-success');
            fb.textContent = `Rescheduled to ${Dashboard.formatTime(when)}.`;
            refresh();
        } catch (err) {
            fb.classList.add('text-danger');
            fb.textContent = err?.message ?? 'Failed to reschedule.';
        } finally {
            btn.disabled = false;
        }
    }

    /**
     * Format a server-supplied datetime (UTC 'Y-m-d H:i:s' or null) as the local
     * 'YYYY-MM-DDTHH:MM' string expected by <input type="datetime-local">.
     * Empty input → default to "now + 1 minute" so the picker isn't blank.
     */
    function toLocalDatetimeInputValue(serverDt) {
        let d;
        if (serverDt) {
            d = new Date(String(serverDt).replace(' ', 'T') + 'Z');
            if (isNaN(d)) d = new Date(Date.now() + 60_000);
        } else {
            d = new Date(Date.now() + 60_000);
        }
        const pad = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }

    /**
     * Convert a 'YYYY-MM-DDTHH:MM' (local) value back to ISO 8601 with the
     * client's offset, which DateTimeImmutable on the server parses correctly.
     */
    function fromLocalDatetimeInputValue(local) {
        const d = new Date(local);
        return isNaN(d) ? local : d.toISOString();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
