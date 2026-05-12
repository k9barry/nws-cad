# Dashboard prettify — design

**Date:** 2026-05-10
**Status:** Approved
**Scope:** Desktop dashboard view only (`src/Dashboard/Views/dashboard.php` + `partials/map-and-stats.php`).

## Problem

The notifications page (`src/Dashboard/Views/notifications.php`) was redesigned in commit `30abc19` with a polished, modern visual language: gradient banner header, live status pill with pulsing dot, gradient-headed cards, pill-style badges, soft shadows, and row-flash animations on new entries. The dashboard kept its earlier "default Bootstrap" treatment — plain h1 title, flat stat-card icons, plain text for status/priority, no gradient accents. The two pages now feel like they're from different products.

## Goal

Bring the notifications-page visual language to the desktop dashboard so the two pages read as a single product. Functional behavior stays the same; only presentation and a few small JS hooks change.

## Non-goals

- Mobile dashboard (`dashboard-mobile.php`).
- Refactoring the notifications page to share CSS (deferred — see Approaches §B below).
- New endpoints, new data, or behavior changes beyond the visual hooks listed in §JS hooks.

## Approach: pragmatic / shared visual vocabulary, separate stylesheets

**Chosen.** Add the new styles to `public/assets/css/dashboard.css`, reusing the same class-name conventions, gradients, and palette as the inline `<style>` block in `notifications.php`. Don't refactor notifications now. Some duplication of gradient values is acceptable until a future cleanup with more consumers.

**Considered and rejected:**

- *Extract shared `components.css`.* Cleaner long-term but requires modifying the working notifications page just for symmetry. Doubles scope. YAGNI for one consumer.
- *Inline `<style>` in `dashboard.php`.* Splits dashboard styles between `dashboard.css` and the view template — two places to look for the same thing. Worse for maintenance.

## Components

All new classes live in `public/assets/css/dashboard.css`. Class-name and gradient values intentionally mirror the notifications page so a future shared module can absorb both with minimal renaming.

| Class | Purpose |
|---|---|
| `.dashboard-banner` | Gradient page header (indigo → purple → pink). |
| `.live-pill` (+ `.is-paused`, `.is-error`) | Status pill on the banner — pulsing green dot when "Live", grey when paused, red on connection error. Same DOM structure as `.notif-live-pill`. |
| `.pill-badge` with modifiers `.is-priority-1`, `.is-priority-2`, `.is-priority-3`, `.is-active`, `.is-closed`, `.is-info` | Reusable rounded pill for status / priority / context labels in tables and cards. |
| `.stat-card-v2` | Replaces `.stat-card` styling: gradient icon tile + top accent strip. The existing `.clickable-stat` lift-on-hover behavior is kept. |
| `.gradient-card-header` | Thin gradient strip + white-on-gradient title styling for the map card and Recent Calls card. |
| `@keyframes row-flash` + `.row-new` | 1.2s yellow → transparent flash on rows newly added to the Recent Calls tbody. Same keyframes as `notif-flash`. |

## Markup changes

### `src/Dashboard/Views/dashboard.php`

Replace the existing dashboard header row:

```php
<div class="row mb-3">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="display-6 mb-0">…Dashboard Control Center</h1>
            …Active Filters chip + Filters button…
        </div>
    </div>
</div>
```

with a `.dashboard-banner` block:

```php
<div class="dashboard-banner d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h2><i class="bi bi-speedometer2"></i> Dashboard Control Center</h2>
        <div class="subtitle">Live call data · refreshes every <span id="dashboard-poll-secs">30</span>s</div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="live-pill" id="dashboard-live-pill">
            <span class="dot"></span>
            <span id="dashboard-live-text">Live</span>
        </span>
        <span class="pill-badge is-info" id="filter-summary-badge">Today, Open</span>
        <button type="button" class="btn btn-sm btn-light"
                data-bs-toggle="offcanvas" data-bs-target="#filter-drawer"
                aria-controls="filter-drawer">
            <i class="bi bi-sliders"></i> Filters
        </button>
    </div>
</div>
```

The existing `#filter-summary` element is renamed to `#filter-summary-badge` and given the `.pill-badge.is-info` shape. JS that previously wrote into `#filter-summary` is updated to write into `#filter-summary-badge`.

### `src/Dashboard/Views/partials/map-and-stats.php`

- Each of the four stat cards: replace `.stat-card border-{color}` with `.stat-card-v2 .is-{theme}` (themes: `is-total`, `is-active`, `is-closed`, `is-analytics`). The existing `.clickable-stat` and click handlers stay.
- Stat-card icon `<div class="stat-icon bg-{color}">` becomes `<div class="stat-icon">` — the gradient comes from the parent's theme class.
- Add a small `<span class="pill-badge is-info">…</span>` below the big number on each card, populated by JS from the active filter summary (e.g., "Today" / "Last 24h" / "All time"). For Active Calls, when the value > 0, the badge becomes `.is-active` (teal) and reads "Live".
- Map card header gets `class="card-header gradient-card-header"` plus a `<span class="pill-badge is-info" id="map-marker-count">0 markers</span>` next to the title.
- Recent Calls card header gets the same `gradient-card-header` class.
- Recent Calls tbody markup stays identical — the JS function in `public/assets/js/dashboard-main.js` that builds each `<tr>` from a call object is updated to emit `.pill-badge.is-priority-N` (where N is `1`, `2`, or `3` mapped from the call's priority value, with `3` covering 3+) for the priority cell and `.pill-badge.is-active` / `.pill-badge.is-closed` for the status cell instead of plain text.

## JS hooks (`public/assets/js/dashboard-main.js` and friends)

All hooks are additive. None change data flow or API contracts.

1. **Live pill state.** After every successful `Dashboard.apiRequest(...)` used by `refreshDashboard()`, call `setLivePill('live')`. On rejection, call `setLivePill('error')`. The function toggles the existing classes on `#dashboard-live-pill` (same pattern as the notifications page's `setLiveStatus`).
2. **Filter summary as pill badge.** The existing `updateFilterSummary()` function already targets `#filter-summary` — change the selector to `#filter-summary-badge`. No logic change.
3. **Stat card filter pills.** When `updateFilterSummary()` runs, write the same text it currently writes into `#filter-summary-badge` into a per-card pill (e.g., `#stat-total-pill`, `#stat-closed-pill`, `#stat-analytics-pill`). Active Calls' badge is special-cased: when the numeric value in `#stat-active-calls` parses to > 0, the badge becomes `.is-active` with text "Live"; otherwise it shows the same date-range label as the others.
4. **Row-flash on new calls.** Maintain a module-scoped `Set<string> previousCallIds`. When the recent-calls renderer rebuilds the tbody, any row whose `id` is not in `previousCallIds` gets `.row-new` added. Update `previousCallIds` after rendering. The flash animation auto-fades via CSS; no JS removal needed.
5. **Map marker count.** After `MapManager.showCalls('calls-map', callsWithLoc)`, set `#map-marker-count` text to `${callsWithLoc.length} marker${callsWithLoc.length === 1 ? '' : 's'}`.

## Test plan

Manual + headless browser verification (no automated UI tests in repo).

- **Visual regression — desktop ≥ lg:** open the dashboard. Confirm gradient banner renders, live pill shows green pulse, stat cards have gradient icon tiles and pill badges, Recent Calls table has pill-shaped status/priority cells, map card header has gradient strip and marker count badge.
- **Live pill state transitions:** simulate API failure (e.g., stop the API container or block the network) and confirm pill flips to red "Connection error". Restore and confirm it flips back to green "Live".
- **Row flash:** load the dashboard, manually post a new call into the system (or use a test fixture), confirm the new row in Recent Calls flashes yellow for ~1.2s.
- **Filter pill text:** change date filters via the drawer, confirm the banner pill and each stat-card pill update.
- **Map marker count:** apply a filter that changes the visible call set; confirm the marker count badge in the map header updates.
- **Mobile fallback:** narrower than `lg`, the desktop dashboard isn't served (mobile dashboard is a separate view), so no regression check needed.
- **Notifications page:** unchanged — open it and confirm nothing visually shifted.

## Risks

- *Class collision with notifications inline styles.* Notifications uses `.notif-live-pill`, `.channel-state-badge`, etc. — namespaced. The new dashboard classes (`.live-pill`, `.pill-badge`, `.stat-card-v2`, `.dashboard-banner`) are also namespaced. No collisions.
- *Existing JS depending on `#filter-summary`.* A grep confirms it's only referenced in the dashboard header and `dashboard-main.js`. Renaming to `#filter-summary-badge` is safe and updated in the same change.
- *Stat-card click handlers depend on `.clickable-stat`.* Kept as-is — only the visual cousin classes change.
- *`.gradient-card-header` overriding the existing `.card-header` rounded-corner rule (`border-radius: 12px 12px 0 0 !important`).* The new rule will need its own `border-radius` override. Confirmed in design.
