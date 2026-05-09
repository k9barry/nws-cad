// public/assets/js/filters/fields/StatusField.js
(function (root) {
  'use strict';
  const STATES = ['open', 'closed', 'reopened', 'canceled'];
  function StatusField() { this.values = []; this.buttons = {}; this.listeners = []; }
  StatusField.prototype.mount = function (rootEl, opts) {
    rootEl.innerHTML = '';
    const labelEl = document.createElement('span');
    labelEl.textContent = 'Status';
    labelEl.className = 'filter-panel-label';
    rootEl.appendChild(labelEl);
    const self = this;
    STATES.forEach(function (s) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = s.charAt(0).toUpperCase() + s.slice(1);
      btn.setAttribute('aria-pressed', 'false');
      btn.className = 'filter-panel-chip';
      btn.addEventListener('click', function () { self.toggle(s); });
      self.buttons[s] = btn;
      rootEl.appendChild(btn);
    });
    if (opts.value && opts.value.length) this.setValue(opts.value);
  };
  StatusField.prototype.toggle = function (s) {
    const i = this.values.indexOf(s);
    if (i >= 0) this.values.splice(i, 1); else this.values.push(s);
    this.refreshButtons();
    this.listeners.forEach(function (cb) { cb(this.values.slice()); }, this);
  };
  StatusField.prototype.refreshButtons = function () {
    const self = this;
    Object.keys(this.buttons).forEach(function (s) {
      const on = self.values.indexOf(s) >= 0;
      self.buttons[s].setAttribute('aria-pressed', on ? 'true' : 'false');
      self.buttons[s].classList.toggle('is-active', on);
    });
  };
  StatusField.prototype.getValue = function () { return this.values.slice(); };
  StatusField.prototype.setValue = function (v) { this.values = (v || []).slice(); this.refreshButtons(); };
  StatusField.prototype.on = function (e, cb) { if (e === 'change') this.listeners.push(cb); };
  StatusField.prototype.destroy = function () { this.listeners = []; };
  root.StatusField = StatusField;
})(typeof window !== 'undefined' ? window : this);
