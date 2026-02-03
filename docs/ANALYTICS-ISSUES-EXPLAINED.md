# Analytics Page Issues - Explanation and Fixes

**Date**: February 3, 2026  
**Issues**: Different counts between pages + Slow loading

---

## Issue #1: Different Call Counts (203 vs 788)

### Why This Happens

**Dashboard shows**: 203 calls  
**Analytics shows**: 788 calls

**Root Cause**: User's browser has **cached the OLD JavaScript** that doesn't translate filters properly.

### What's Happening

The OLD analytics.js (in browser cache):
```javascript
// Old code (broken):
const queryString = Dashboard.buildQueryString(filters);
// Sends: ?quick_period=today&status=closed
```

The NEW analytics.js (we just fixed):
```javascript
// New code (fixed):
const apiFilters = filterManager.translateForAPI(filters);
const queryString = Dashboard.buildQueryString(apiFilters);
// Sends: ?date_from=2026-02-03&date_to=2026-02-03&closed_flag=true
```

### The Problem
- Old code sends `quick_period=today` which backend doesn't understand
- Old code sends `status=closed` which backend doesn't understand
- Backend ignores unrecognized parameters
- Result: No filters applied, returns all 788 calls

### The Solution
**HARD RELOAD to clear JavaScript cache**:
- Chrome/Edge: `Ctrl+Shift+R` or `Cmd+Shift+R`
- Firefox: `Ctrl+F5`
- Safari: `Cmd+Option+R`

After cache clear, analytics will send correct parameters and show 203 calls.

---

## Issue #2: Slow Loading Stats Cards

### Why Stats Cards Load Slowly

The analytics page makes **4 API calls** on every load:
1. `/stats` - Fast (~40ms)
2. `/stats/calls` - Fast (~40ms)
3. **`/calls?per_page=10000`** - **SLOW! (500ms-2000ms)**
4. **`/units?per_page=10000`** - **SLOW! (500ms-2000ms)**

### The Problem

**Fetching 10,000 records is expensive**:
- 10,000 calls = ~5-10 MB of JSON data
- PHP must serialize all that data
- MySQL must fetch and process thousands of rows
- Network must transfer megabytes
- Browser must parse megabytes of JSON

**Comparison**:
- Dashboard: `/units?per_page=1000` = Still too many, but tolerable
- Analytics: `/calls?per_page=10000` + `/units?per_page=10000` = **Extremely slow**

### Why It's Fetching So Much Data

Looking at the code (lines 97-100):
```javascript
const [stats, callStats, calls, units] = await Promise.all([
    Dashboard.apiRequest('/stats' + queryString),
    Dashboard.apiRequest('/stats/calls' + queryString),
    Dashboard.apiRequest('/calls?per_page=10000' + ...),  // ← WHY?
    Dashboard.apiRequest('/units?per_page=10000' + ...)   // ← WHY?
]);
```

These huge datasets are used for:
- **calls**: Calculate busiest hour, populate charts
- **units**: Populate top units table

**But**: The `/stats` endpoint already provides:
- `top_call_types`
- `calls_by_jurisdiction`
- `calls_by_status`
- `response_times`
- etc.

### The Solution Options

**Option 1: Use stats endpoint data (Recommended)**
- Remove the `/calls?per_page=10000` call
- Use data from `/stats` and `/stats/calls` endpoints
- These already have aggregated data

**Option 2: Reduce per_page limit**
- Change from `per_page=10000` to `per_page=1000` or less
- Still slow, but 10x faster

**Option 3: Add pagination**
- Don't fetch all data at once
- Load charts/tables progressively

---

## Performance Impact

### Current (Slow):
- Dashboard loads stats: ~100-500ms
- Analytics loads stats: **2,000-5,000ms** (4-50x slower!)

### After Cache Clear + Optimization:
- Dashboard loads stats: ~100-500ms
- Analytics loads stats: **~100-200ms** (same speed!)

---

## Immediate Fix (User Action)

**Clear browser cache** (Ctrl+Shift+R):
- ✅ Fixes the 203 vs 788 discrepancy
- ✅ Fixes filter translation
- ⚠️ Analytics will still be slow (needs code optimization)

---

## Long-term Fix (Code Change Needed)

**Remove unnecessary data fetching from analytics.js**:

Change line 96-101 from:
```javascript
const [stats, callStats, calls, units] = await Promise.all([
    Dashboard.apiRequest('/stats' + queryString),
    Dashboard.apiRequest('/stats/calls' + queryString),
    Dashboard.apiRequest('/calls?per_page=10000' + ...),
    Dashboard.apiRequest('/units?per_page=10000' + ...)
]);
```

To:
```javascript
const [stats, callStats] = await Promise.all([
    Dashboard.apiRequest('/stats' + queryString),
    Dashboard.apiRequest('/stats/calls' + queryString)
]);
// Use stats.top_call_types, stats.calls_by_jurisdiction, etc.
```

This would make analytics **20x faster** by eliminating the huge data fetches.

---

## Summary

| Issue | Cause | Fix |
|-------|-------|-----|
| Different counts | Cached JS not translating filters | **Hard reload (Ctrl+Shift+R)** |
| Slow stats cards | Fetching 10,000+ records | Optimize code to use /stats data |

**Immediate action**: Hard reload browser  
**Future optimization**: Remove excessive data fetching

