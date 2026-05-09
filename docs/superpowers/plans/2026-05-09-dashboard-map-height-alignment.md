# Dashboard Map Height Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align the bottom of the desktop dashboard map (`#calls-map`) with the bottom of the Recent Calls card so the gap above the footer disappears.

**Architecture:** Pure-CSS flex sizing. Bootstrap's row already stretches `col-lg-5` and `col-lg-7` to equal height; remove the hard-coded `height: 800px` on `#calls-map` and let the map fill its column's stretched card via flexbox. Call Leaflet's `invalidateSize()` (via the existing `MapManager.resize()` wrapper) once after init and on window resize so tiles re-render at the new size.

**Tech Stack:** PHP 8.3 (Bootstrap 5 markup), vanilla CSS, vanilla JS, Leaflet (already wired through `public/assets/js/maps.js`).

**Spec:** `docs/superpowers/specs/2026-05-09-dashboard-map-height-alignment-design.md`

---

## File Structure

| File | Change |
|------|--------|
| `src/Dashboard/Views/partials/map-and-stats.php` | Modify (drop two inline `style` attributes, add a class) |
| `public/assets/css/dashboard.css` | Modify (append three CSS rules near existing `#calls-map` block) |
| `public/assets/js/dashboard-main.js` | Modify (one `requestAnimationFrame` after init + one debounced window resize listener) |

No new files. No new modules. The plan is intentionally three small surgical edits plus one manual visual-verification task. There is no automated UI test infrastructure in this repo, so verification is by inspecting the rendered DOM (grep + view-source) and by visual confirmation in a browser.

---

### Task 1: Remove inline sizing from the map markup and add a class hook

**Files:**
- Modify: `src/Dashboard/Views/partials/map-and-stats.php` (lines 4-15)

- [ ] **Step 1: Make the edit**

In `src/Dashboard/Views/partials/map-and-stats.php`, replace this block (lines 4-15):

```php
    <!-- Madison County Map (40% width) -->
    <div class="col-lg-5 mb-3">
        <div class="card" style="height: 100%;">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-geo-alt"></i> Madison County Map
                </h5>
            </div>
            <div class="card-body p-0">
                <div id="calls-map" style="height: 800px; width: 100%;"></div>
            </div>
        </div>
    </div>
```

with:

```php
    <!-- Madison County Map (40% width) -->
    <div class="col-lg-5 mb-3">
        <div class="card map-column-card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-geo-alt"></i> Madison County Map
                </h5>
            </div>
            <div class="card-body p-0">
                <div id="calls-map"></div>
            </div>
        </div>
    </div>
```

Two changes:
1. `<div class="card" style="height: 100%;">` → `<div class="card map-column-card">` — moves height from inline to a class so CSS can do the work.
2. `<div id="calls-map" style="height: 800px; width: 100%;"></div>` → `<div id="calls-map"></div>` — drops the hard-coded 800px.

- [ ] **Step 2: Verify the inline styles are gone**

Run:

```bash
grep -nE 'height: 800px|style="height: 100%' src/Dashboard/Views/partials/map-and-stats.php
```

Expected: **no output** (exit code 1). The two inline-style strings should no longer appear anywhere in this partial.

Then verify the class was added:

```bash
grep -n 'map-column-card' src/Dashboard/Views/partials/map-and-stats.php
```

Expected: one match on the line `<div class="card map-column-card">`.

- [ ] **Step 3: Commit**

```bash
git add src/Dashboard/Views/partials/map-and-stats.php
git commit -m "fix(dashboard): drop hard-coded map height in favor of CSS class"
```

---

### Task 2: Add the flex-sizing CSS rules

**Files:**
- Modify: `public/assets/css/dashboard.css` (insert after the existing `#calls-map, #units-map` block at lines 143-146)

- [ ] **Step 1: Make the edit**

Find the existing block in `public/assets/css/dashboard.css`:

```css
/* Map Styles */
#calls-map, #units-map {
    border-radius: 0 0 12px 12px;
}
```

Immediately after that block (before the next rule, `.leaflet-popup-content-wrapper`), insert:

```css
/* Map column: fill the row's stretched height; aligns with Recent Calls card */
.map-column-card {
    display: flex;
    flex-direction: column;
    height: 100%;
}

.map-column-card .card-body {
    flex: 1;
    min-height: 0;
    padding: 0;
}

#calls-map {
    width: 100%;
    height: 100%;
    min-height: 400px;
}
```

Why each piece:
- `.map-column-card { display: flex; flex-direction: column; height: 100%; }` — makes the card a vertical flex container that fills its column's stretched height.
- `.map-column-card .card-body { flex: 1; min-height: 0; padding: 0; }` — the body absorbs all available height; `min-height: 0` is required so the body can shrink below its intrinsic content size (a flexbox gotcha); `padding: 0` preserves the existing `p-0` behavior on the body in case it's ever removed from the markup.
- `#calls-map { width: 100%; height: 100%; min-height: 400px; }` — the map fills the body, with a 400px floor so the map stays usable when the column stacks at `< lg` (or in the unlikely case the right column ends up very short).

The pre-existing rule `#calls-map, #units-map { border-radius: 0 0 12px 12px; }` stays put — the new `#calls-map` rule below adds size properties without overriding the border-radius (different properties, no conflict).

- [ ] **Step 2: Verify the new rules are in place**

Run:

```bash
grep -nE '\.map-column-card|min-height: 400px' public/assets/css/dashboard.css
```

Expected: at least three matches — one for `.map-column-card { ... }`, one for `.map-column-card .card-body { ... }`, and one for `min-height: 400px`.

- [ ] **Step 3: Commit**

```bash
git add public/assets/css/dashboard.css
git commit -m "fix(dashboard): flex-size map card to fill column height"
```

---

### Task 3: Trigger Leaflet `invalidateSize` after init and on window resize

**Files:**
- Modify: `public/assets/js/dashboard-main.js` (lines 65-79)

**Background:** Leaflet caches its container's pixel size at `initMap()` time. Once we switch to flex sizing, the container's height is determined by layout, so we must call `invalidateSize()` after the first paint and on viewport resize. `MapManager.resize(id)` (defined at `public/assets/js/maps.js:279`) already wraps `invalidateSize()`.

- [ ] **Step 1: Make the edit**

Find the existing block at lines 65-79 of `public/assets/js/dashboard-main.js`:

```javascript
        // Initialize map
        let map = null;
        if (managers.MapManager) {
            try {
                const mapEl = document.getElementById('calls-map');
                if (mapEl) {
                    map = MapManager.initMap('calls-map');
                    console.log('[Dashboard Main] Map initialized');
                } else {
                    console.warn('[Dashboard Main] Map element not found');
                }
            } catch (error) {
                console.error('[Dashboard Main] Map init failed:', error);
            }
        }
```

Replace with:

```javascript
        // Initialize map
        let map = null;
        if (managers.MapManager) {
            try {
                const mapEl = document.getElementById('calls-map');
                if (mapEl) {
                    map = MapManager.initMap('calls-map');
                    console.log('[Dashboard Main] Map initialized');

                    // Map container is flex-sized; nudge Leaflet to recompute
                    // tiles after first paint and on viewport resize.
                    requestAnimationFrame(() => MapManager.resize('calls-map'));

                    let mapResizeTimer = null;
                    window.addEventListener('resize', () => {
                        clearTimeout(mapResizeTimer);
                        mapResizeTimer = setTimeout(() => {
                            MapManager.resize('calls-map');
                        }, 150);
                    });
                } else {
                    console.warn('[Dashboard Main] Map element not found');
                }
            } catch (error) {
                console.error('[Dashboard Main] Map init failed:', error);
            }
        }
```

Two additions, both inside the `if (mapEl)` branch so they only run when the map actually initialized:

1. `requestAnimationFrame(() => MapManager.resize('calls-map'));` — calls `invalidateSize()` once after the next paint, so Leaflet picks up the flex-sized container's real height.
2. A debounced (150ms) window `resize` listener that calls `MapManager.resize('calls-map')`, so the map re-tiles when the viewport changes width and the right column's natural height shifts.

The two-line comment is the WHY (Leaflet caches container size at init) — the code itself is self-explanatory, so no further comments are needed.

- [ ] **Step 2: Verify the additions are present**

Run:

```bash
grep -nE 'requestAnimationFrame.*MapManager\.resize|mapResizeTimer' public/assets/js/dashboard-main.js
```

Expected: at least three matches — the `requestAnimationFrame` call, the `let mapResizeTimer`, and the `clearTimeout(mapResizeTimer)` line.

- [ ] **Step 3: Commit**

```bash
git add public/assets/js/dashboard-main.js
git commit -m "fix(dashboard): invalidate Leaflet size after init and on resize"
```

---

### Task 4: Visual verification in a browser

CLAUDE.md mandates browser verification for UI changes: "For UI or frontend changes, start the dev server and use the feature in a browser before reporting the task as complete."

**Files:** none (verification only).

- [ ] **Step 1: Start a dev server**

From the repo root, pick whichever runs in your environment:

Option A — PHP built-in server (lightest, no DB needed for this visual check):

```bash
php -S localhost:8080 -t public/
```

Option B — full Docker stack (use this if your `.env` is wired up and you want real data):

```bash
COMPOSE_PROFILES=$DB_TYPE docker-compose up -d
docker-compose logs -f api
```

Either way the dashboard is at `http://localhost:8080/` (Option A) or whatever `API_PORT` is set to (Option B, default 8080).

- [ ] **Step 2: Verify the alignment at desktop width**

Open the dashboard at `≥ 992px` viewport width (the `lg` breakpoint). Open DevTools → Elements.

Check:

1. The `<div id="calls-map">` element has **no inline `style` attribute**. Its computed `height` should equal the `.card-body` height of its parent card.
2. The bottom edge of the map card and the bottom edge of the Recent Calls card share the same y-coordinate (use the DevTools layout overlay or just eyeball it — they should line up cleanly).
3. There is no large empty space between the bottom of the row and the page footer (only the existing `mb-4` on the row, which is small).
4. The Leaflet tiles fill the entire map area — no grey strips at the bottom.

If you see a grey strip at the bottom of the map, it means `invalidateSize()` didn't fire after the layout settled. Double-check Task 3's `requestAnimationFrame` call is wired to the right `MapManager.resize` call.

- [ ] **Step 3: Verify the alignment after window resize**

With the dashboard still open, drag the browser window between desktop widths (e.g., 1400px → 1100px → 1400px). After each resize:

- The map's bottom should still align with the Recent Calls card's bottom.
- The map should re-tile (no grey gaps appearing where new tiles should be) within ~150ms.

- [ ] **Step 4: Verify the small-viewport (`< lg`) fallback**

Resize the window narrower than 992px so the columns stack. Check:

- The map card now sits above the right-column content (stats + Recent Calls).
- The map area is at least ~400px tall (the `min-height` floor) and isn't collapsed to 0.
- The map tiles render fully within the visible area.

- [ ] **Step 5: Verify the call-detail zoom modal still works**

Click the map icon for any call in the Recent Calls table. The zoom modal should open with the modal map (`#modal-map`) at its existing 600px height. This map uses its own inline style and should be untouched by our changes — confirm it still renders correctly.

- [ ] **Step 6: Note results**

If everything passes, the implementation is complete — no further commits needed. If any check fails, revisit the relevant task, adjust, and re-verify.

---

## Self-Review

**Spec coverage:**

- ✅ "Drop the inline `style="height: 800px; width: 100%;"` on `#calls-map`" → Task 1
- ✅ "Drop the inline `style="height: 100%;"` on the map column's card and replace it with a class (`map-column-card`)" → Task 1
- ✅ Append CSS rules for `.map-column-card`, `.map-column-card .card-body`, `#calls-map` → Task 2
- ✅ `requestAnimationFrame(() => MapManager.resize('calls-map'))` after init → Task 3
- ✅ Debounced window resize listener calling `MapManager.resize('calls-map')` → Task 3 (150ms as specified)
- ✅ Test plan items (desktop alignment, resize, `< lg` floor, modal-map untouched) → Task 4

**Placeholder scan:** No TBDs, no "implement appropriately", no "similar to Task N", no "add validation". Every code step shows the actual code; every verification step shows the actual command and expected output.

**Type/identifier consistency:** `MapManager.resize` (not `.resizeMap` or `.invalidate`), `'calls-map'` consistently in single quotes, `.map-column-card` consistently with the dot prefix in CSS and bare in PHP. The class name introduced in Task 1 is referenced in Task 2 — names match.

No issues to fix.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-05-09-dashboard-map-height-alignment.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

**Which approach?**
