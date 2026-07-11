// public/assets/js/filters/fields/DateRangeField.js
(function (root) {
  'use strict';

  const PRESETS = [
    ['today', 'Today'], ['yesterday', 'Yesterday'],
    ['last_7_days', 'Last 7 days'], ['last_30_days', 'Last 30 days'],
    ['this_month', 'This month'], ['last_month', 'Last month'],
  ];

  function DateRangeField(name, label) {
    this.name = name; // 'date'
    this.label = label;
    this.fp = null;
    this.presetSelect = null;
    this.listeners = [];
    this.value = { preset: null, from: null, to: null };
  }

  DateRangeField.prototype.mount = function (rootEl, opts) {
    rootEl.innerHTML = '';
    const inputId = 'ff-' + this.name + '-range';
    const labelEl = document.createElement('label');
    labelEl.textContent = this.label;
    labelEl.htmlFor = inputId;
    rootEl.appendChild(labelEl);

    const presetSelect = document.createElement('select');
    presetSelect.setAttribute('aria-label', 'Date preset');
    [['', 'Custom']].concat(PRESETS).forEach(function (p) {
      const o = document.createElement('option');
      o.value = p[0]; o.textContent = p[1];
      presetSelect.appendChild(o);
    });
    this.presetSelect = presetSelect;
    rootEl.appendChild(presetSelect);

    const input = document.createElement('input');
    input.type = 'text';
    input.id = inputId;
    // Accessible name via the associated <label htmlFor>; aria-label is a
    // fallback for when Flatpickr swaps in an alt input that loses the id link.
    input.setAttribute('aria-label', this.label + ' range (YYYY-MM-DD to YYYY-MM-DD)');
    input.placeholder = 'YYYY-MM-DD to YYYY-MM-DD';
    rootEl.appendChild(input);

    const self = this;
    this.fp = flatpickr(input, {
      mode: 'range',
      dateFormat: 'Y-m-d',
      allowInput: true,
      onChange: function (selectedDates) {
        if (selectedDates.length === 2) {
          self.value = {
            preset: null,
            from: self.fp.formatDate(selectedDates[0], 'Y-m-d'),
            to:   self.fp.formatDate(selectedDates[1], 'Y-m-d'),
          };
          presetSelect.value = '';
          self.emit();
        }
      },
    });

    presetSelect.addEventListener('change', function () {
      const v = presetSelect.value;
      if (!v) return;
      self.value = { preset: v, from: null, to: null };
      self.fp.clear();
      self.emit();
    });

    if (opts.value) this.setValue(opts.value);
  };

  DateRangeField.prototype.getValue = function () { return this.value; };

  DateRangeField.prototype.setValue = function (val) {
    if (!val) { this.value = { preset: null, from: null, to: null }; return; }
    if (val.preset) {
      this.value = { preset: val.preset, from: null, to: null };
      if (this.presetSelect) this.presetSelect.value = val.preset;
    } else if (val.from && val.to) {
      this.value = { preset: null, from: val.from, to: val.to };
      if (this.fp) this.fp.setDate([val.from, val.to]);
    }
  };

  DateRangeField.prototype.on = function (event, cb) {
    if (event === 'change') this.listeners.push(cb);
  };

  DateRangeField.prototype.emit = function () {
    const v = this.value;
    this.listeners.forEach(function (cb) { cb(v); });
  };

  DateRangeField.prototype.destroy = function () {
    if (this.fp) { this.fp.destroy(); this.fp = null; }
    this.listeners = [];
  };

  root.DateRangeField = DateRangeField;
})(typeof window !== 'undefined' ? window : this);
