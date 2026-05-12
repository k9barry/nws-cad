# Dashboard Prettify Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the notifications-page visual language to the desktop dashboard — gradient banner header, live pill, modern stat cards, pill-badge table cells, gradient card headers, row-flash on new calls.

**Architecture:** Pure CSS additions in `public/assets/css/dashboard.css` reusing the same class-name and palette conventions as the inline styles in `notifications.php`, paired with surgical markup edits in `dashboard.php` and `partials/map-and-stats.php` and small additive JS hooks in `dashboard-main.js`. No data-flow or API changes.

**Tech Stack:** Bootstrap 5 markup, vanilla CSS (gradients, keyframes, `:has()`), vanilla JS, Leaflet (already wired via `MapManager`).

**Spec:** `docs/superpowers/specs/2026-05-10-dashboard-prettify-design.md`

---

## File Structure

| File | Change |
|------|--------|
| `public/assets/css/dashboard.css` | Modify — append new component classes (`.dashboard-banner`, `.live-pill`, `.pill-badge`, `.stat-card-v2`, `.gradient-card-header`, `@keyframes row-flash`) |
| `src/Dashboard/Views/dashboard.php` | Modify — replace header row with `.dashboard-banner` markup |
| `src/Dashboard/Views/partials/map-and-stats.php` | Modify — restyle four stat cards, add `gradient-card-header` to map and Recent Calls cards, add `#map-marker-count` pill, remove `bg-{color}` from stat icons |
| `public/assets/js/dashboard-main.js` | Modify — rename `#filter-summary` → `#filter-summary-badge`, write same value to per-card pills, swap status/priority cells to `pill-badge`, track `previousCallIds` for row-flash, set `#map-marker-count` after `MapManager.showCalls`, set live-pill state on refresh success/failure |

No new files. No new modules. Visual verification at the end via headless chromium since there's no JS test framework in this repo.

---

### Task 1: CSS foundations

**Files:**
- Modify: `public/assets/css/dashboard.css` (append a new section near the end)

This task adds every new class needed by later tasks. It changes nothing visually until later tasks attach the classes.

- [ ] **Step 1: Append the new component block at the end of `dashboard.css`**

Open `public/assets/css/dashboard.css` and append at the very end (after the existing rules, before any closing media query if present — verify you're at file scope):

```css
/* === Dashboard prettify components (mirrors notifications page palette) === */
.dashboard-banner {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #db2777 100%);
    color: #fff;
    border-radius: 0.75rem;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1rem;
    box-shadow: 0 4px 14px rgba(79, 70, 229, 0.25);
}
.dashboard-banner h2 { margin: 0; font-weight: 600; }
.dashboard-banner .subtitle { opacity: 0.85; font-size: 0.9rem; margin-top: 0.25rem; }
.dashboard-banner .btn-light {
    background: rgba(255, 255, 255, 0.95);
    border: none;
}

/* Live status pill — same shape as .notif-live-pill but reusable */
.live-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: rgba(255, 255, 255, 0.18);
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 999px;
    padding: 0.25rem 0.75rem;
    font-size: 0.8rem;
    font-weight: 500;
    color: #fff;
}
.live-pill .dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #34d399;
    box-shadow: 0 0 0 0 rgba(52, 211, 153, 0.7);
    animation: live-pulse 1.6s infinite;
}
.live-pill.is-paused .dot { background: #94a3b8; animation: none; box-shadow: none; }
.live-pill.is-error  .dot { background: #f87171; animation: none; box-shadow: none; }
@keyframes live-pulse {
    0%   { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0.7); }
    70%  { box-shadow: 0 0 0 10px rgba(52, 211, 153, 0); }
    100% { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0); }
}

/* Reusable pill-shaped status / priority / context badge */
.pill-badge {
    display: inline-block;
    padding: 0.2rem 0.55rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    background: #e2e8f0;
    color: #475569;
}
.pill-badge.is-info       { background: rgba(255, 255, 255, 0.22); color: #fff; border: 1px solid rgba(255, 255, 255, 0.3); }
.pill-badge.is-priority-1 { background: #fee2e2; color: #991b1b; }
.pill-badge.is-priority-2 { background: #ffedd5; color: #9a3412; }
.pill-badge.is-priority-3 { background: #e0f2fe; color: #075985; }
.pill-badge.is-active     { background: #d1fae5; color: #065f46; }
.pill-badge.is-closed     { background: #e2e8f0; color: #475569; }

/* Gradient card header strip — applied to map + Recent Calls cards */
.gradient-card-header {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%) !important;
    color: #fff;
    border-bottom: none !important;
    border-radius: 12px 12px 0 0 !important;
}
.gradient-card-header .card-title,
.gradient-card-header h5,
.gradient-card-header small { color: #fff !important; }
.gradient-card-header .text-muted { color: rgba(255, 255, 255, 0.75) !important; }

/* Modernized stat cards — gradient icon tile + top accent strip */
.stat-card-v2 {
    border: none;
    border-radius: 0.75rem;
    overflow: hidden;
    position: relative;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.stat-card-v2::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
}
.stat-card-v2:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
}
.stat-card-v2 .stat-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #fff;
    font-size: 1.25rem;
    flex-shrink: 0;
}
.stat-card-v2 h2 { font-size: 2rem; font-weight: 700; }
.stat-card-v2 h6 { font-size: 0.8rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; }

.stat-card-v2.is-total::before    { background: linear-gradient(90deg, #2563eb, #4f46e5); }
.stat-card-v2.is-total    .stat-icon { background: linear-gradient(135deg, #2563eb, #4f46e5); }
.stat-card-v2.is-active::before   { background: linear-gradient(90deg, #ef4444, #db2777); }
.stat-card-v2.is-active   .stat-icon { background: linear-gradient(135deg, #ef4444, #db2777); }
.stat-card-v2.is-closed::before   { background: linear-gradient(90deg, #475569, #1e293b); }
.stat-card-v2.is-closed   .stat-icon { background: linear-gradient(135deg, #475569, #1e293b); }
.stat-card-v2.is-analytics::before{ background: linear-gradient(90deg, #f59e0b, #db2777); }
.stat-card-v2.is-analytics .stat-icon { background: linear-gradient(135deg, #f59e0b, #db2777); }

/* Recent Calls row flash on insert */
.recent-calls-card tbody tr.row-new {
    animation: row-flash 1.2s ease;
}
@keyframes row-flash {
    0%   { background-color: #fef9c3; }
    100% { background-color: transparent; }
}
```

- [ ] **Step 2: Verify the additions are in place**

Run:

```bash
grep -nE '\.dashboard-banner \{|\.live-pill \{|\.pill-badge \{|\.stat-card-v2 \{|\.gradient-card-header \{|@keyframes row-flash' public/assets/css/dashboard.css
```

Expected: at least 6 matches — one for each new class/keyframe declaration.

- [ ] **Step 3: Commit**

```bash
git add public/assets/css/dashboard.css
git commit -m "feat(dashboard): add prettify component classes (banner, pill, stat-card-v2)"
```

---

### Task 2: Gradient banner header

**Files:**
- Modify: `src/Dashboard/Views/dashboard.php` (lines 3-24)
- Modify: `public/assets/js/dashboard-main.js` (line 98)

- [ ] **Step 1: Replace the dashboard header row markup**

In `src/Dashboard/Views/dashboard.php`, find lines 3-24:

```php
<!-- Dashboard Header with Filter Controls (Right-aligned) -->
<div class="row mb-3">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="display-6 mb-0">
                <i class="bi bi-speedometer2"></i> Dashboard Control Center
            </h1>
            <div class="d-flex align-items-center gap-3">
                <div>
                    <i class="bi bi-funnel me-2"></i>
                    <span class="text-muted">Active Filters: </span>
                    <span id="filter-summary" class="fw-bold">Today, Open</span>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm"
                        data-bs-toggle="offcanvas" data-bs-target="#filter-drawer"
                        aria-controls="filter-drawer">
                    <i class="bi bi-sliders"></i> Filters
                </button>
            </div>
        </div>
    </div>
</div>
```

Replace with:

```php
<!-- Dashboard Header (gradient banner) -->
<div class="dashboard-banner d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h2><i class="bi bi-speedometer2"></i> Dashboard Control Center</h2>
        <div class="subtitle">Live call data &middot; refreshes every <span id="dashboard-poll-secs">30</span>s</div>
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

- [ ] **Step 2: Update the JS selector**

In `public/assets/js/dashboard-main.js`, find line 98:

```javascript
        function updateFilterSummary() {
            const summaryEl = document.getElementById('filter-summary');
```

Change the selector to `'filter-summary-badge'`:

```javascript
        function updateFilterSummary() {
            const summaryEl = document.getElementById('filter-summary-badge');
```

No other logic changes in this function.

- [ ] **Step 3: Verify selector + class are present**

Run:

```bash
grep -n 'dashboard-banner\|filter-summary-badge\|dashboard-live-pill' src/Dashboard/Views/dashboard.php public/assets/js/dashboard-main.js
```

Expected: `dashboard.php` has `dashboard-banner`, `filter-summary-badge`, and `dashboard-live-pill`. `dashboard-main.js` has at least one match for `filter-summary-badge`. There should be NO remaining match for the bare ID `filter-summary` (without `-badge`):

```bash
grep -nE 'getElementById\(.filter-summary.\)|id="filter-summary"' src/Dashboard/Views/dashboard.php public/assets/js/dashboard-main.js
```

Expected: empty (the bare `filter-summary` ID is gone).

- [ ] **Step 4: Commit**

```bash
git add src/Dashboard/Views/dashboard.php public/assets/js/dashboard-main.js
git commit -m "feat(dashboard): gradient banner header + live pill"
```

---

### Task 3: Restyle the four stat cards

**Files:**
- Modify: `src/Dashboard/Views/partials/map-and-stats.php` (lines 21-91, the four stat-card blocks)

Each of the four card blocks today follows this pattern:

```php
<div class="col-md-6 col-sm-6 mb-3">
    <div class="card stat-card border-{COLOR} clickable-stat" {CLICK-HANDLER} style="cursor: pointer;">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-muted mb-1">{LABEL}</h6>
                    <h2 class="mb-0{TEXT-MOD}" id="{ID}">…</h2>
                </div>
                <div class="stat-icon bg-{COLOR}">
                    <i class="bi bi-{ICON}"></i>
                </div>
            </div>
        </div>
    </div>
</div>
```

The new pattern is:

```php
<div class="col-md-6 col-sm-6 mb-3">
    <div class="card stat-card-v2 is-{THEME} clickable-stat" {CLICK-HANDLER} style="cursor: pointer;">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-muted mb-1">{LABEL}</h6>
                    <h2 class="mb-0{TEXT-MOD}" id="{ID}">…</h2>
                    <span class="pill-badge mt-1 d-inline-block" id="{PILL-ID}">&nbsp;</span>
                </div>
                <div class="stat-icon">
                    <i class="bi bi-{ICON}"></i>
                </div>
            </div>
        </div>
    </div>
</div>
```

Mapping:

| Card | Theme | Pill ID |
|---|---|---|
| Total Calls | `is-total` | `stat-total-pill` |
| Active Calls | `is-active` | `stat-active-pill` |
| Closed Calls | `is-closed` | `stat-closed-pill` |
| Analytics | `is-analytics` | `stat-analytics-pill` |

- [ ] **Step 1: Replace the four stat card blocks**

Open `src/Dashboard/Views/partials/map-and-stats.php`. Replace lines 21-37 (Total Calls card) with:

```php
            <div class="col-md-6 col-sm-6 mb-3">
                <div class="card stat-card-v2 is-total clickable-stat" data-bs-toggle="modal" data-bs-target="#filters-modal" style="cursor: pointer;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Total Calls</h6>
                                <h2 class="mb-0" id="stat-total-calls">
                                    <span class="spinner-border spinner-border-sm"></span>
                                </h2>
                                <span class="pill-badge mt-1 d-inline-block" id="stat-total-pill">&nbsp;</span>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-funnel"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
```

Replace lines 39-55 (Active Calls card) with:

```php
            <div class="col-md-6 col-sm-6 mb-3">
                <div class="card stat-card-v2 is-active clickable-stat" onclick="filterDashboard('active')" style="cursor: pointer;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Active Calls</h6>
                                <h2 class="mb-0 text-danger" id="stat-active-calls">
                                    <span class="spinner-border spinner-border-sm"></span>
                                </h2>
                                <span class="pill-badge mt-1 d-inline-block" id="stat-active-pill">&nbsp;</span>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-telephone-fill"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
```

Replace lines 57-73 (Closed Calls card) with:

```php
            <div class="col-md-6 col-sm-6 mb-3">
                <div class="card stat-card-v2 is-closed clickable-stat" onclick="filterDashboard('closed')" style="cursor: pointer;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Closed Calls</h6>
                                <h2 class="mb-0" id="stat-closed-calls">
                                    <span class="spinner-border spinner-border-sm"></span>
                                </h2>
                                <span class="pill-badge mt-1 d-inline-block" id="stat-closed-pill">&nbsp;</span>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
```

Replace lines 75-91 (Analytics card) with:

```php
            <div class="col-md-6 col-sm-6 mb-3">
                <div class="card stat-card-v2 is-analytics clickable-stat" data-bs-toggle="modal" data-bs-target="#analytics-modal" style="cursor: pointer;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Analytics</h6>
                                <h2 class="mb-0" style="font-size: 1.2rem;">
                                    <i class="bi bi-graph-up"></i> View Charts
                                </h2>
                                <span class="pill-badge mt-1 d-inline-block" id="stat-analytics-pill">&nbsp;</span>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-bar-chart-fill"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
```

- [ ] **Step 2: Verify the four cards switched**

Run:

```bash
grep -cE 'stat-card-v2' src/Dashboard/Views/partials/map-and-stats.php
```

Expected: `4`.

```bash
grep -nE 'border-(primary|danger|secondary|warning)|bg-(primary|danger|secondary|warning)' src/Dashboard/Views/partials/map-and-stats.php
```

Expected: empty (none of the old `border-*` / `bg-*` color utilities should remain on the stat cards).

```bash
grep -nE 'id="(stat-total-pill|stat-active-pill|stat-closed-pill|stat-analytics-pill)"' src/Dashboard/Views/partials/map-and-stats.php
```

Expected: 4 matches, one for each pill.

- [ ] **Step 3: Commit**

```bash
git add src/Dashboard/Views/partials/map-and-stats.php
git commit -m "feat(dashboard): stat cards use stat-card-v2 with gradient icon tiles"
```

---

### Task 4: Populate stat-card pills from filter summary

**Files:**
- Modify: `public/assets/js/dashboard-main.js` (the `updateFilterSummary` function around line 97)

The function currently writes a chip-list label into one element. Extend it to also write into each per-card pill, with the Active card special-cased.

- [ ] **Step 1: Read the existing function in full**

Open `public/assets/js/dashboard-main.js` at line 97 and read the entire `updateFilterSummary` function (it's about 30 lines). You need to know the variable name it uses for the final summary string (likely a join of `chips`).

- [ ] **Step 2: Add the pill-population block**

At the **end** of the existing `updateFilterSummary` function — immediately before its closing `}` — append:

```javascript
            // Mirror the summary text into per-stat-card pills. Active Calls
            // is special-cased: when its numeric value > 0, show a green
            // "Live" pill instead.
            const summaryText = summaryEl ? summaryEl.textContent : '';
            ['stat-total-pill', 'stat-closed-pill', 'stat-analytics-pill'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) el.textContent = summaryText;
            });
            const activePill = document.getElementById('stat-active-pill');
            if (activePill) {
                const activeValEl = document.getElementById('stat-active-calls');
                const activeVal = activeValEl ? parseInt(activeValEl.textContent, 10) : NaN;
                if (Number.isFinite(activeVal) && activeVal > 0) {
                    activePill.textContent = 'Live';
                    activePill.classList.add('is-active');
                } else {
                    activePill.textContent = summaryText;
                    activePill.classList.remove('is-active');
                }
            }
```

- [ ] **Step 3: Verify the additions are present**

Run:

```bash
grep -nE 'stat-total-pill|stat-active-pill' public/assets/js/dashboard-main.js
```

Expected: at least 3 matches (one for each ID listed in the array, plus the active-pill block).

- [ ] **Step 4: Commit**

```bash
git add public/assets/js/dashboard-main.js
git commit -m "feat(dashboard): populate per-stat-card pill badges from filter summary"
```

---

### Task 5: Map + Recent Calls header upgrade

**Files:**
- Modify: `src/Dashboard/Views/partials/map-and-stats.php` (map header lines 6-10, Recent Calls header lines 96-101)
- Modify: `public/assets/js/dashboard-main.js` (the `loadCallsMap` site that calls `MapManager.showCalls('calls-map', callsWithLoc)` — search for `showCalls('calls-map'` to locate)

- [ ] **Step 1: Map card header + marker count badge**

In `src/Dashboard/Views/partials/map-and-stats.php`, find the map header (lines 6-10):

```php
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-geo-alt"></i> Madison County Map
                </h5>
            </div>
```

Replace with:

```php
            <div class="card-header gradient-card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-geo-alt"></i> Madison County Map
                </h5>
                <span class="pill-badge is-info" id="map-marker-count">0 markers</span>
            </div>
```

- [ ] **Step 2: Recent Calls card header**

In the same file, find the Recent Calls header (lines 96-101):

```php
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-list-ul"></i> <span id="recent-calls-title">Recent Calls</span>
                </h5>
                <small class="text-muted">Last updated: <span id="last-updated">Never</span></small>
            </div>
```

Replace with:

```php
            <div class="card-header gradient-card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-list-ul"></i> <span id="recent-calls-title">Recent Calls</span>
                </h5>
                <small>Last updated: <span id="last-updated">Never</span></small>
            </div>
```

(The `text-muted` class is removed because the gradient header's CSS already handles muted-text color via `.gradient-card-header .text-muted`, but the simpler reading is to drop it so the small text inherits white.)

- [ ] **Step 3: Update marker count after MapManager.showCalls**

In `public/assets/js/dashboard-main.js`, find the call to `MapManager.showCalls('calls-map', callsWithLoc)` (search via `grep -n "showCalls('calls-map'" public/assets/js/dashboard-main.js`). Immediately after that call, add:

```javascript
                            const markerCountEl = document.getElementById('map-marker-count');
                            if (markerCountEl) {
                                const n = callsWithLoc.length;
                                markerCountEl.textContent = n + ' marker' + (n === 1 ? '' : 's');
                            }
```

(Match the existing indentation at that site — likely 28 spaces given how nested the code is. Eyeball the surrounding lines.)

- [ ] **Step 4: Verify the changes**

```bash
grep -cE 'gradient-card-header' src/Dashboard/Views/partials/map-and-stats.php
```

Expected: `2` (map header + Recent Calls header).

```bash
grep -n 'map-marker-count' src/Dashboard/Views/partials/map-and-stats.php public/assets/js/dashboard-main.js
```

Expected: one match in the partial, at least one in the JS.

- [ ] **Step 5: Commit**

```bash
git add src/Dashboard/Views/partials/map-and-stats.php public/assets/js/dashboard-main.js
git commit -m "feat(dashboard): gradient card headers + map marker count pill"
```

---

### Task 6: Recent Calls table — pill badges for priority and status

**Files:**
- Modify: `public/assets/js/dashboard-main.js` (the row-builder around lines 302-308)

The current row-builder builds priority and status badges using Bootstrap `.badge bg-*`. Switch them to `.pill-badge` classes. Do NOT change the shared `Dashboard.getStatusBadge` / `Dashboard.getCallStateBadge` functions in `dashboard.js` — they're used elsewhere (e.g., units list). Inline the new pill builders only at the recent-calls render site.

- [ ] **Step 1: Replace the priority badge expression**

In `public/assets/js/dashboard-main.js`, find lines 305-307:

```javascript
                        const priorityBadge = call.priority 
                            ? `<span class="badge bg-${call.priority === 'High' ? 'danger' : call.priority === 'Medium' ? 'warning' : 'secondary'}">${Dashboard.escapeHtml(call.priority)}</span>`
                            : '<span class="badge bg-secondary">Normal</span>';
```

Replace with:

```javascript
                        const priorityKey = (call.priority || 'Normal');
                        const priorityClass = priorityKey === 'High'
                            ? 'is-priority-1'
                            : (priorityKey === 'Medium' ? 'is-priority-2' : 'is-priority-3');
                        const priorityBadge = `<span class="pill-badge ${priorityClass}">${Dashboard.escapeHtml(priorityKey)}</span>`;
```

- [ ] **Step 2: Replace the status badge expression**

Immediately above the priority block, find line 303:

```javascript
                        const statusBadge = Dashboard.getCallStateBadge(call);
```

Replace with:

```javascript
                        const callState = Dashboard.getCallState(call); // 'open' | 'closed' | 'reopened' | 'canceled'
                        const stateLabel = callState.charAt(0).toUpperCase() + callState.slice(1);
                        const stateClass = (callState === 'closed' || callState === 'canceled') ? 'is-closed' : 'is-active';
                        const statusBadge = `<span class="pill-badge ${stateClass}">${Dashboard.escapeHtml(stateLabel)}</span>`;
```

The two `<td>` cells (lines 331-332) already inject `${priorityBadge}` and `${statusBadge}` via template literal — no change needed there.

- [ ] **Step 3: Verify**

```bash
grep -nE 'pill-badge.*priority|pill-badge.*\$\{stateClass\}' public/assets/js/dashboard-main.js
```

Expected: at least 2 matches.

```bash
grep -n 'badge bg-.*Dashboard.escapeHtml(call.priority)' public/assets/js/dashboard-main.js
```

Expected: empty (the old inline priority badge is gone).

- [ ] **Step 4: Commit**

```bash
git add public/assets/js/dashboard-main.js
git commit -m "feat(dashboard): pill-badge styling for Recent Calls priority + status cells"
```

---

### Task 7: Row-flash animation for new calls

**Files:**
- Modify: `public/assets/js/dashboard-main.js` (recent-calls render path around line 285+)

The render path replaces `tableBody.innerHTML` wholesale on each refresh. We need to remember which call IDs were rendered last time, and add `.row-new` to any `<tr>` whose `data-call-id` wasn't seen before.

- [ ] **Step 1: Add a module-scoped tracker**

Near the top of the dashboard-main IIFE (just after the `let map = null;` line around line 66), add:

```javascript
        const previousCallIds = new Set();
```

- [ ] **Step 2: Diff IDs after each render**

Find the recent-calls render path. The `else` branch around line 301-360 builds the HTML via `tableBody.innerHTML = calls.map(…).join('');` (verify this — if the existing code uses something different, adapt).

Immediately AFTER the assignment to `tableBody.innerHTML` for the non-empty branch, add the diff loop. Concretely, look for the first line after the `tableBody.innerHTML = calls.map((call, index) => { … }).join('');` statement and insert:

```javascript
                    // Mark rows whose ID wasn't in the previous render so they flash.
                    const currentIds = new Set();
                    calls.forEach(function (c) { currentIds.add(String(c.id)); });
                    tableBody.querySelectorAll('tr.call-row').forEach(function (tr) {
                        const id = tr.getAttribute('data-call-id');
                        if (id && !previousCallIds.has(id)) {
                            tr.classList.add('row-new');
                        }
                    });
                    previousCallIds.clear();
                    currentIds.forEach(function (id) { previousCallIds.add(id); });
```

- [ ] **Step 3: Verify**

```bash
grep -nE 'previousCallIds|row-new' public/assets/js/dashboard-main.js
```

Expected: at least 4 matches (the declaration, the diff block additions, and the classList.add line).

- [ ] **Step 4: Commit**

```bash
git add public/assets/js/dashboard-main.js
git commit -m "feat(dashboard): flash new Recent Calls rows on refresh"
```

---

### Task 8: Live pill state on refresh success/failure

**Files:**
- Modify: `public/assets/js/dashboard-main.js` (the `refreshDashboard` function at line 692)

The pill defaults to "Live" markup as written in dashboard.php. We just need to flip it to error state on failure and back to live on success.

- [ ] **Step 1: Add a small helper inside the dashboard-main IIFE**

Just after the `previousCallIds` declaration from Task 7 (or anywhere inside the IIFE before `refreshDashboard`), add:

```javascript
        function setDashboardLivePill(state) {
            const pill = document.getElementById('dashboard-live-pill');
            const text = document.getElementById('dashboard-live-text');
            if (!pill || !text) return;
            pill.classList.remove('is-paused', 'is-error');
            if (state === 'error') {
                pill.classList.add('is-error');
                text.textContent = 'Connection error';
            } else if (state === 'paused') {
                pill.classList.add('is-paused');
                text.textContent = 'Paused';
            } else {
                text.textContent = 'Live';
            }
        }
```

- [ ] **Step 2: Wire it into refreshDashboard**

Find `refreshDashboard` (line 692). The current body is:

```javascript
        async function refreshDashboard() {
            console.log('[Dashboard Main] === Refreshing ===');
            
            // Update filter summary
            updateFilterSummary();
            
            try {
                await Promise.all([
                    loadStats(),
                    loadRecentCalls(),
                    loadCallsMap(),
                    loadCharts()
                ]);
                console.log('[Dashboard Main] === Refresh complete ===');
            } catch (error) {
                console.error('[Dashboard Main] Refresh error:', error);
            }
        }
```

Replace with:

```javascript
        async function refreshDashboard() {
            console.log('[Dashboard Main] === Refreshing ===');
            
            // Update filter summary
            updateFilterSummary();
            
            try {
                await Promise.all([
                    loadStats(),
                    loadRecentCalls(),
                    loadCallsMap(),
                    loadCharts()
                ]);
                setDashboardLivePill('live');
                console.log('[Dashboard Main] === Refresh complete ===');
            } catch (error) {
                setDashboardLivePill('error');
                console.error('[Dashboard Main] Refresh error:', error);
            }
        }
```

- [ ] **Step 3: Verify**

```bash
grep -nE 'setDashboardLivePill|dashboard-live-pill' public/assets/js/dashboard-main.js
```

Expected: at least 4 matches (the function definition + two calls inside refreshDashboard + the `getElementById` inside the helper).

- [ ] **Step 4: Commit**

```bash
git add public/assets/js/dashboard-main.js
git commit -m "feat(dashboard): drive live pill from refreshDashboard success/failure"
```

---

### Task 9: Visual verification

The compose stack is already running at `http://localhost:8080`. Headless chromium is at `/snap/bin/chromium`. Source files are mounted into the API container, so edits are live without rebuilds.

- [ ] **Step 1: Capture a desktop screenshot**

```bash
cd /home/jcleaver && /snap/bin/chromium --headless --disable-gpu --no-sandbox --hide-scrollbars --window-size=1920,1080 --screenshot=dash-prettify.png http://localhost:8080/ --virtual-time-budget=5000
```

Expected: file `~/dash-prettify.png` written with bytes > 100000.

- [ ] **Step 2: Inspect the screenshot**

Use the Read tool to view `/home/jcleaver/dash-prettify.png`. Confirm visually:

1. Gradient banner at top of the page (indigo → purple → pink), with white "Dashboard Control Center" title, a green-pulsing "Live" pill, a translucent "Today, Open" chip, and a Filters button.
2. Four stat cards each with a colored top accent strip and a colored gradient icon tile in the upper right (Total = blue/indigo, Active = red/pink, Closed = slate, Analytics = orange/pink).
3. Map card has a gradient header with "Madison County Map" in white and a "N markers" pill on the right.
4. Recent Calls card has a gradient header. In the table body, the Priority column shows pill-shaped tags and the Status column shows pill-shaped tags (uppercase, rounded).
5. No grey strip at the bottom of the map (Leaflet retiled).
6. Footer is right at the bottom of the row content (the prior gap fix is preserved).

- [ ] **Step 3: Confirm CSS / DOM via curl spot-checks**

```bash
curl -s http://localhost:8080/ | grep -nE 'dashboard-banner|dashboard-live-pill|stat-card-v2|gradient-card-header|map-marker-count' | head -10
```

Expected: at least 7 matches across the rendered HTML.

```bash
curl -s http://localhost:8080/assets/css/dashboard.css | grep -nE '\.dashboard-banner \{|\.live-pill \{|\.pill-badge \{|\.stat-card-v2 \{|\.gradient-card-header \{|@keyframes row-flash' | head -10
```

Expected: 6 matches.

- [ ] **Step 4: Notifications page regression check**

```bash
cd /home/jcleaver && /snap/bin/chromium --headless --disable-gpu --no-sandbox --hide-scrollbars --window-size=1920,1080 --screenshot=notif-regression.png http://localhost:8080/notifications --virtual-time-budget=5000
```

Read the file. Confirm the notifications page still looks identical to before (gradient header, channel cards, send-log rows). The dashboard prettify must not have visually shifted notifications.

- [ ] **Step 5: Note results**

If everything passes, the implementation is complete — no further commits needed. If any check fails, revisit the relevant task.

---

## Self-Review

**Spec coverage:**

- ✅ Spec §1 "Gradient banner header" → Task 2.
- ✅ Spec §2 "Stat card upgrade" → Task 3 (markup) + Task 4 (per-card pill JS).
- ✅ Spec §3 "Recent Calls table polish" → Task 6 (pill badges) + Task 7 (row-flash). Card header in Task 5.
- ✅ Spec §4 "Map card upgrade" → Task 5 (gradient header + marker count badge).
- ✅ Spec §Components → Task 1 (every class declared).
- ✅ Spec §JS hooks → all five accounted for: live-pill (Task 8), filter-summary rename (Task 2), stat-card pills (Task 4), row-flash (Task 7), map marker count (Task 5).
- ✅ Spec §Test plan → Task 9.
- ✅ Spec §Risks "Class collisions" → addressed by namespace (different class names from notifications). "Existing JS depending on `#filter-summary`" → covered by Task 2 selector update + verification grep. "Stat-card click handlers depend on `.clickable-stat`" → preserved in Task 3 markup.

No gaps.

**Placeholder scan:** No "TBD", "TODO", "appropriate error handling", "similar to Task N", or "implement later" anywhere. Each step has either exact code or exact bash. The "(if the existing code uses something different, adapt)" caveat in Task 7 Step 2 is supported by an explicit instruction to verify the line before editing — I'm trusting the engineer with that one because the row-render block is well-scoped and the diff-add code is independent of the surrounding render style.

**Type / identifier consistency:**

- `dashboard-live-pill` consistent across Task 2 (markup), Task 8 (JS).
- `filter-summary-badge` consistent across Task 2 (markup) and Task 2 (JS rename).
- `stat-{total,active,closed,analytics}-pill` consistent across Task 3 (markup) and Task 4 (JS).
- `map-marker-count` consistent across Task 5 (markup) and Task 5 (JS update).
- `previousCallIds` declared in Task 7, referenced only there.
- `setDashboardLivePill` declared in Task 8, called only there.
- `pill-badge`, `is-active`, `is-closed`, `is-priority-1/2/3`, `is-info` defined in Task 1, used in Tasks 2/3/5/6.
- `stat-card-v2`, `is-total/is-active/is-closed/is-analytics` defined in Task 1, used in Task 3.
- `gradient-card-header` defined in Task 1, used in Task 5.
- `row-new` + `@keyframes row-flash` defined in Task 1, used in Task 7.

No drift. No issues to fix.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-05-10-dashboard-prettify.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

**Which approach?**
