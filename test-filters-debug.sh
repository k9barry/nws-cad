#!/bin/bash
# Diagnostic script to test filter updates

echo "=== Testing Filter Functionality ==="
echo ""

echo "1. Testing Dashboard Page..."
curl -s "http://localhost:8080/" > /tmp/dashboard.html
if grep -q "filter-manager.js" /tmp/dashboard.html; then
    echo "✓ FilterManager script loaded"
else
    echo "✗ FilterManager script NOT loaded"
fi

if grep -q 'id="calls-stat-total"' /tmp/dashboard.html; then
    echo "✓ Stats cards present"
else
    echo "✗ Stats cards NOT found"
fi

if grep -q 'id="clear-filters"' /tmp/dashboard.html; then
    echo "✓ Clear filters button found"
else
    echo "✗ Clear filters button NOT found"
fi

echo ""
echo "2. Testing API Endpoints..."
response=$(curl -s "http://localhost:8080/api/stats" | jq -r '.success' 2>/dev/null || echo "error")
if [ "$response" = "true" ]; then
    echo "✓ /api/stats endpoint working"
    total=$(curl -s "http://localhost:8080/api/stats" | jq -r '.data.total_calls' 2>/dev/null)
    echo "  - Total calls: $total"
else
    echo "⚠ /api/stats endpoint not returning data (might be empty DB)"
fi

echo ""
echo "3. Testing Filtered API..."
response=$(curl -s "http://localhost:8080/api/stats?date_from=2024-01-01" | jq -r '.success' 2>/dev/null || echo "error")
if [ "$response" = "true" ]; then
    echo "✓ Filtered /api/stats working"
else
    echo "⚠ Filtered API not working"
fi

echo ""
echo "4. JavaScript Files..."
for file in filter-manager dashboard dashboard-main calls units analytics; do
    if [ -f "/home/jcleaver/nws-cad/public/assets/js/${file}.js" ]; then
        lines=$(wc -l < "/home/jcleaver/nws-cad/public/assets/js/${file}.js")
        fm_refs=$(grep -c "filterManager" "/home/jcleaver/nws-cad/public/assets/js/${file}.js" 2>/dev/null || echo 0)
        echo "✓ ${file}.js ($lines lines, $fm_refs FilterManager refs)"
    else
        echo "✗ ${file}.js NOT FOUND"
    fi
done

echo ""
echo "5. Common Issues Check..."

# Check for clear-filters vs clear-filters-btn
if grep -q 'id="clear-filters-btn"' /tmp/dashboard.html; then
    echo "⚠ Found clear-filters-btn (should be clear-filters)"
else
    echo "✓ Button ID is correct (clear-filters)"
fi

# Check FilterManager initialization
if grep -q "filterManager = new FilterManager" /home/jcleaver/nws-cad/public/assets/js/dashboard-main.js; then
    echo "✓ FilterManager initialized in dashboard-main.js"
else
    echo "✗ FilterManager NOT initialized in dashboard-main.js"
fi

echo ""
echo "=== Diagnostics Complete ==="
echo ""
echo "If stats cards aren't updating:"
echo "1. Check browser console for errors (F12)"
echo "2. Verify onFilterChange callback is firing"
echo "3. Check network tab for API calls"
echo "4. Clear browser cache and reload"
