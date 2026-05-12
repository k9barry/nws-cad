// public/assets/js/filters/FilterPanel.js
(function (root) {
  'use strict';

  function FilterPanel(opts) {
    this.root = opts.root;
    this.optionsEndpoint = opts.optionsEndpoint || '/api/filter-options';
    this.onChange = opts.onChange || function () {};
    this.fields = (opts.fields || (this.root.dataset.fields || '').split(','))
      .map(function (s) { return s.trim(); })
      .filter(Boolean);
    this.compact = (opts.compact !== undefined ? opts.compact : (this.root.dataset.compact === 'true'));
    this.state = window.FilterState.fromQuery(window.location.search);
    this.fieldInstances = {};
    this.options = {};
    this.applyTimer = null;
  }

  FilterPanel.prototype.mount = async function () {
    await this._loadOptions();
    this._render();
    this._wireEvents();
    this._maybeShowRestoreBanner();
  };

  FilterPanel.prototype._loadOptions = async function () {
    const fieldsNeedingOptions = this.fields.filter(function (f) {
      return ['agency','ori','fdid','beat','area','city','call_type','incident_type','unit'].indexOf(f) >= 0;
    });
    if (fieldsNeedingOptions.length === 0) { this.options = {}; return; }

    const cacheKey = 'filter-panel:opts:' + fieldsNeedingOptions.join(',');
    const cached = JSON.parse(localStorage.getItem(cacheKey) || 'null');
    if (cached && cached.fetchedAt > Date.now() - 5 * 60 * 1000) {
      this.options = cached.data; return;
    }
    const url = this.optionsEndpoint + '?fields=' + encodeURIComponent(fieldsNeedingOptions.join(','));
    const resp = await fetch(url, { credentials: 'same-origin' });
    if (!resp.ok) { console.error('FilterPanel: filter-options request failed', resp.status); this.options = {}; return; }
    const json = await resp.json();
    this.options = json.data || {};
    localStorage.setItem(cacheKey, JSON.stringify({ fetchedAt: Date.now(), data: this.options }));
  };

  FilterPanel.prototype._render = function () {
    this.root.innerHTML = '';
    this.root.classList.add('filter-panel');
    if (this.compact) this.root.classList.add('filter-panel--compact');

    const header = document.createElement('div');
    header.className = 'filter-panel-header';
    const reset = document.createElement('button');
    reset.type = 'button';
    reset.textContent = 'Reset';
    reset.className = 'filter-panel-reset';
    const self = this;
    reset.addEventListener('click', function () { self.clear(); });
    header.appendChild(reset);
    this.root.appendChild(header);

    const announcer = document.createElement('div');
    announcer.setAttribute('aria-live', 'polite');
    announcer.className = 'filter-panel-announcer';
    this.root.appendChild(announcer);
    this.announcer = announcer;

    this.fields.forEach(function (name) {
      const wrap = document.createElement('div');
      wrap.className = 'filter-panel-field filter-panel-field--' + name;
      self.root.appendChild(wrap);
      const field = root.fieldRegistry.buildField(name);
      const initialValue = self._initialValueFor(name);
      field.mount(wrap, {
        options: self._optionsFor(name),
        value: initialValue,
      });
      field.on('change', function () { self._onFieldChange(); });
      self.fieldInstances[name] = field;
    });
  };

  FilterPanel.prototype._optionsFor = function (name) {
    const opts = this.options[name];
    if (!opts) return [];
    if (Array.isArray(opts) && typeof opts[0] === 'string') return opts;
    return (opts || []).map(function (o) {
      return { value: o.value, label: o.label || o.value };
    });
  };

  FilterPanel.prototype._initialValueFor = function (name) {
    if (name === 'date') {
      return { preset: this.state.get('preset'), from: this.state.get('from'), to: this.state.get('to') };
    }
    return this.state.get(name);
  };

  FilterPanel.prototype._onFieldChange = function () {
    const partial = {};
    Object.keys(this.fieldInstances).forEach(function (name) {
      const v = this.fieldInstances[name].getValue();
      if (name === 'date') {
        if (v && v.preset)        { partial.preset = v.preset; partial.from = ''; partial.to = ''; }
        else if (v && v.from && v.to) { partial.preset = ''; partial.from = v.from; partial.to = v.to; }
        else                          { partial.preset = ''; partial.from = ''; partial.to = ''; }
      } else {
        partial[name] = v;
      }
    }, this);
    this.state.merge(partial);
    this._scheduleApply();
  };

  FilterPanel.prototype._scheduleApply = function () {
    clearTimeout(this.applyTimer);
    const self = this;
    this.applyTimer = setTimeout(function () { self._apply(); }, 250);
  };

  FilterPanel.prototype._apply = function () {
    const qs = this.state.toQueryString();
    const url = window.location.pathname + (qs ? '?' + qs : '');
    window.history.replaceState({}, '', url);
    localStorage.setItem('filter-panel:last-state', JSON.stringify(this.state.snapshot()));
    this._announce();
    this.onChange(this.state);
  };

  FilterPanel.prototype._announce = function () {
    const count = Object.keys(this.state.values).length;
    this.announcer.textContent = count === 0 ? 'No filters active' : 'Filters applied: ' + count + ' active';
  };

  FilterPanel.prototype._maybeShowRestoreBanner = function () {
    if (window.location.search) return;
    const last = JSON.parse(localStorage.getItem('filter-panel:last-state') || 'null');
    if (!last || Object.keys(last).length === 0) return;

    const banner = document.createElement('div');
    banner.className = 'filter-panel-restore';
    banner.setAttribute('role', 'status');
    banner.textContent = 'Restore last filter? ';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = 'Restore';
    const self = this;
    btn.addEventListener('click', function () {
      self.state.merge(last);
      Object.keys(self.fieldInstances).forEach(function (name) {
        if (name === 'date') {
          self.fieldInstances[name].setValue({ preset: last.preset, from: last.from, to: last.to });
        } else {
          self.fieldInstances[name].setValue(last[name]);
        }
      });
      self._apply();
      banner.remove();
    });
    banner.appendChild(btn);
    this.root.insertBefore(banner, this.root.firstChild);
    setTimeout(function () { if (banner.parentNode) banner.remove(); }, 6000);
  };

  FilterPanel.prototype._wireEvents = function () {
    const self = this;
    window.addEventListener('popstate', function () {
      self.state = window.FilterState.fromQuery(window.location.search);
      Object.keys(self.fieldInstances).forEach(function (name) {
        self.fieldInstances[name].setValue(self._initialValueFor(name));
      });
      self.onChange(self.state);
    });
  };

  FilterPanel.prototype.getState = function () { return this.state; };

  FilterPanel.prototype.clear = function () {
    this.state.clear();
    Object.keys(this.fieldInstances).forEach(function (name) {
      this.fieldInstances[name].setValue(name === 'date' ? null : (window.FilterState.MULTI_FIELDS.indexOf(name) >= 0 ? [] : ''));
    }, this);
    this._apply();
  };

  FilterPanel.prototype.destroy = function () {
    Object.keys(this.fieldInstances).forEach(function (name) { this.fieldInstances[name].destroy(); }, this);
    this.fieldInstances = {};
    this.root.innerHTML = '';
  };

  root.FilterPanel = FilterPanel;
})(typeof window !== 'undefined' ? window : this);
