(function () {
  'use strict';

  async function load(qs) {
    const resp = await fetch('/api/calls' + (qs || ''));
    if (!resp.ok) { console.error('calls fetch failed'); return; }
    const body = await resp.json();
    render(body.data);
  }

  function render(data) {
    const list = document.getElementById('calls-list');
    list.innerHTML = '';
    (data.items || []).forEach(function (c) {
      const div = document.createElement('div');
      div.className = 'call-item card mb-2';
      const body = document.createElement('div');
      body.className = 'card-body';
      const num = document.createElement('strong');
      num.textContent = c.call_number || '';
      body.appendChild(num);
      const sep = document.createTextNode(' — ');
      body.appendChild(sep);
      const nat = document.createElement('span');
      nat.textContent = c.nature_of_call || '';
      body.appendChild(nat);
      if (c.full_address) {
        const addr = document.createElement('div');
        addr.className = 'text-muted small mt-1';
        addr.textContent = c.full_address;
        body.appendChild(addr);
      }
      div.appendChild(body);
      list.appendChild(div);
    });
    renderPagination(data.pagination);
  }

  function renderPagination(p) {
    const nav = document.getElementById('calls-pagination');
    nav.innerHTML = '';
    if (!p) return;
    nav.textContent = 'Page ' + p.current_page + ' of ' + p.total_pages + ' (' + p.total + ' total)';
  }

  function updateSummary(state) {
    const el = document.getElementById('filter-summary');
    if (!el) return;
    const parts = [];
    const v = state.values;
    if (v.preset)        parts.push(v.preset.replace(/_/g, ' '));
    else if (v.from && v.to) parts.push(v.from + ' → ' + v.to);
    if (v.status && v.status.length) parts.push(v.status.join('/'));
    if (v.call_type && v.call_type.length) parts.push(v.call_type.join(','));
    if (v.agency && v.agency.length) parts.push(v.agency.length + ' agencies');
    el.textContent = parts.length ? parts.join(', ') : 'All';
  }

  document.addEventListener('DOMContentLoaded', async function () {
    // Default to today + open on a fresh visit
    if (!window.location.search && !localStorage.getItem('filter-panel:last-state')) {
      const url = new URL(window.location);
      url.searchParams.set('preset', 'today');
      url.searchParams.set('status', 'open');
      window.history.replaceState({}, '', url);
    }

    const panel = new FilterPanel({
      root: document.getElementById('filter-panel'),
      onChange: function (state) {
        updateSummary(state);
        load('?' + state.toQueryString());
      },
    });
    await panel.mount();
    updateSummary(panel.getState());
    load('?' + panel.getState().toQueryString());
  });
})();
