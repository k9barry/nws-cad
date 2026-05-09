# Dashboard map height alignment — design

**Date:** 2026-05-09
**Status:** Approved
**Scope:** Desktop dashboard view only (`src/Dashboard/Views/dashboard.php`)

## Problem

On the desktop dashboard, the Madison County Map (`#calls-map`) has a hard-coded `height: 800px`, while the right-hand column's natural height is driven by its content (stats cards 2x2 grid + Recent Calls card with a `max-height: 400px` table plus header/pagination).

Because the two heights are independent, the map's bottom rarely aligns with the bottom of the Recent Calls card. In practice, this leaves a visible gap between the bottom of the map and the page footer.

## Goal

Make the bottom of the map line up with the bottom of the Recent Calls card on the desktop dashboard, with no manual height bookkeeping.

## Non-goals

- Mobile dashboard (`dashboard-mobile.php`) — separate view, not affected.
- Removing the `max-height: 400px` cap on the Recent Calls table (kept as-is).
- Changes to the call-detail zoom modal map (`#modal-map`).

## Approach: pure-CSS flex sizing

Bootstrap's row already stretches `col-lg-5` and `col-lg-7` to equal height (`align-items: stretch` is the default for `.row`). The card inside the map column declares `height: 100%`, so it already stretches to fill the column. The only thing breaking alignment is `#calls-map` having a fixed pixel height instead of filling its parent.

We make the map's card a vertical flex container, give the body `flex: 1` so it absorbs all available height, and let `#calls-map` fill the body. A `min-height` floor keeps the map usable when the column stacks at `< lg`.

Leaflet caches its container size at init time, so after the layout settles we call `MapManager.resize('calls-map')` (which wraps `invalidateSize()`) to force a re-tile. We also re-call it on debounced window resize.

## Changes

### 1. `src/Dashboard/Views/partials/map-and-stats.php`

- Drop the inline `style="height: 800px; width: 100%;"` on `#calls-map`.
- Drop the inline `style="height: 100%;"` on the map column's card and replace it with a class (`map-column-card`) so styling lives in CSS.

The right column's markup is untouched.

### 2. `public/assets/css/dashboard.css`

Append:

```css
/* Map column: fill the row's stretched height; aligns with Recent Calls card */
.map-column-card { display: flex; flex-direction: column; height: 100%; }
.map-column-card .card-body { flex: 1; min-height: 0; padding: 0; }
#calls-map { width: 100%; height: 100%; min-height: 400px; }
```

`min-height: 0` on the flex item is required so the body can shrink below its intrinsic content size (a known flexbox gotcha). The existing `#calls-map, #units-map { border-radius: 0 0 12px 12px; }` rule stays.

### 3. `public/assets/js/dashboard-main.js`

- After the existing `MapManager.initMap('calls-map')` call (around line 71), schedule `requestAnimationFrame(() => MapManager.resize('calls-map'))` so Leaflet picks up the flex-sized container after first paint.
- Add a debounced `window.addEventListener('resize', …)` that calls `MapManager.resize('calls-map')`. Debounce ~150ms is sufficient.

`MapManager.resize()` already exists in `public/assets/js/maps.js` and calls `invalidateSize()` — no JS changes there.

## Test plan

- Desktop `≥ lg`: open the dashboard. The bottom edge of the map card and the bottom edge of the Recent Calls card share the same y-coordinate. No empty space between map and footer.
- Resize the browser between desktop widths: the alignment holds; map tiles re-render without grey gaps appearing in newly-revealed areas.
- Width `< lg`: columns stack. Map shows at the 400px floor; no overflow or layout collapse.
- Reload with empty result set (no recent calls): right column shrinks; map still aligns to the (shorter) right column.
- Existing call-detail zoom modal (`#modal-map`) is unaffected — its own `height: 600px` inline style remains.

## Risks

- **Leaflet sizing race:** if the resize hook fires before the container has a non-zero size (e.g., the map card is hidden), tiles render at 0×0. Mitigation: schedule via `requestAnimationFrame` after init and on the debounced window resize. The dashboard isn't tab-hidden at load, so this is sufficient.
- **Right column extremely short:** if both stats and recent calls collapse to near-zero (unlikely but possible during loading), the map would also shrink. The 400px `min-height` floor prevents an unusably small map.
