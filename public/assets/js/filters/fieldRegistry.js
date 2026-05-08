// public/assets/js/filters/fieldRegistry.js
(function (root) {
  'use strict';
  const labels = {
    date: 'Date', call_type: 'Call Type', incident_type: 'Incident Type',
    nature_of_call: 'Nature of Call', agency: 'Agency', ori: 'ORI', fdid: 'FDID',
    beat: 'Beat', area: 'Area', city: 'City', location: 'Location',
    call_id: 'Call ID', unit: 'Unit #', status: 'Status', q: 'Search',
  };
  const types = {
    date: 'DateRangeField', status: 'StatusField',
    location: 'TextField', nature_of_call: 'TextField', q: 'TextField',
    call_type: 'MultiSelectField', incident_type: 'MultiSelectField',
    agency: 'MultiSelectField', ori: 'MultiSelectField', fdid: 'MultiSelectField',
    beat: 'MultiSelectField', area: 'MultiSelectField', city: 'MultiSelectField',
    call_id: 'MultiSelectField', unit: 'MultiSelectField',
  };
  function buildField(name) {
    const ctorName = types[name] || 'TextField';
    const Ctor = root[ctorName];
    if (!Ctor) throw new Error('Field constructor missing: ' + ctorName);
    return new Ctor(name, labels[name] || name);
  }
  root.fieldRegistry = { buildField: buildField, types: types, labels: labels };
})(typeof window !== 'undefined' ? window : this);
