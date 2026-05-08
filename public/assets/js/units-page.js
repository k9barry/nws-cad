(function () {
  'use strict';

  async function load(qs) {
    const resp = await fetch('/api/units' + (qs || ''));
    if (!resp.ok) { console.error('units fetch failed'); return; }
    const body = await resp.json();
    render(body.data);
  }

  function render(data) {
    const list = document.getElementById('units-list');
    list.innerHTML = '';
    (data.items || []).forEach(function (u) {
      const div = document.createElement('div');
      div.className = 'unit-item card mb-2';
      const body = document.createElement('div');
      body.className = 'card-body';
      body.innerHTML = '';  // build via createElement to avoid XSS
      const num = document.createElement('strong');
      num.textContent = u.unit_number || '';
      body.appendChild(num);
      const sep = document.createTextNode(' — ');
      body.appendChild(sep);
      const callSpan = document.createElement('span');
      callSpan.textContent = (u.call_number || '') + ' / ' + (u.nature_of_call || '');
      body.appendChild(callSpan);
      div.appendChild(body);
      list.appendChild(div);
    });
    renderPagination(data.pagination);
  }

  function renderPagination(p) {
    const nav = document.getElementById('units-pagination');
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
