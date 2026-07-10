# Vendored frontend libraries

All third-party frontend libraries are vendored here (not loaded from a CDN) so
the Content-Security-Policy can forbid third-party script/style/font origins and
drop `'unsafe-eval'`. See `src/Security/SecurityHeaders.php`.

| Library | Version | Source | Purpose |
|---|---|---|---|
| Choices.js | 10.x | https://github.com/Choices-js/Choices | Multi-select chip widget for filter panel |
| Flatpickr | 4.x | https://github.com/flatpickr/flatpickr | Date/time + range picker for filter panel |
| Bootstrap | 5.3.0 | https://cdn.jsdelivr.net/npm/bootstrap@5.3.0 | UI framework (CSS + JS bundle) |
| Bootstrap Icons | 1.11.0 | https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0 | Icon font (CSS + woff2/woff under `fonts/`) |
| Chart.js | 4.4.0 | https://cdn.jsdelivr.net/npm/chart.js@4.4.0 | Analytics charts (UMD build; no eval) |
| Leaflet | 1.9.4 | https://unpkg.com/leaflet@1.9.4 | Interactive maps (JS + CSS + marker images under `images/`) |

All are MIT/BSD-licensed and copied from the jsDelivr / unpkg CDNs. To upgrade a
library:
1. Download the new minified js/css (and any referenced fonts/images) from the CDN.
2. Update this file with the new version.
3. Reference it from `public/index.php` (the shared page shell) via `/assets/vendor/...`.
4. Verify the dashboard still renders: filters (`FilterPanel`) mount on `/`, `/calls`,
   `/units`; the map (Leaflet) and analytics charts (Chart.js) render; no CSP
   violations appear in the browser console.
