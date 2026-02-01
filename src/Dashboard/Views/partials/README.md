# Dashboard Partials

This directory contains modular components for the NWS CAD Dashboard. Each file is a self-contained section that is included in the main `dashboard.php`.

## Files

### 1. filter-summary.php (19 lines)
**Purpose**: Active filters summary bar
**Shows**: Current filter status (e.g., "Last 7 Days") and "Change Filters" button
**Used by**: Main dashboard header

### 2. stats-cards.php (74 lines)
**Purpose**: Four primary dashboard action cards
**Cards**:
- **Total Calls** (Blue) → Opens filter modal
- **Active Calls** (Red) → Filters to active calls
- **Closed Calls** (Gray) → Filters to closed calls
- **Analytics** (Yellow) → Opens analytics modal

### 3. map-and-table.php (57 lines)
**Purpose**: Main dashboard content area
**Layout**:
- Left (25%): Madison County map (800px height)
- Right (75%): Recent calls table (20 rows, sticky header)

### 4. filter-modal.php (118 lines)
**Purpose**: Filter configuration interface
**Features**:
- Quick Select dropdown (Today, Yesterday, Last 7 Days, etc.)
- Custom date range (conditional display)
- Agency Type, Jurisdiction, Status, Priority filters
- Search field
- Clear, Cancel, Apply buttons
**Managed by**: FilterManager JavaScript class

### 5. call-detail-modal.php (17 lines)
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

### 6. analytics-modal.php (152 lines)
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

// Include components in order
include $partialsPath . 'filter-summary.php';
include $partialsPath . 'stats-cards.php';
include $partialsPath . 'map-and-table.php';
include $partialsPath . 'filter-modal.php';
include $partialsPath . 'call-detail-modal.php';
include $partialsPath . 'analytics-modal.php';
?>
```

## Benefits

1. **Modular**: Each component is self-contained
2. **Maintainable**: Easy to find and edit specific sections
3. **Reusable**: Components can be included elsewhere if needed
4. **Testable**: Individual components can be tested in isolation
5. **Readable**: Clear separation of concerns

## Editing Guidelines

### To modify a specific section:
1. Identify which partial contains the section
2. Edit only that file
3. Test the change
4. No need to touch other files

### To add a new section:
1. Create new partial in this directory (e.g., `new-section.php`)
2. Add include line to `dashboard.php`
3. Test rendering

### To remove a section:
1. Comment out or remove include line in `dashboard.php`
2. Optionally delete the partial file
3. Test dashboard still works

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
- Custom dashboard.css

### Required Libraries:
- Chart.js 4.4
- Leaflet 1.9.4

## File Structure

```
src/Dashboard/Views/
├── dashboard.php                    # Main file (29 lines)
├── partials/                        # Modular components
│   ├── README.md                    # This file
│   ├── filter-summary.php           # 19 lines
│   ├── stats-cards.php              # 74 lines
│   ├── map-and-table.php            # 57 lines
│   ├── filter-modal.php             # 118 lines
│   ├── call-detail-modal.php        # 17 lines
│   └── analytics-modal.php          # 152 lines
├── dashboard_old.php                # Backup (before modularization)
└── dashboard.php.backup-pre-modular # Backup (pre-consolidation)
```

## Total Line Count
- Main: 29 lines
- Partials: 437 lines
- **Total**: 466 lines (well-organized across 7 files)

## Maintenance

When updating:
1. Keep partials focused on single responsibility
2. Use consistent naming (kebab-case.php)
3. Add comments for complex sections
4. Update this README if adding/removing partials
5. Test after each change

## Questions?

See `DASHBOARD_CONSOLIDATION_COMPLETE.md` in session files for full consolidation details and rationale.
