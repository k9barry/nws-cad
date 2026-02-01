# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added (Dashboard Consolidation - 2026-02-01)
- 📊 **Analytics Modal** - Full analytics and charts accessible from dashboard
  - 4th stat card opens fullscreen analytics modal
  - All charts from analytics page now in modal
  - No separate analytics page needed
- 🎯 **Modular Dashboard Architecture** - Dashboard broken into 6 reusable components
  - `partials/filter-summary.php` - Active filters bar (19 lines)
  - `partials/stats-cards.php` - 4 stat cards with actions (74 lines)
  - `partials/map-and-table.php` - Map + recent calls table (57 lines)
  - `partials/filter-modal.php` - Filter configuration modal (118 lines)
  - `partials/call-detail-modal.php` - Call details modal (17 lines)
  - `partials/analytics-modal.php` - Analytics charts modal (152 lines)
  - Main dashboard.php reduced from 284 to 29 lines
  - Easier maintenance and updates to individual sections
- 🚛 **Units Quick View Popover** - Hover/click units button to see assigned units
  - Shows unit numbers, types, and current status
  - Quick status badges (Clear, On Scene, Enroute, Dispatched, Assigned)
  - Highlights primary unit
  - Link to full call details if needed
  - No need for separate action - units button and action button have different purposes

### Removed (Dashboard Consolidation - 2026-02-01)
- ❌ **Separate Page Navigation** - Removed /calls, /units, /analytics routes
  - Everything now accessible from dashboard through modals
  - Cleaner UX without page navigation
  - Faster workflows (no page loads)
- ❌ **DBeaver Service** - Removed CloudBeaver database manager
  - Removed from docker-compose.yml
  - Removed navigation link
  - Reduced docker overhead

### Added (Dashboard Interactivity - 2026-02-01)
- 🎯 **Auto-updating filter dropdowns** - Jurisdiction and Agency dropdowns now auto-reload when Quick Select period changes
  - Dropdowns immediately reflect only jurisdictions/agencies with data in selected time period
  - Filters are applied before fetching dropdown options (no invalid options shown)
  - Works seamlessly with all Quick Select periods (Today, Yesterday, Last 7 Days, etc.)
- 🪟 **Full Call Details Modal on Dashboard** - Complete call information without leaving dashboard
  - Click any table row or View button to open comprehensive modal
  - Shows all call information: Call details, Location, Caller info
  - Displays Agency Contexts, Incidents, Assigned Units with timestamps
  - Shows Persons Involved and Narratives  
  - No "View Full Details" button needed - everything is shown
  - Same rich modal as on Calls page
- 📊 **Smart stat card behaviors** - Different actions for different cards
  - **Total Calls** → Opens filter modal for custom filtering
  - **Active Calls** → Filters dashboard to active calls in-place
  - **Closed Calls** → Filters dashboard to closed calls in-place
  - **Available Units** → Navigates to Units page (unchanged)

### Changed (Dashboard Layout Optimization - 2026-02-01)
- 📊 **Dashboard Map Layout** - Optimized for Madison County viewing
  - Map reduced to 1/4 page width (col-lg-3) for more efficient use of space
  - Map height increased to 800px to show entirety of Madison County Indiana
  - Recent Calls table expanded to 3/4 page width (col-lg-9)
  - Table displays 20 calls (up from 10) with full details
  - Added sticky table header for better scrolling
  - Removed duplicate "Recent Calls" card section
  - Consolidated all call details into single comprehensive table
  - Improved space utilization: 25% map + 75% data

### Added (Database Protection - 2026-02-01)
- 🔒 **Database Backup Script** (`backup-database.sh`) - Automated database backups
  - Creates timestamped, compressed SQL dumps
  - Supports both MySQL and PostgreSQL
  - Automatic cleanup of backups older than 30 days (configurable)
  - Shows backup size and lists recent backups
  - Ready for cron automation
- 🔄 **Database Restore Script** (`restore-database.sh`) - Interactive backup restoration
  - Lists all available backups with timestamps
  - Creates safety backup before restore
  - Strong confirmation prompts (type "RESTORE")
  - Automatic database type detection
- 📚 **Backup Guide** (`docs/BACKUP_GUIDE.md`) - Complete backup/restore documentation
  - Setup instructions for automated backups
  - Recovery scenarios and troubleshooting
  - Security best practices
  - Example workflows

### Changed (Database Protection - 2026-02-01)
- ⚠️ **Enhanced reset-repo.sh** - Stronger database deletion protection
  - Added large red warning banner before database deletion
  - Requires typing "DELETE" (all caps) to confirm
  - Automatically creates backup before deletion
  - Skips database deletion in non-interactive mode (CI/CD safe)
  - Shows backup location after creation

### Fixed (Dashboard Fixes - 2026-02-01)
- ✅ **Accidental data loss prevention** - Multiple safeguards added to prevent database deletion
- ✅ **Backup directory** - Added `/backups/` to `.gitignore` to protect sensitive data
- 🔧 **Filter dropdown sync** - Fixed Quick Select not filtering Jurisdiction/Agency dropdowns
  - Dropdowns now use current date range when loading options
  - No more stale or invalid values appearing in dropdowns  
  - Date filters are applied to currentFilters state before dropdown reload
- 🔧 **Dashboard element IDs** - Fixed table not loading after modularization
  - Fixed `calls-table-body` → `recent-calls-body` (3 locations)
  - Fixed `recent-activity-title` → `recent-calls-title`
  - Fixed map element ID: `map` → `calls-map`
  - Fixed filterDashboard to actually refresh dashboard data
  - Fixed analytics top call type to use correct API field (top_call_types)
  - Recent calls, map, and stat card filtering now work correctly
- 🔧 **Analytics call counts** - Fixed inflated counts in Call Distribution chart
  - Changed `COUNT(*)` to `COUNT(DISTINCT c.id)` in top_call_types query
  - Calls with multiple agencies now counted only once
  - Distribution chart now shows accurate call counts
- 🔧 **Dozzle logs link** - Fixed navbar Logs button not working
  - Updated default port from 9999 to 8081 (external Dozzle container)
  - Link now correctly opens Dozzle log viewer

### Added (Dashboard & Filter Improvements)
- 🎯 **FilterManager Class** (`public/assets/js/filter-manager.js`) - NEW centralized filter management
  - Single source of truth for all filtering across dashboard pages
  - URL parameter support for shareable filtered views
  - Real-time debounced search (300ms) - search as you type
  - Automatic date field visibility toggle (only show when "Custom Range" selected)
  - Smart jurisdiction/agency loading from filtered results (descending sort)
  - Centralized date range calculation with proper boundaries
  - Filter validation and sanitization
  - Active filter badges with individual remove buttons
  - ~300 lines of duplicate code eliminated across 4 pages
- 🧪 **Comprehensive Filter Testing Suite** (`tests/Integration/ApiFilteringTest.php`)
  - 20+ test cases covering all filter parameters
  - Date range filtering tests (date_from, date_to, combined)
  - Status filtering tests (active/closed calls via closed_flag)
  - Agency type and jurisdiction filtering tests
  - Combined filter scenario tests (2-3 filters simultaneously)
  - Search functionality tests (LIKE queries, pattern matching)
  - SQL injection protection tests for all filter parameters
  - Edge case tests (NULL values, invalid dates, empty results, case sensitivity)
  - Performance tests for complex multi-filter queries (<100ms benchmark)
  - Seeded test data with 7 calls, multiple agencies/jurisdictions
- 📊 CI/CD status badge to README.md showing test pass/fail status
- 📚 Enhanced test documentation in tests/README.md with filter testing guide
- 🎨 CSS hover effects for clickable stat cards with smooth transitions

### Changed (Dashboard UX Improvements)
- **🔍 Complete Filter System Refactor**
  - Refactored all 4 dashboard pages to use new FilterManager class
    - `calls.js` - Calls list and tracking
    - `units.js` - Unit locations and status
    - `dashboard-main.js` - Main overview dashboard
    - `analytics.js` - Advanced analytics and reports
  - Removed ~300 lines of duplicate filter handling code
  - Unified filter behavior across all pages
  - Consistent sessionStorage and URL parameter handling
  - Real-time search now works across all pages
- **🗺️ Map Layout**: Changed from full-width (col-lg-8) to half-width portrait (col-lg-6)
  - Better fits Madison County's shape and boundaries
  - Increased height from 500px to 600px for improved visibility
  - Applied to dashboard and units pages
- **🗺️ Map Center**: Updated default center to Madison County, Indiana
  - Coordinates: 40.1184°N, 85.6900°W (Anderson, IN area)
  - Default zoom level: 10 (shows county + surrounding area)
  - Replaces generic US center (Lebanon, Kansas)
  - Applied to both map initialization and "no data" fallback
  - Updated maps.js default center
  - Updated units.js fallback center (when no units have location data)
- **📊 Stat Cards Optimization** (Dashboard page):
  - Reduced from 6 cards to 4 essential metrics
  - **Kept**: Total Calls, Active Calls, Closed Calls, Available Units
  - **Removed**: Avg Response Time, Top Call Type (less critical)
  - Changed layout from col-md-2 to col-md-3 for better spacing
  - Made all remaining cards clickable with `cursor: pointer`
  - Added visual feedback: lift effect on hover, shadow enhancement
  - Cards navigate to filtered views (e.g., Active Calls → Calls page filtered to active)
- **🧭 Navigation Cleanup**: Removed redundant page buttons from dashboard header
  - Streamlined dashboard title area (no navigation buttons)
  - Kept "Return to Dashboard" buttons on sub-pages (calls, units, analytics)
- **📱 Improved Empty States**: Enhanced "No data" messaging across all sections
  - Recent Activity sections show clear empty states
  - Tables display friendly "No data found" messages
  - Charts show "No data available" with icon

### Fixed
- ✅ **"Yesterday" filter returning 0 calls** - Fixed date range calculation to include today (yesterday through now)
- ✅ **"Yesterday" and date filters not returning all data** - Fixed API date_to comparison to include entire day by appending ' 23:59:59'
- ✅ **"Today" filter not populating call list** - Fixed date calculation with proper boundaries
- ✅ **Jurisdiction dropdown not showing filtered results** - Now loads from current filters, sorted descending
- ✅ **Custom date fields always visible** - Now hidden unless "Custom Range" selected in quick period
- ✅ **Dropdown filters not sorted** - All dropdowns now sort descending (Z-A)
- ✅ **Search field only working on submit** - Now real-time with 300ms debouncing
- ✅ **Filter inconsistency across pages** - All pages now use centralized FilterManager
- ✅ **Units page map not centering on Madison County** - Fixed fallback coordinates when no unit locations
- ✅ **Clear filters button not working** - Fixed ID mismatch (clear-filters-btn → clear-filters)
- ✅ **Stats cards not updating on filter change** - Added comprehensive debug logging to trace filter application
- ✅ Map initialization now uses Madison County coordinates by default
- ✅ Stat card hover effects properly applied with CSS
- ✅ Dashboard-main.js updated to only update 4 stat cards (removed avg response/top call type references)
- ✅ Empty data sections properly handle and display no-data states

### Improved
- 🎯 Filters now shareable via URL parameters - bookmark and share filtered views
- 🔍 Real-time search with instant feedback as you type (300ms debounce)
- 🎨 Filter badges show active filters with individual remove buttons
- 🗺️ Maps now better suited for Madison County geography (portrait vs landscape)
- 🧹 Cleaner codebase with ~300 lines of duplicate code eliminated
- 🎨 Enhanced visual feedback on interactive elements
- 🧪 Comprehensive test coverage for filtering functionality ensures reliability
- 📊 All filter parameters now thoroughly tested for security and correctness
- 🚀 Better UX with clickable stat cards providing direct navigation

## [Unreleased - Previous]

### Added
- **Dozzle Docker Log Viewer** - Real-time container log monitoring service
  - Added Dozzle service to docker-compose.yml (port 9999, localhost-only by default)
  - Added DOZZLE_PORT, DOZZLE_USERNAME, DOZZLE_PASSWORD configuration to .env.example
  - Logs link in navigation now opens Dozzle in new tab
  - Security: Binds to localhost only, supports optional authentication
- **Enhanced DEBUG Logging** - Comprehensive step-by-step logging throughout codebase
  - DEBUG level shows detailed step-by-step processing information
  - INFO level shows only major milestones
  - Updated FileWatcher.php with DEBUG logging for file scanning, stability checks, processing
  - Updated AegisXmlParser.php with DEBUG logging for XML parsing, database operations
  - Updated Database.php with DEBUG logging (sanitized, no sensitive credentials exposed)
  - Updated watcher.php with DEBUG logging for service startup
- Agency and Jurisdiction filters to Analytics page
- Call counts to Call Distribution chart labels
- Dynamic calculation of busiest hour from actual call data
- Dynamic calculation of most active unit from actual units data
- "Incidents by Jurisdiction" chart replacing "Call Volume Over Time"
- Unique constraints on units table for (call_id, unit_number)
- Unique constraints on unit_logs table for (unit_id, log_datetime, status, location)
- Unique constraints on narratives table for (call_id, create_datetime, create_user, text)
- Location field to unit_logs table to store log location data

### Changed
- Logs page replaced with Dozzle external service for real-time container log viewing
- Removed internal logs.php view and logs.js frontend components
- Logs navigation link now opens Dozzle in a new browser tab
- LOG_LEVEL environment variable now controls verbosity (DEBUG for detailed, INFO for milestones)

### Removed
- Internal logs page frontend (logs.php, logs.js) - replaced by Dozzle service
- /logs route from dashboard routing

### Fixed
- Analytics page stats calculation using correct data sources
- SQL GROUP BY compatibility with MySQL strict mode
- API jurisdiction filtering to use incidents table instead of agency_contexts
- XML file processing now appends new data instead of replacing existing records
- Unit logs and narratives are now preserved when processing updated XML files
- Units are now updated (UPSERT) rather than deleted and recreated

### Changed
- XML parser now uses INSERT IGNORE for cumulative child records (narratives, unit_logs)
- XML parser now uses UPSERT for units to update timestamps without losing child records
- Removed deleteChildRecords() method that was deleting all child data on updates
- Database schema updated to support idempotent XML imports

## [1.1.0] - 2026-01-30

### Added
- **FilenameParser utility class** for parsing CAD XML filenames
- Intelligent file version detection and processing optimization
- Automatic skipping of older file versions for the same call
- Enhanced `processed_files` table with `call_number` and `file_timestamp` columns
- Database migration scripts for MySQL and PostgreSQL (v1.1.0)
- Comprehensive documentation:
  - File Processing Optimization guide
  - Database Schema Diagram
- Test script for validating file processing optimization
- 82% reduction in file processing overhead (tested with 89 sample files)

### Changed
- FileWatcher now groups files by call number and processes only latest versions
- AegisXmlParser now stores call metadata in processed_files table
- Enhanced logging to show version analysis and skipped files
- Database indexes added for efficient call_number and file_timestamp queries

### Performance
- Processing optimization: 82% reduction in database operations
- Example: 19 versions of same call → only 1 file processed
- 73 of 89 sample files automatically skipped as older versions

## [1.0.1] - 2026-01-25

### Added
- Dashboard main page with live data refresh
- Units tracking page with real-time status
- Analytics page with comprehensive reporting
- Auto-detection of GitHub Codespaces environment for API URLs
- Comprehensive JavaScript logging for debugging

### Fixed
- Dashboard API connection issues in GitHub Codespaces
- Async/await initialization in dashboard JavaScript
- API base URL configuration for both local and Codespaces environments
- Field name mapping to match database schema

### Changed
- Improved error handling in all dashboard pages
- Enhanced logging throughout JavaScript modules
- Updated APP_CONFIG to auto-detect environment

## [1.0.0] - 2025-01-18

### Added
- Initial release
- XML file parsing and processing
- REST API with 19 endpoints
- MySQL and PostgreSQL support
- Comprehensive test suite
- CI/CD pipeline
- Documentation

## [Unreleased] - 2026-02-01

### Changed
- **Dashboard Layout Improvements**
  - Moved map to top section (40% width, 600px height)
  - Positioned 4 stat cards next to map in 2x2 grid (60% width)
  - Moved Recent Calls table to full-width below map/stats
  - Repositioned filter label to right side next to "Change Filters" button
  - Created new partials:
    - map-and-stats.php (93 lines) - Combined map + stats cards
    - recent-calls-table.php (42 lines) - Standalone table component
  - Updated partials/README.md with new structure documentation
  - Reduced table max-height from 770px to 500px for better proportions


### Fixed
- **Units Popover Bug**
  - Fixed API response format handling: Changed from `result.items` to `result.data` to match actual API response structure
  - Fixed primary unit badge: Changed from `u.primary_unit` to `u.is_primary` (correct field name)
  - Units popover now displays correctly with unit details and status


### Changed
- **Dashboard Layout Restructured**
  - Recent Calls table moved below stats cards (right column only)
  - Map height increased to 800px to fill left column
  - Layout now: Map (left 40%) | Stats Cards + Recent Calls (right 60%, stacked)
  - Table max-height reduced to 400px for better proportions
  - Removed standalone recent-calls-table.php partial (merged into map-and-stats.php)


### Changed
- **Dashboard Header Optimization**
  - Merged filter controls into header row (single row layout)
  - Dashboard title on left, filter summary and button on right
  - Removed standalone filter-summary.php partial (merged into dashboard.php)
  - Map now starts immediately below header (more vertical space)
  - Reduced header margin from mb-4 to mb-3 for tighter layout


### Fixed
- **Recent Calls Table Positioning**
  - Fixed HTML structure: Table now correctly positioned in right column below stats cards
  - Corrected closing div tags that were pushing table outside the column layout


### Added
- **Map Boundary Restrictions**
  - Map now restricted to Madison County, Indiana boundaries
  - maxBounds set to prevent panning outside county (~40mi buffer)
  - maxBoundsViscosity: 1.0 creates "hard" boundaries (can't drag outside)
  - minZoom: 9 prevents zooming out too far
  - Coordinates: SW (39.90°N, 85.90°W) to NE (40.35°N, 85.45°W)

### Confirmed
- **Units Popover "Full Details" Button**
  - Already correctly opens call details modal via viewCallDetails() function
  - No changes needed - functionality already working as intended


### Fixed
- **Units Popover "Full Details" Button**
  - Fixed button not opening call details modal
  - Added event.stopPropagation() to prevent click event from bubbling
  - Added setTimeout(100ms) to ensure popover closes before modal opens
  - Button now correctly closes popover and opens call details modal


### Changed
- **Call Details Modal Layout**
  - Moved Agency Contexts section to top (above Call Information section)
  - Provides better overview of multi-agency response context first

### Fixed
- **Units Popover "Full Details" Button (Improved Fix)**
  - Replaced inline onclick handler with proper event listener
  - Button now has ID and data-call-id attribute
  - Event listener attached after popover creation with setTimeout
  - More reliable than inline handler approach
  - Properly closes popover (150ms) then opens call details modal


### Added
- **Recent Calls Table Pagination**
  - Added Previous/Next navigation buttons
  - Shows "Page X of Y" info
  - Displays "Showing X-Y of Z calls"
  - Pagination controls only appear when there are multiple pages
  - 20 calls per page
  - Maintains current filters when navigating pages
  - Event listeners for prev/next buttons

### Changed
- **Recent Calls Table**
  - Added pagination footer below table
  - Table now supports viewing all filtered calls (not just first 20)
  - loadRecentCalls() function now accepts page parameter
  - Tracks currentCallsPage, totalCallsPages, currentCallsTotal state


### Fixed
- **Jurisdiction Filter Dropdown - Missing Jurisdictions**
  - **Root Cause**: Stats API had `LIMIT 10` on jurisdiction query, only showing top 10 most frequent jurisdictions in dropdown
  - **Impact**: Less frequent jurisdictions (like 48020) were not available as filter options
  - **Solution**: Removed LIMIT 10 from calls_by_jurisdiction query in StatsController
  - **Result**: All jurisdictions now appear in filter dropdown (went from 10 to 17 in current dataset)
  - Example: Call 1093 with jurisdiction 48020 now filterable


### Changed
- **Default Filter Period**
  - Changed default quick select filter from "Last 7 Days" to "Today"
  - Dashboard now shows today's calls by default on first load
  - User preference stored in session still takes precedence


### Fixed
- **Default Filter Initialization**
  - FilterManager now sets quick_period: 'today' when no saved filters exist
  - Dashboard immediately applies 'Today' filter on first load
  - Filter summary correctly shows "Today" instead of "All Time" on first load


### Changed
- **Map Zoom Limits**
  - Increased maximum zoom level from 19 to 21 (2 additional zoom levels)
  - Allows users to zoom in closer for more detailed street-level views
  - Both map container and tile layer maxZoom increased to 21
  - Minimum zoom (9) remains unchanged to prevent zooming out past county view


### Changed
- **Map Minimum Zoom Level**
  - Increased minimum zoom from level 9 to level 12 (3 levels more zoomed in)
  - Prevents users from zooming out to county-wide view
  - Keeps map focused on more detailed city/neighborhood level
  - Users cannot zoom out past level 12 (was level 9)
  - Both map container and tile layer minZoom increased to 12

  - Updated default starting zoom from 10 to 12 (matches new minimum)
  - Map now initializes at city/neighborhood level instead of county-wide


### Changed
- **Map Minimum Zoom Adjusted**
  - Changed minimum zoom from level 12 to level 10
  - Allows slightly more zoom out capability
  - Still prevents extreme zoom out (original was level 9)
  - Default starting zoom remains at level 10 (matches minimum)
  - Balance between detail and overview


### Changed
- **Map Zoom Level to 11**
  - Changed minimum zoom from level 10 to level 11
  - Changed default starting zoom from level 10 to level 11
  - Slightly more focused view with better detail
  - Good balance between coverage and detail for Madison County


### Changed
- **Map Zoom Level to 10.5 (Fractional)**
  - Enabled fractional zoom levels with zoomSnap: 0.5
  - Changed minimum zoom from 11 to 10.5
  - Changed default zoom from 11 to 10.5
  - Added zoomDelta: 0.5 for smoother zoom controls
  - Provides zoom level between 10 and 11 for optimal view

