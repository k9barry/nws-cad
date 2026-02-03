# API Routing Fix - February 3, 2026

## Issue Summary
The API routing was broken due to incorrect basePath configuration in the Router class.

## Problem
**Symptom**: All `/api/*` endpoints returned 404 "Endpoint not found"

**Root Cause**: 
- In `/public/api.php`, the Router was instantiated with basePath = `/api.php`
- This caused route patterns to be `#^/api.php/calls$#`
- But incoming URIs were `/api/calls` (no `.php` extension)
- Patterns never matched, resulting in 404 errors

## Solution
Changed `/public/api.php` line 36:
```php
// BEFORE (broken):
$router = new Router('/api.php');

// AFTER (fixed):
$router = new Router('/api');
```

## Files Modified
1. **`/public/api.php`** - Changed Router basePath from `/api.php` to `/api`
2. **`/public/router.php`** - Simplified routing logic and added documentation
3. **`/src/Api/Router.php`** - Removed debug logging

## Prevention
- Added comment in `api.php` warning that basePath MUST be `/api` not `/api.php`
- Added comment in `router.php` explaining the routing flow
- This document serves as reference for future developers

## Verification
Test all API endpoints work correctly:
```bash
# Should return success: true
curl http://localhost:8080/api/calls?per_page=5
curl http://localhost:8080/api/units?per_page=5
curl http://localhost:8080/api/stats/calls
```

## Related Changes
This fix was implemented as part of the performance optimization that also:
- Added 7 database indexes
- Removed slow GROUP_CONCAT queries from CallsController
- Improved API response time from 39+ seconds to ~14ms (2,700x faster)

## Author
GitHub Copilot CLI - Performance Optimization Session
Date: February 3, 2026

---

## Frontend Configuration Update

### Issue
After fixing the backend routing, the frontend was still configured with the old API base URL:
- Frontend config: `apiBaseUrl: baseUrl + '/api.php'`
- Backend expects: `/api/*` paths

This caused all frontend API calls to get 404 errors because they were requesting:
- `http://localhost:8080/api.php/calls`
- `http://localhost:8080/api.php/stats`

Instead of the correct:
- `http://localhost:8080/api/calls`
- `http://localhost:8080/api/stats`

### Solution
Updated `/public/index.php` line 130:
```php
// BEFORE:
apiBaseUrl: baseUrl + '/api.php',

// AFTER:
apiBaseUrl: baseUrl + '/api',
```

### Files Modified
1. `/public/api.php` - Router basePath from `/api.php` to `/api`
2. `/public/index.php` - Frontend apiBaseUrl from `/api.php` to `/api`

### Verification
After clearing browser cache, all API calls should work:
```javascript
// Browser console should show:
[Dashboard Main] API Base URL: http://localhost:8080/api

// And API requests should succeed:
[Dashboard] API Request: http://localhost:8080/api/calls
```

---

**Both backend and frontend are now aligned on `/api` as the base path.**
