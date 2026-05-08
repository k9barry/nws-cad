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
    const apiBase = window.APP_CONFIG.apiBaseUrl;
    const container = document.getElementById('notifications-channels-container');
    const tpl = document.getElementById('channel-card-template');
    const KNOWN = [
        { type: 'ntfy',     name: 'ntfy_primary' },
        { type: 'pushover', name: 'pushover_primary' },
    ];

    const apiCall = (path, options) => {
        if (window.Dashboard && Dashboard.apiRequest) {
            return Dashboard.apiRequest(path.replace(/^\/api/, ''), options);
        }
        const opts = Object.assign({ headers: { 'Accept': 'application/json' } }, options || {});
        return fetch(`${apiBase}${path.startsWith('/api') ? path.slice(4) : path}`, opts).then(r => r.json());
    };

    function setText(node, sel, value) {
        const el = node.querySelector(sel);
        if (el) el.textContent = value ?? '';
    }

    async function fetchAllChannels() {
        const resp = await apiCall('/notifications/channels');
        const byName = {};
        if (resp.success) for (const ch of resp.data.items) byName[ch.name] = ch;
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
            li.className = 'list-group-item d-flex justify-content-between';
            const left = document.createElement('span');
            left.textContent = `${row.ok ? '✓' : '✗'} ${row.created_at ?? ''} ${row.intent ?? ''} ${row.topic ?? ''}`;
            li.appendChild(left);
            const right = document.createElement('span');
            right.className = 'text-muted';
            right.textContent = `${row.http_status ?? ''} (${row.duration_ms ?? 0}ms)`;
            li.appendChild(right);
            ul.appendChild(li);
        }
    }

    function showInlineError(card, message) {
        const div = card.querySelector('.channel-inline-error');
        div.textContent = message;
        div.hidden = false;
        setTimeout(() => { div.hidden = true; div.textContent = ''; }, 8000);
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
            setText(node, '.channel-error-time', ch.last_error_at);
            setText(node, '.channel-error-message', ch.last_error_message || '');
        }

        const ul = node.querySelector('.channel-log');
        if (ch) renderLog(ul, await fetchLog(ch.id));
        else renderLog(ul, []);

        toggle.addEventListener('change', async () => {
            const wantEnable = toggle.checked;
            if (wantEnable) {
                const resp = await apiCall(`/notifications/channels/${known.type}/enable`, { method: 'POST' });
                if (!resp.success) {
                    toggle.checked = false;
                    testBtn.disabled = true;
                    showInlineError(card, resp.error || 'Failed to enable channel');
                    return;
                }
                testBtn.disabled = false;
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
                testBtn.disabled = true;
                await refreshCard(known, card);
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
            card.querySelector('.channel-error-time').textContent = ch.last_error_at;
            card.querySelector('.channel-error-message').textContent = ch.last_error_message || '';
        } else {
            errEl.hidden = true;
        }
        renderLog(card.querySelector('.channel-log'), ch ? await fetchLog(ch.id) : []);
    }

    (async function init() {
        const byName = await fetchAllChannels();
        for (const known of KNOWN) {
            await renderCard(known, byName);
        }
    })();
})();
</script>
