# NWS CAD Dashboard Documentation

## Overview

The NWS CAD Dashboard is a comprehensive web-based interface for visualizing and managing Computer-Aided Dispatch (CAD) data. It provides real-time monitoring, interactive maps, data analytics, and reporting capabilities.

## Features

### 1. Main Dashboard (`/`)
- **Real-time Statistics**: Active calls, available units, average response times
- **Interactive Map**: Visual display of call locations using Leaflet.js
- **Recent Calls List**: Latest 10 calls with quick details
- **Charts**: 
  - Call volume trends (line chart)
  - Call types distribution (doughnut chart)
  - Unit activity (bar chart)
- **Auto-refresh**: Updates every 30 seconds

### 2. Calls Management (`/calls`)
- **Advanced Filtering**:
  - Date range (from/to)
  - Call type
  - Status (active, pending, closed)
  - Agency
  - Priority (1-4)
  - Full-text search
- **Paginated Results**: 30 calls per page
- **Call Details Modal**: Complete call information including:
  - Call metadata
  - Location details
  - Assigned units
  - Narratives
- **Export**: CSV export of filtered calls
- **Auto-refresh**: Updates every 30 seconds

### 3. Units Status & Tracking (`/units`)
- **Unit Statistics**: Available, en route, on scene, off duty counts
- **Interactive Map**: Visual display of unit locations
- **Filtering**:
  - Unit status
  - Unit type
  - Agency
  - Search by unit ID/badge
- **Unit Details Modal**: Complete unit information including:
  - Unit metadata
  - Personnel assigned
  - Activity logs
- **Export**: CSV export of units
- **Auto-refresh**: Updates every 30 seconds

### 4. Analytics & Reports (`/analytics`)
- **Flexible Date Ranges**: Custom or quick-select periods
  - Today, Yesterday
  - Last 7/30 days
  - This/Last month
- **Key Metrics**:
  - Total calls in period
  - Average response time
  - Busiest hour
  - Most active unit
- **Charts**:
  - Call volume over time (hourly/daily/weekly)
  - Call type distribution
  - Response time analysis
  - Calls by agency
- **Top 10 Lists**:
  - Most frequent call types
  - Most active locations
  - Most active units
- **Export Options**:
  - Summary report (CSV)
  - Detailed report (CSV)
  - Print-friendly format

## Technical Architecture

### Frontend Stack
- **HTML5/CSS3**: Modern, semantic markup
- **Bootstrap 5**: Responsive UI framework
- **Vanilla JavaScript**: No heavy frameworks, lightweight and fast
- **Leaflet.js 1.9.4**: Interactive maps
- **Chart.js 4.4.0**: Data visualization

### Backend Integration
- **REST API**: All data fetched from existing NWS CAD API
- **API Base URL**: `http://localhost:8080/api`
- **CORS Enabled**: Cross-origin requests supported

### Key API Endpoints Used
```
GET /api/calls                    - List calls
GET /api/calls/{id}               - Call details
GET /api/calls/{id}/location      - Call location
GET /api/calls/{id}/units         - Call units
GET /api/calls/{id}/narratives    - Call narratives
GET /api/units                    - List units
GET /api/units/{id}               - Unit details
GET /api/units/{id}/logs          - Unit logs
GET /api/units/{id}/personnel     - Unit personnel
GET /api/stats/calls              - Call statistics
GET /api/stats/units              - Unit statistics
GET /api/stats/response-times     - Response time stats
```

## File Structure

```
public/
├── index.php                     - Dashboard entry point (routing)
├── assets/
    ├── css/
    │   ├── dashboard.css         - Main dashboard styles
    │   └── print.css             - Print-friendly styles
    └── js/
        ├── dashboard.js          - Core utilities & API functions
        ├── maps.js               - Leaflet map management
        ├── charts.js             - Chart.js integration
        ├── dashboard-main.js     - Main dashboard page logic
        ├── calls.js              - Calls page logic
        ├── units.js              - Units page logic
        └── analytics.js          - Analytics page logic

src/Dashboard/Views/
├── dashboard.php                 - Main dashboard view
├── calls.php                     - Calls list view
├── units.php                     - Units tracking view
└── analytics.php                 - Analytics view
```

## Configuration

### API Base URL
Update the API base URL in `public/index.php`:
```php
$apiBaseUrl = 'http://localhost:8080/api';
```

Or use environment variables:
```php
$apiBaseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8080/api';
```

### Auto-refresh Interval
Default: 30 seconds. Change in `public/index.php`:
```javascript
window.APP_CONFIG = {
    apiBaseUrl: '<?= $apiBaseUrl ?>',
    currentPage: '<?= $page ?>',
    refreshInterval: 30000 // Change to desired milliseconds
};
```

## Usage Guide

### Accessing the Dashboard
1. Navigate to `http://your-server/` (or configured path)
2. The main dashboard loads automatically

### Filtering Calls
1. Go to the Calls page (`/calls`)
2. Use the filter form to set criteria
3. Click "Filter" to apply
4. Click "Reset" to clear all filters

### Viewing Call Details
1. Click the eye icon (👁️) on any call row
2. Or click on a map marker
3. Modal opens with complete call information

### Viewing Unit Details
1. Go to the Units page (`/units`)
2. Click the eye icon on any unit row
3. Or click on a map marker
4. Modal opens with unit information, personnel, and logs

### Generating Analytics Reports
1. Go to Analytics page (`/analytics`)
2. Select date range (or use quick-select)
3. Click "Generate Report"
4. View charts and statistics
5. Export as CSV or print

### Exporting Data
- **Calls**: Click "Export CSV" on Calls page
- **Units**: Click "Export CSV" on Units page
- **Analytics**: Use "Summary Report" or "Detailed Report" buttons

### Printing Reports
1. Click the "Print" button in the navigation bar
2. Or use Ctrl+P / Cmd+P
3. Print-friendly styles automatically apply
4. Maps show placeholder text for digital viewing

## Customization

### Adding Custom Charts
Edit the relevant page script (e.g., `dashboard-main.js`):
```javascript
// Add new chart
const customData = {
    labels: ['A', 'B', 'C'],
    datasets: [{
        label: 'Custom Data',
        data: [10, 20, 30]
    }]
};

ChartManager.createBarChart('my-chart-id', customData);
```

### Styling
Edit `public/assets/css/dashboard.css`:
```css
/* Change primary color */
:root {
    --primary-color: #your-color;
}

/* Customize cards */
.card {
    /* Your styles */
}
```

### Adding New Pages
1. Create view in `src/Dashboard/Views/yourpage.php`
2. Add route in `public/index.php`:
   ```php
   $routes = [
       // ... existing routes
       '/yourpage' => 'yourpage',
   ];
   ```
3. Create script in `public/assets/js/yourpage.js`
4. Add navigation link in `public/index.php`

## Browser Support

- **Chrome/Edge**: ✅ Fully supported
- **Firefox**: ✅ Fully supported
- **Safari**: ✅ Fully supported
- **Mobile browsers**: ✅ Responsive design

## Performance Considerations

### Data Limits
- **Calls list**: 30 per page (pagination)
- **Map markers**: 100 max (performance)
- **Export**: 1000 records max
- **Analytics**: Date range limited by API

### Optimization Tips
1. Use pagination for large datasets
2. Apply filters to reduce data load
3. Limit date ranges in analytics
4. Clear browser cache if experiencing issues

### Auto-refresh Impact
- Refresh interval: 30 seconds
- API calls per refresh: 2-4 depending on page
- Can be disabled by stopping auto-refresh in code

## Troubleshooting

### Dashboard Not Loading
1. Check API is running: `http://localhost:8080/api`
2. Check browser console for errors (F12)
3. Verify PHP is working: `php -v`
4. Check file permissions

### Maps Not Displaying
1. Ensure internet connection (for tile loading)
2. Check browser console for Leaflet errors
3. Verify latitude/longitude data in API responses

### Charts Not Rendering
1. Check browser console for Chart.js errors
2. Ensure canvas elements exist in HTML
3. Verify data format from API

### API Errors
1. Check API status indicator in navbar
2. Verify API endpoint URLs
3. Check CORS settings if on different domain
4. Review browser network tab (F12)

### Print Issues
1. Use Chrome/Edge for best results
2. Enable background graphics in print settings
3. Use "Print" button, not browser default

## Security Considerations

1. **Input Validation**: All user inputs are sanitized
2. **XSS Prevention**: HTML escaping on all outputs
3. **CSRF**: Not implemented (read-only dashboard)
4. **API Security**: Relies on API authentication
5. **SQL Injection**: N/A (no direct database access)

## Future Enhancements

Potential additions:
- [ ] User authentication/authorization
- [ ] WebSocket for real-time updates
- [ ] Offline mode with service workers
- [ ] Mobile app (PWA)
- [ ] Advanced analytics (ML predictions)
- [ ] Custom dashboard layouts
- [ ] Alert notifications
- [ ] Audio/visual alerts for priority calls
- [ ] Multi-language support
- [ ] Dark mode

## Support & Contribution

For issues, feature requests, or contributions:
- GitHub: [repository URL]
- Documentation: `/docs/`
- API Docs: `http://localhost:8080/api/docs`

## License

See LICENSE file in repository root.

## Version History

### Version 1.0.0 (Current)
- Initial release
- Main dashboard with overview
- Calls management with filtering
- Units tracking
- Analytics and reporting
- Interactive maps
- Charts and visualizations
- CSV export
- Print-friendly layouts
- Auto-refresh functionality
- Responsive design

---

**Last Updated**: 2024
**Author**: NWS CAD Development Team
