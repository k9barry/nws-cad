# Dashboard User Guide

## Overview

The NWS CAD Dashboard provides real-time monitoring and visualization of CAD data through an interactive web interface with automatic mobile detection.

## Access

- **Desktop Dashboard:** http://localhost:80
- **Mobile Dashboard:** Auto-detected on mobile devices
- **Database Manager:** http://localhost:8978 (DBeaver/CloudBeaver)

## Desktop Dashboard

### Main View

The main dashboard displays:

| Section | Description |
|---------|-------------|
| **Stats Cards** | Total calls, Active calls, Closed calls, Units available |
| **Interactive Map** | Call locations centered on Madison County, IN |
| **Recent Calls** | Latest calls with quick details |
| **Live Indicator** | Shows auto-refresh status (30-second interval) |

### Stats Cards (Clickable)

- **Total Calls** - Shows all calls, click to view filtered list
- **Active Calls** - Click to filter to active calls only
- **Closed Calls** - Click to filter to closed calls only
- **Analytics** - Opens full analytics modal with charts

### Filters

Access the filter modal via the filter icon in the header:

| Filter | Options |
|--------|---------|
| Quick Select | Today, Yesterday, Last 7 Days, Last 30 Days, Custom |
| Jurisdiction | All jurisdictions from data |
| Agency | Police, Fire, EMS |
| Status | Active, Closed, All |
| Priority | 1-5 |
| Call Type | Text search |

Filters persist across page refreshes and apply to all views.

### Call Details Modal

Click any call to view complete details:

- **Header**: Call number, status badge, priority badge
- **Agency Context**: Agency type, call type, priority, status
- **Location**: Full address, cross streets, coordinates, district info
- **Caller Info**: Name and phone (if available)
- **Incidents**: Incident numbers and jurisdictions
- **Units**: Assigned units with status
- **Narratives**: Chronological timeline
- **Persons**: Involved parties
- **Vehicles**: Involved vehicles

### Analytics Modal

Access via the 4th stats card or analytics button:

- **Call Volume Chart**: Hourly/daily call distribution
- **Call Types Chart**: Distribution by type
- **Priority Chart**: Distribution by priority
- **Agency Chart**: Calls by agency type

## Mobile Dashboard

### Automatic Detection

The system uses `jenssegers/agent` to detect mobile devices and automatically serves a touch-optimized interface.

### Mobile Layout

| Section | Description |
|---------|-------------|
| **Header** | App title, live indicator |
| **Stats Bar** | Horizontal scrollable stats cards |
| **Content Area** | Calls list, map, or analytics |
| **Bottom Nav** | Calls, Map, Analytics, Refresh, Filters |

### Bottom Navigation

| Icon | Action |
|------|--------|
| 📋 Calls | Show calls list |
| 🗺️ Map | Show interactive map |
| 📊 Analytics | Open analytics modal |
| 🔄 Refresh | Manual data refresh |
| 🔍 Filters | Open filters modal |

### Mobile Features

- **Pull-to-refresh**: Swipe down to refresh data
- **Touch-optimized**: Large tap targets (44x44px minimum)
- **Full-screen modals**: Filters, call details, analytics
- **Swipeable cards**: Navigate stats horizontally
- **Map popups**: Tap markers for call info + View Details button

### Mobile Call Details

Tap any call card to view full details in a modal with the same information as desktop.

## Interactive Map

### Features

- **Leaflet.js**: OpenStreetMap tiles
- **Default Center**: Madison County, IN (40.1184°N, 85.69°W)
- **Zoom Level**: 10 (county view)
- **Auto-bounds**: Fits to visible call markers

### Map Markers

- Click any marker to see call popup
- Popup shows: Call number, type, address, time
- "View Details" button opens full call detail modal

## Configuration

### Auto-Refresh Interval

Default: 30 seconds. Configure in `public/index.php`:

```php
window.APP_CONFIG = {
    refreshInterval: 30000  // milliseconds
};
```

### Map Center

Default: Madison County, IN. Configure in `public/assets/js/maps.js`:

```javascript
const MADISON_COUNTY_CENTER = [40.1184, -85.69];
const DEFAULT_ZOOM = 10;
```

## Keyboard Shortcuts

| Key | Action |
|-----|--------|
| Escape | Close modal |
| Enter | Submit filter form |

## Browser Support

| Browser | Support |
|---------|---------|
| Chrome/Edge | ✅ Full |
| Firefox | ✅ Full |
| Safari | ✅ Full |
| Mobile Chrome | ✅ Full |
| Mobile Safari | ✅ Full |

## Troubleshooting

### Dashboard Not Loading

1. Check API status: `curl http://localhost:8080/api/`
2. Check browser console (F12) for errors
3. Clear browser cache: Ctrl+Shift+R

### Map Not Displaying

1. Verify internet connection (for map tiles)
2. Check browser console for Leaflet errors
3. Verify calls have coordinates in database

### Mobile View Not Appearing

1. Check User-Agent header
2. Clear browser cache
3. Try different mobile device or browser

### Data Not Refreshing

1. Check live indicator (should pulse green)
2. Verify API is responding
3. Check browser console for errors

## File Structure

```
public/
├── index.php                 # Entry point with routing
├── assets/
│   ├── css/
│   │   ├── dashboard.css     # Desktop styles
│   │   ├── mobile.css        # Mobile styles
│   │   └── print.css         # Print styles
│   └── js/
│       ├── dashboard.js      # Core utilities
│       ├── dashboard-main.js # Main page logic
│       ├── mobile.js         # Mobile controller
│       ├── maps.js           # Map management
│       ├── charts.js         # Chart rendering
│       ├── calls.js          # Calls page
│       ├── units.js          # Units page
│       ├── analytics.js      # Analytics page
│       └── filter-manager.js # Filter state

src/Dashboard/Views/
├── dashboard.php             # Desktop view
├── dashboard-mobile.php      # Mobile view
├── partials/                 # Desktop components
│   ├── analytics-modal.php
│   ├── call-detail-modal.php
│   ├── filter-modal.php
│   └── map-and-stats.php
└── partials-mobile/          # Mobile components
    ├── analytics-modal.php
    ├── call-detail-modal.php
    └── filters-modal.php
```

---

**Version:** 1.1.0 | **Last Updated:** 2026-02-15
