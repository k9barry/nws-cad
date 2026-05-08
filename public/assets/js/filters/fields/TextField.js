// public/assets/js/filters/fields/TextField.js
(function (root) {
  'use strict';
  function TextField(name, label) { this.name = name; this.label = label; this.input = null; this.listeners = []; this.timer = null; }
  TextField.prototype.mount = function (rootEl, opts) {
    rootEl.innerHTML = '';
    const labelEl = document.createElement('label');
    labelEl.textContent = this.label;
    labelEl.htmlFor = 'ff-' + this.name;
    rootEl.appendChild(labelEl);
    const input = document.createElement('input');
    input.type = 'text';
    input.id = 'ff-' + this.name;
    input.value = opts.value || '';
    rootEl.appendChild(input);
    this.input = input;
    const self = this;
    input.addEventListener('input', function () {
      clearTimeout(self.timer);
      self.timer = setTimeout(function () {
        self.listeners.forEach(function (cb) { cb(input.value); });
      }, 250);
    });
  };
  TextField.prototype.getValue = function () { return this.input ? this.input.value : ''; };
  TextField.prototype.setValue = function (v) { if (this.input) this.input.value = v || ''; };
  TextField.prototype.on = function (e, cb) { if (e === 'change') this.listeners.push(cb); };
  TextField.prototype.destroy = function () { clearTimeout(this.timer); this.listeners = []; };
  root.TextField = TextField;
})(typeof window !== 'undefined' ? window : this);
