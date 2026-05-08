// public/assets/js/filters/FilterState.js
(function (root) {
  'use strict';

  const MULTI_FIELDS = [
    'call_type', 'incident_type', 'agency', 'ori', 'fdid',
    'beat', 'area', 'city', 'call_id', 'unit', 'status',
  ];
  const SINGLE_FIELDS = ['preset', 'from', 'to', 'date_field', 'location', 'nature_of_call', 'q'];

  function FilterState(initial) {
    this.values = Object.assign({}, initial || {});
  }

  FilterState.MULTI_FIELDS = MULTI_FIELDS;
  FilterState.SINGLE_FIELDS = SINGLE_FIELDS;
  FilterState.ALL_FIELDS = MULTI_FIELDS.concat(SINGLE_FIELDS);

  FilterState.fromQuery = function (qs) {
    const params = new URLSearchParams(qs.indexOf('?') === 0 ? qs.slice(1) : qs);
    const out = {};
    FilterState.ALL_FIELDS.forEach(function (key) {
      const raw = params.get(key);
      if (raw === null || raw === '') return;
      if (MULTI_FIELDS.indexOf(key) >= 0) {
        out[key] = raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
      } else {
        out[key] = raw;
      }
    });
    return new FilterState(out);
  };

  FilterState.prototype.toQueryString = function () {
    const params = new URLSearchParams();
    const v = this.values;
    Object.keys(v).forEach(function (key) {
      const value = v[key];
      if (value === null || value === undefined || value === '') return;
      if (Array.isArray(value)) {
        if (value.length === 0) return;
        params.set(key, value.join(','));
      } else {
        params.set(key, value);
      }
    });
    return params.toString();
  };

  FilterState.prototype.merge = function (partial) {
    Object.keys(partial).forEach(function (key) {
      const v = partial[key];
      if (v === null || v === undefined || (Array.isArray(v) && v.length === 0) || v === '') {
        delete this.values[key];
      } else {
        this.values[key] = v;
      }
    }, this);
    return this;
  };

  FilterState.prototype.get = function (key) { return this.values[key]; };
  FilterState.prototype.clear = function () { this.values = {}; return this; };
  FilterState.prototype.snapshot = function () { return JSON.parse(JSON.stringify(this.values)); };

  // Browser global
  root.FilterState = FilterState;
})(typeof window !== 'undefined' ? window : this);
