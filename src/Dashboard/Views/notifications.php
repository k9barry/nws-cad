<?php

declare(strict_types=1);

/** @var bool $isMobile */
?>
<div class="row">
    <div class="col-12">
        <h2 class="mb-3"><i class="bi bi-bell"></i> Notifications</h2>
        <p class="text-muted">Manage notification channels. Add new channel types with <code>php bin/notifications.php enable &lt;type&gt;</code>.</p>
        <div id="notifications-channels-container" class="row g-3"></div>
    </div>
</div>

<template id="channel-card-template">
    <div class="col-md-6">
        <div class="card channel-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><strong class="channel-name"></strong>
                  <span class="badge bg-secondary channel-type ms-2"></span></span>
                <div class="form-check form-switch m-0">
                    <input class="form-check-input channel-toggle" type="checkbox" role="switch">
                </div>
            </div>
            <div class="card-body">
                <p class="mb-1"><small>Base URL: <code class="channel-base-url"></code></small></p>
                <p class="channel-status mb-1 small text-muted"></p>
                <p class="channel-error mb-2 text-danger" hidden>
                    <i class="bi bi-exclamation-triangle"></i>
                    <span class="channel-error-time"></span> —
                    <span class="channel-error-message"></span>
                </p>
                <div class="channel-inline-error alert alert-warning small py-2 mb-2" hidden></div>
                <button type="button" class="btn btn-sm btn-outline-primary channel-test-btn" disabled>
                    <i class="bi bi-send"></i> Send test
                </button>
                <h6 class="mt-3">Recent sends</h6>
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
    function start() {
    const apiBase = window.APP_CONFIG?.apiBaseUrl ?? '/api';
    const container = document.getElementById('notifications-channels-container');
    const tpl = document.getElementById('channel-card-template');
    const KNOWN = [
        { type: 'ntfy',     name: 'ntfy_primary' },
        { type: 'pushover', name: 'pushover_primary' },
    ];

    const apiCall = (path, options) => {
        const opts = Object.assign({ headers: { 'Accept': 'application/json' } }, options || {});
        return fetch(`${apiBase}${path}`, opts).then(r => r.json());
    };

    function setText(node, sel, value) {
        const el = node.querySelector(sel);
        if (el) el.textContent = value ?? '';
    }

    // DB returns "YYYY-MM-DD HH:MM:SS" with no offset; MySQL is UTC. Without
    // explicit Z, JS would parse as local and display the UTC digits as-is.
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

    function renderLog(ul, items) {
        ul.replaceChildren();
        if (!items.length) {
            const li = document.createElement('li');
            li.className = 'list-group-item text-muted';
            li.textContent = 'no recent sends';
            ul.appendChild(li);
            return;
        }
        for (const row of items) {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-start';

            const left = document.createElement('div');
            left.className = 'me-2';

            const main = document.createElement('div');
            main.textContent = `${row.ok ? '✓' : '✗'} ${summarizeRow(row)}`.trim();
            left.appendChild(main);

            const sub = document.createElement('div');
            sub.className = 'text-muted small';
            sub.textContent = detailLine(row);
            left.appendChild(sub);

            li.appendChild(left);

            const right = document.createElement('span');
            right.className = 'text-muted text-nowrap';
            right.textContent = `${row.http_status ?? ''} (${row.duration_ms ?? 0}ms)`;
            li.appendChild(right);

            ul.appendChild(li);
        }
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

    async function renderCard(known, byName) {
        const node = tpl.content.cloneNode(true);
        const card = node.querySelector('.channel-card');
        const ch = byName[known.name] || null;

        setText(node, '.channel-name', known.name);
        setText(node, '.channel-type', known.type);
        setText(node, '.channel-base-url', ch ? ch.base_url : '(not configured)');
        setText(node, '.channel-status', ch ? '' : 'Not yet configured. Enable to create.');

        const toggle = node.querySelector('.channel-toggle');
        const testBtn = node.querySelector('.channel-test-btn');
        toggle.checked = !!(ch && Number(ch.enabled));
        testBtn.disabled = !toggle.checked;

        if (ch && ch.last_error_at) {
            const err = node.querySelector('.channel-error');
            err.hidden = false;
            setText(node, '.channel-error-time', fmtSentAtLocal(ch.last_error_at));
            setText(node, '.channel-error-message', ch.last_error_message || '');
        }

        const ul = node.querySelector('.channel-log');
        if (ch) renderLog(ul, await fetchLog(ch.id));
        else renderLog(ul, []);

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
                    await refreshCard(known, card);
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
                    await refreshCard(known, card);
                }
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
                await refreshCard(known, card);
            } finally {
                testBtn.disabled = !toggle.checked;
            }
        });

        container.appendChild(node);
    }

    async function refreshCard(known, card) {
        const byName = await fetchAllChannels();
        const ch = byName[known.name] || null;
        card.querySelector('.channel-base-url').textContent = ch ? ch.base_url : '(not configured)';
        card.querySelector('.channel-status').textContent   = ch ? '' : 'Not yet configured. Enable to create.';
        const toggle = card.querySelector('.channel-toggle');
        const testBtn = card.querySelector('.channel-test-btn');
        toggle.checked = !!(ch && Number(ch.enabled));
        testBtn.disabled = !toggle.checked;
        const errEl = card.querySelector('.channel-error');
        if (ch && ch.last_error_at) {
            errEl.hidden = false;
            card.querySelector('.channel-error-time').textContent = fmtSentAtLocal(ch.last_error_at);
            card.querySelector('.channel-error-message').textContent = ch.last_error_message || '';
        } else {
            errEl.hidden = true;
        }
        renderLog(card.querySelector('.channel-log'), ch ? await fetchLog(ch.id) : []);
    }

    (async function init() {
        try {
            const byName = await fetchAllChannels();
            for (const known of KNOWN) {
                await renderCard(known, byName);
            }
        } catch (e) {
            const div = document.createElement('div');
            div.className = 'col-12';
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger';
            alert.textContent = 'Failed to load notification channels. Check the API.';
            div.appendChild(alert);
            container.replaceChildren(div);
        }
    })();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
</script>
