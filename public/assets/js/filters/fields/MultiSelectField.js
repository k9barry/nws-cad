// public/assets/js/filters/fields/MultiSelectField.js
(function (root) {
  'use strict';

  function MultiSelectField(name, label) {
    this.name = name;
    this.label = label;
    this.choices = null;
    this.listeners = [];
  }

  MultiSelectField.prototype.mount = function (rootEl, opts) {
    const select = document.createElement('select');
    select.name = this.name;
    select.id = 'ff-' + this.name;
    select.multiple = true;
    select.setAttribute('aria-label', this.label);
    rootEl.innerHTML = '';
    const labelEl = document.createElement('label');
    labelEl.htmlFor = select.id;
    labelEl.textContent = this.label;
    rootEl.appendChild(labelEl);
    rootEl.appendChild(select);

    (opts.options || []).forEach(function (opt) {
      const option = document.createElement('option');
      option.value = typeof opt === 'string' ? opt : opt.value;
      option.textContent = typeof opt === 'string' ? opt : opt.label;
      select.appendChild(option);
    });

    this.choices = new Choices(select, {
      removeItemButton: true,
      searchEnabled: true,
      shouldSort: false,
      allowHTML: false,
      placeholder: true,
      placeholderValue: 'Any',
    });

    if (opts.value && opts.value.length) {
      this.choices.setChoiceByValue(opts.value);
    }

    const self = this;
    select.addEventListener('change', function () {
      self.listeners.forEach(function (cb) { cb(self.getValue()); });
    });
  };

  MultiSelectField.prototype.getValue = function () {
    return this.choices ? this.choices.getValue(true) : [];
  };

  MultiSelectField.prototype.setValue = function (values) {
    if (!this.choices) return;
    this.choices.removeActiveItems();
    if (values && values.length) this.choices.setChoiceByValue(values);
  };

  MultiSelectField.prototype.on = function (event, cb) {
    if (event === 'change') this.listeners.push(cb);
  };

  MultiSelectField.prototype.destroy = function () {
    if (this.choices) { this.choices.destroy(); this.choices = null; }
    this.listeners = [];
  };

  root.MultiSelectField = MultiSelectField;
})(typeof window !== 'undefined' ? window : this);
