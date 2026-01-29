# NWS CAD Dashboard - Quick Start Guide

## Installation

The dashboard is already integrated into the NWS CAD system. No additional installation required.

## File Structure

```
public/
├── index.php                 # Dashboard entry point
├── api.php                   # API entry point
├── .htaccess                 # Apache rewrite rules
└── assets/
    ├── css/
    │   ├── dashboard.css     # Main styles
    │   └── print.css         # Print styles
    └── js/
        ├── dashboard.js      # Core utilities
        ├── maps.js           # Map functionality
        ├── charts.js         # Chart functionality
        ├── dashboard-main.js # Main page
        ├── calls.js          # Calls page
        ├── units.js          # Units page
        └── analytics.js      # Analytics page

src/Dashboard/Views/
├── dashboard.php             # Main dashboard view
├── calls.php                 # Calls view
├── units.php                 # Units view
└── analytics.php             # Analytics view

docs/
└── DASHBOARD.md              # Complete documentation
```

## Starting the Dashboard

### Option 1: Using PHP Built-in Server

```bash
# From project root
cd public
php -S localhost:8080

# Access dashboard at: http://localhost:8080/
# Access API at: http://localhost:8080/api/
```

### Option 2: Using Docker

```bash
# From project root
docker-compose up -d

# Access dashboard at: http://localhost:8080/
# Access API at: http://localhost:8080/api/
```

### Option 3: Using Apache/Nginx

Configure your web server to point to the `public/` directory as the document root.

**Apache Example:**
```apache
<VirtualHost *:80>
    ServerName nws-cad.local
    DocumentRoot /path/to/nws-cad/public
    
    <Directory /path/to/nws-cad/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Nginx Example:**
```nginx
server {
    listen 80;
    server_name nws-cad.local;
    root /path/to/nws-cad/public;
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location /api {
        try_files $uri $uri/ /api.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
    }
}
```

## Quick Tour

### 1. Main Dashboard (`/`)
- View active calls count
- See available units
- Check average response time
- Monitor call locations on map
- View recent calls and trends

### 2. Calls Page (`/calls`)
- Browse all calls
- Filter by date, type, status, agency, priority
- View detailed call information
- Export to CSV

### 3. Units Page (`/units`)
- Monitor unit status
- Track unit locations
- Filter by status, type, agency
- View unit details and activity logs
- Export to CSV

### 4. Analytics Page (`/analytics`)
- Generate reports for custom date ranges
- View call volume trends by jurisdiction
- Analyze response times
- See top call types, locations, and units
- Export reports

## Configuration

### Change API URL
Edit `public/index.php`:
```php
// Line ~18
$apiBaseUrl = 'http://your-api-url/api';
```

### Change Auto-Refresh Interval
Edit `public/index.php`:
```javascript
// Line ~106
refreshInterval: 30000 // Change to desired milliseconds
```

### Customize Colors
Edit `public/assets/css/dashboard.css`:
```css
:root {
    --primary-color: #0d6efd;   /* Change primary color */
    --success-color: #198754;   /* Change success color */
    /* ... etc */
}
```

## Requirements

- PHP 8.3+
- Web server (Apache with mod_rewrite or Nginx)
- Modern web browser (Chrome, Firefox, Safari, Edge)
- Internet connection (for CDN resources: Bootstrap, Leaflet, Chart.js)

## Troubleshooting

### Dashboard shows "Page not found"
- Check that `.htaccess` exists in `public/` directory
- Ensure Apache `mod_rewrite` is enabled
- Verify document root is set to `public/` directory

### API Status shows "Offline"
- Ensure API is running at configured URL
- Check CORS settings
- Verify network connectivity

### Maps not loading
- Check internet connection (required for map tiles)
- Verify browser console for JavaScript errors
- Ensure calls/units have valid latitude/longitude data

### Charts not rendering
- Verify Chart.js is loading (check browser console)
- Ensure API returns valid data
- Check canvas elements exist in HTML

## Features

✅ **Real-time Updates** - Auto-refresh every 30 seconds  
✅ **Interactive Maps** - Leaflet.js with call/unit markers  
✅ **Data Visualization** - Chart.js for trends and analytics  
✅ **Advanced Filtering** - Multiple filter options on all pages  
✅ **Export to CSV** - Download calls, units, and reports  
✅ **Print-Friendly** - Optimized layouts for printing  
✅ **Responsive Design** - Works on desktop, tablet, mobile  
✅ **Modal Details** - Quick view of call/unit information  

## Next Steps

- Read the complete [Dashboard Documentation](../docs/DASHBOARD.md)
- Explore the [API Documentation](http://localhost:8080/api/docs)
- Customize the dashboard to your needs
- Set up authentication if required
- Configure production environment

## Support

For detailed documentation, see `docs/DASHBOARD.md`

For API documentation, visit `http://localhost:8080/api/docs`

---

**Happy monitoring! 🚨📊🗺️**
