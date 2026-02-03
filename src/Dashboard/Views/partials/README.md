# Dashboard Partials

This directory contains modular components for the NWS CAD Dashboard. Each file is a self-contained section that is included in the main `dashboard.php`.

## Active Files

### 1. map-and-stats.php
**Purpose**: Main dashboard content layout
**Layout**:
- Left (40%): Madison County interactive map (800px height)
- Right (60%): 
  - Statistics Cards (2x2 grid): Total Calls, Active Calls, Closed Calls, Analytics
  - Recent Calls Table (responsive, paginated)

### 2. filter-modal.php
**Purpose**: Filter configuration interface
**Features**:
- Quick Select dropdown (Today, Yesterday, Last 7 Days, etc.)
- Custom date range (conditional display)
- Agency Type, Jurisdiction, Status, Priority filters
- Search field
- Clear, Cancel, Apply buttons
**Managed by**: FilterManager JavaScript class

### 3. call-detail-modal.php
**Purpose**: Modal container for call details
**Content**: Dynamically populated by `dashboard-main.js` → `viewCallDetails()`
**Shows**:
- Call information (ID, status, priority, times)
- Location details (address, coordinates, districts)
- Agency contexts (latest per agency type)
- Incidents (one per jurisdiction)
- Assigned units (with timestamps and status)
- Persons involved (deduplicated by name+role)
- Narratives (chronological with full text)

### 4. analytics-modal.php
**Purpose**: Analytics and reporting dashboard
**Layout**: Fullscreen modal for maximum chart visibility
**Content**:
- 4 summary stat cards
- 4 charts:
  1. Incidents by Jurisdiction (horizontal bar)
  2. Call Distribution (pie chart)
  3. Response Time Analysis (line chart)
  4. Calls by Agency (horizontal bar)
**Managed by**: `analytics.js`

## Usage

All partials are automatically included in `dashboard.php`:

```php
<?php
$partialsPath = __DIR__ . '/partials/';

// Include components
include $partialsPath . 'map-and-stats.php';
include $partialsPath . 'filter-modal.php';
include $partialsPath . 'call-detail-modal.php';
include $partialsPath . 'analytics-modal.php';
?>
```

## Dependencies

### Required JavaScript:
- `dashboard.js` - Core dashboard functionality
- `filter-manager.js` - Filter management
- `dashboard-main.js` - Dashboard page logic
- `analytics.js` - Analytics charts
- `maps.js` - Leaflet map functionality
- `charts.js` - Chart.js helpers

### Required CSS:
- Bootstrap 5.3
- Bootstrap Icons
- Leaflet CSS

### Required Libraries:
- Chart.js 4.4
- Leaflet 1.9.4

## File Structure

```
src/Dashboard/Views/
├── dashboard.php                    # Main dashboard file
└── partials/                        # Modular components
    ├── README.md                    # This file
    ├── map-and-stats.php            # Map + stats + recent calls
    ├── filter-modal.php             # Filter configuration
    ├── call-detail-modal.php        # Call details popup
    └── analytics-modal.php          # Analytics dashboard
```

## Maintenance

When updating:
1. Keep partials focused on single responsibility
2. Use consistent naming (kebab-case.php)
3. Add comments for complex sections
4. Update this README if adding/removing partials
5. Test after each change
