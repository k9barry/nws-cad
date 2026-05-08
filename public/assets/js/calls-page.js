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

  document.addEventListener('DOMContentLoaded', async function () {
    const panel = new FilterPanel({
      root: document.getElementById('filter-panel'),
      onChange: function (state) { load('?' + state.toQueryString()); },
    });
    await panel.mount();
    load('?' + panel.getState().toQueryString());
  });
})();
