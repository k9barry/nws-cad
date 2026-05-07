<?php

declare(strict_types=1);

/** @var bool $isMobile */
?>
<div class="row">
    <div class="col-12">
        <h2 class="mb-3"><i class="bi bi-bell"></i> Notifications</h2>
        <p class="text-muted">Read-only view of notification channels and recent send results. Toggle channels via <code>php bin/notifications.php</code>.</p>
        <div id="notifications-channels-container" class="row g-3"></div>
    </div>
</div>

<template id="channel-card-template">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><strong class="channel-name"></strong>
                  <span class="badge bg-secondary channel-type ms-2"></span></span>
                <span class="badge channel-enabled-badge"></span>
            </div>
            <div class="card-body">
                <p class="mb-1"><small>Base URL: <code class="channel-base-url"></code></small></p>
                <p class="channel-error mb-2 text-danger" hidden>
                    <i class="bi bi-exclamation-triangle"></i>
                    <span class="channel-error-time"></span> —
                    <span class="channel-error-message"></span>
                </p>
                <h6 class="mt-3">Recent sends</h6>
                <ul class="list-group list-group-flush channel-log small"></ul>
            </div>
        </div>
    </div>
</template>

<script>
(async function() {
    const apiBase = window.APP_CONFIG.apiBaseUrl;
    const container = document.getElementById('notifications-channels-container');
    const tpl = document.getElementById('channel-card-template');

    const channelsResp = await fetch(`${apiBase}/notifications/channels`).then(r => r.json());
    if (! channelsResp.success || channelsResp.data.items.length === 0) {
        container.innerHTML = '<div class="col-12"><div class="alert alert-info">No channels configured. Run <code>php bin/notifications.php enable ntfy</code> to add one.</div></div>';
        return;
    }

    for (const ch of channelsResp.data.items) {
        const node = tpl.content.cloneNode(true);
        node.querySelector('.channel-name').textContent = ch.name;
        node.querySelector('.channel-type').textContent = ch.type;
        node.querySelector('.channel-base-url').textContent = ch.base_url;

        const flag = node.querySelector('.channel-enabled-badge');
        flag.textContent = ch.enabled ? 'enabled' : 'disabled';
        flag.classList.add(ch.enabled ? 'bg-success' : 'bg-secondary');

        if (ch.last_error_at) {
            const err = node.querySelector('.channel-error');
            err.hidden = false;
            node.querySelector('.channel-error-time').textContent = ch.last_error_at;
            node.querySelector('.channel-error-message').textContent = ch.last_error_message || '';
        }

        const logResp = await fetch(`${apiBase}/notifications/log?channel=${ch.id}&limit=10`).then(r => r.json());
        const logUl = node.querySelector('.channel-log');
        if (logResp.success && logResp.data.items.length) {
            for (const row of logResp.data.items) {
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex justify-content-between';
                li.innerHTML = `<span>${row.ok ? '✓' : '✗'} ${row.created_at} ${row.intent ?? ''} ${row.topic ?? ''}</span>` +
                               `<span class="text-muted">${row.http_status ?? ''} (${row.duration_ms}ms)</span>`;
                logUl.appendChild(li);
            }
        } else {
            const li = document.createElement('li');
            li.className = 'list-group-item text-muted';
            li.textContent = 'no recent sends';
            logUl.appendChild(li);
        }

        container.appendChild(node);
    }
})();
</script>
