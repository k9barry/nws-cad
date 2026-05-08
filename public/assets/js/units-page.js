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

  function updateSummary(state) {
    const el = document.getElementById('filter-summary');
    if (!el) return;
    const v = state.values;
    const chips = [];

    const presets = {
      today: 'Today', yesterday: 'Yesterday',
      last_7_days: 'Last 7 Days', last_30_days: 'Last 30 Days',
      this_month: 'This Month', last_month: 'Last Month',
    };
    if (v.preset)               chips.push({ label: presets[v.preset] || v.preset, kind: 'accent' });
    else if (v.from && v.to)    chips.push({ label: v.from + ' → ' + v.to, kind: 'accent' });
    else                        chips.push({ label: 'All Time', kind: 'plain' });

    if (v.status && v.status.length) {
      v.status.forEach(function (s) {
        chips.push({ label: s.charAt(0).toUpperCase() + s.slice(1), kind: 'status-' + s });
      });
    }
    if (v.unit && v.unit.length)     chips.push({ label: v.unit.length === 1 ? 'Unit ' + v.unit[0] : v.unit.length + ' units', kind: 'plain' });
    if (v.agency && v.agency.length) chips.push({ label: v.agency.length + ' agencies', kind: 'plain' });
    if (v.call_id)                   chips.push({ label: 'Call ' + (Array.isArray(v.call_id) ? v.call_id[0] : v.call_id), kind: 'plain' });

    el.innerHTML = '';
    chips.forEach(function (c) {
      const span = document.createElement('span');
      span.className = 'summary-chip summary-chip--' + c.kind;
      span.textContent = c.label;
      el.appendChild(span);
    });

    const btn = document.querySelector('[data-bs-target="#filter-drawer"]');
    if (btn) {
      let badge = btn.querySelector('.filter-count-badge');
      if (chips.length <= 1) {
        if (badge) badge.remove();
      } else {
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'filter-count-badge';
          btn.appendChild(badge);
        }
        badge.textContent = String(chips.length);
      }
    }
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
