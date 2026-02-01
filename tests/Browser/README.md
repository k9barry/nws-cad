# Browser Testing with Selenium

## 🎯 Overview

This directory contains browser automation tests using **Symfony Panther** (built on Selenium WebDriver). These tests validate UI interactions, JavaScript functionality, and end-to-end user workflows.

## 📋 What We Test

### Filter Functionality ✅
- ✓ "Today" filter populates call list
- ✓ Custom date fields auto-hide/show
- ✓ Real-time search with debouncing (300ms)
- ✓ Filter persistence across page navigation
- ✓ URL parameters load filters on page load
- ✓ Jurisdiction dropdown loads from filtered results
- ✓ Filter badges display and removal

### Map Interactions ✅
- ✓ Map centers on Madison County, Indiana
- ✓ Map popup opens on marker click
- ✓ View button in popup opens call details modal

### Page Navigation ✅
- ✓ All pages load correctly
- ✓ Calls page displays call list
- ✓ Units page displays unit locations
- ✓ Analytics page displays charts
- ✓ Call details modal opens and displays data

## 🚀 Quick Start

### 1. Install Dependencies
```bash
# Install Panther
docker compose exec app composer require --dev symfony/panther
```

### 2. Start Selenium
```bash
# Start Selenium container
docker compose --profile testing up -d selenium

# Verify it's running
docker compose ps selenium
```

### 3. Run Tests
```bash
# Run all browser tests
docker compose exec app vendor/bin/phpunit --testsuite Browser

# Run specific test
docker compose exec app vendor/bin/phpunit tests/Browser/ExampleTest.php

# Run with verbose output
docker compose exec app vendor/bin/phpunit --testsuite Browser --verbose
```

### 4. View Browser (Optional)
Open http://localhost:7900 in your browser
- Password: `secret`
- See tests running in real-time!

## 📁 Directory Structure

```
tests/Browser/
├── README.md                    # This file
├── ExampleTest.php              # Getting started examples
├── FilterTest.php               # Filter interaction tests
├── MapInteractionTest.php       # Map and popup tests
├── CallsPageTest.php            # Calls page tests
├── UnitsPageTest.php            # Units page tests
├── AnalyticsPageTest.php        # Analytics page tests
└── Pages/                       # Page Object Models
    ├── FilterModal.php          # Filter modal interactions
    ├── CallsPage.php            # Calls page wrapper
    └── BasePage.php             # Common page actions
```

## 📝 Writing Tests

### Basic Test Structure
```php
<?php

namespace NwsCad\Tests\Browser;

use Symfony\Component\Panther\PantherTestCase;

class MyTest extends PantherTestCase
{
    public function testSomething(): void
    {
        // 1. Create client
        $client = static::createPantherClient([
            'external_base_uri' => 'http://localhost:8080',
        ]);
        
        // 2. Navigate to page
        $client->request('GET', '/calls');
        
        // 3. Wait for elements
        $client->waitFor('#calls-table', 10);
        
        // 4. Interact with page
        $button = $client->findElement(['css' => '.btn-primary']);
        $button->click();
        
        // 5. Assert results
        $this->assertSelectorTextContains('h1', 'Expected Text');
    }
}
```

### Wait Strategies
```php
// Wait for element to appear
$client->waitFor('#element', 10); // 10 second timeout

// Wait for element to be visible
$client->waitForVisibility('#element');

// Wait for element to disappear
$client->waitForInvisibility('#loading');

// Wait for text to appear
$client->waitForElementToContain('#status', 'Complete');

// Custom JavaScript wait
$client->waitFor(function () use ($client) {
    return $client->executeScript('return document.readyState === "complete"');
});
```

### Selectors
```php
// CSS selector (recommended)
$client->findElement(['css' => '#my-id']);
$client->findElement(['css' => '.my-class']);
$client->findElement(['css' => 'button[type="submit"]']);

// XPath selector
$client->findElement(['xpath' => '//button[text()="Submit"]']);

// Find multiple elements
$elements = $client->findElements(['css' => '.list-item']);
```

### JavaScript Execution
```php
// Execute JavaScript
$result = $client->executeScript('return document.title;');

// Get sessionStorage
$filters = $client->executeScript(
    "return sessionStorage.getItem('nws_cad_filters');"
);

// Set sessionStorage
$client->executeScript(
    "sessionStorage.setItem('test_key', 'test_value');"
);

// Check if function exists
$exists = $client->executeScript('return typeof myFunction === "function";');
```

### Screenshots
```php
// Take screenshot (saved to var/screenshots/)
$client->takeScreenshot('var/screenshots/debug.png');

// Automatic screenshots on test failure (configured in phpunit.xml)
// Screenshots saved to var/error-screenshots/
```

## 🧪 Test Examples

### Example 1: Filter Modal Test
```php
public function testFilterModalOpensAndCloses(): void
{
    $client = static::createPantherClient();
    $client->request('GET', '/calls');
    
    // Open modal
    $client->findElement(['css' => '[data-bs-target="#filterModal"]'])->click();
    $client->waitFor('#filterModal.show');
    
    // Verify modal visible
    $modal = $client->findElement(['css' => '#filterModal']);
    $this->assertTrue($modal->isDisplayed());
    
    // Close modal
    $client->findElement(['css' => '#filterModal .btn-close'])->click();
    $client->waitForInvisibility('#filterModal.show');
}
```

### Example 2: Real-time Search Test
```php
public function testRealTimeSearch(): void
{
    $client = static::createPantherClient();
    $client->request('GET', '/calls');
    
    // Open filter modal
    $client->findElement(['css' => '[data-bs-target="#filterModal"]'])->click();
    $client->waitFor('#filterModal.show');
    
    // Type in search
    $searchInput = $client->findElement(['css' => '#dashboard-search']);
    $searchInput->sendKeys('Fire Department');
    
    // Wait for debounce (300ms) + processing
    $client->wait(1);
    
    // Verify URL updated
    $this->assertStringContainsString('search=Fire', $client->getCurrentURL());
}
```

### Example 3: Map Interaction Test
```php
public function testMapMarkerClickOpensPopup(): void
{
    $client = static::createPantherClient();
    $client->request('GET', '/calls');
    
    // Wait for map
    $client->waitFor('.leaflet-map-pane', 10);
    $client->wait(2); // Wait for markers
    
    // Click first marker
    $markers = $client->findElements(['css' => '.leaflet-marker-icon']);
    if (count($markers) > 0) {
        $markers[0]->click();
        
        // Verify popup appeared
        $client->waitFor('.leaflet-popup-content');
        $this->assertSelectorExists('.leaflet-popup-content button.btn-primary');
    }
}
```

## 🐛 Debugging

### View Browser in Real-Time
1. Start Selenium with VNC: `docker compose --profile testing up -d selenium`
2. Open http://localhost:7900 in browser
3. Password: `secret`
4. Run tests and watch them execute!

### Print Page Source
```php
echo $client->getPageSource();
```

### Print Console Logs
```php
$logs = $client->manage()->getLog('browser');
print_r($logs);
```

### Take Debug Screenshots
```php
$client->takeScreenshot('var/screenshots/debug-' . time() . '.png');
```

### Slow Down Tests
```php
// Add delays to see what's happening
$client->wait(2); // Wait 2 seconds
```

## 🚨 Common Issues

### Issue: Selenium not connecting
**Solution**:
```bash
# Check Selenium logs
docker compose logs selenium

# Restart Selenium
docker compose restart selenium

# Verify network
docker compose exec app ping selenium
```

### Issue: Element not found
**Solution**:
```php
// Add explicit wait
$client->waitFor('#element', 10);

// Or wait for page to settle
$client->wait(1);

// Use more specific selector
$client->findElement(['css' => '#specific-parent #specific-child']);
```

### Issue: Tests timing out
**Solution**:
```php
// Increase timeout
$client->waitFor('#element', 30); // 30 seconds

// Or in phpunit.xml
<env name="PANTHER_WEB_SERVER_STARTUP_TIMEOUT" value="60"/>
```

### Issue: JavaScript not loaded
**Solution**:
```php
// Wait for script to load
$client->wait(1);

// Check if loaded
$loaded = $client->executeScript('return typeof myFunction !== "undefined"');
if (!$loaded) {
    $this->markTestSkipped('Script not loaded');
}
```

## 📊 Test Coverage

Current browser test coverage:

- ✅ **Filter Functionality**: 90% covered
  - Quick period selection
  - Date field visibility
  - Real-time search
  - URL parameters
  - Filter persistence
  - Jurisdiction dropdown

- ✅ **Map Interactions**: 80% covered
  - Map initialization
  - Marker clicking
  - Popup display
  - View button action

- ⏳ **Page Navigation**: 60% covered
  - Basic page loads
  - Modal interactions
  - Pagination (partial)

- ⏳ **Data Display**: 50% covered
  - Stats cards
  - Tables
  - Charts (not fully covered)

## 🎯 Test Priorities

### High Priority ⚡
- [x] Filter modal interactions
- [x] Real-time search
- [x] URL parameter filtering
- [ ] Cross-page filter persistence
- [ ] Map popup → modal flow

### Medium Priority 📋
- [ ] Pagination
- [ ] Data export
- [ ] Call details modal all tabs
- [ ] Stats card navigation

### Low Priority 📝
- [ ] Chart interactions
- [ ] Responsive design testing
- [ ] Accessibility testing

## 🚀 Running Tests in CI/CD

Tests run automatically on GitHub Actions:
```yaml
# .github/workflows/browser-tests.yml
- name: Run Browser Tests
  run: vendor/bin/phpunit --testsuite Browser
```

## 📚 Resources

- [Symfony Panther Documentation](https://github.com/symfony/panther)
- [Selenium WebDriver Docs](https://www.selenium.dev/documentation/)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [NWS CAD Testing Guide](../README.md)

## 💡 Tips

1. **Always wait for elements** - Don't assume instant loading
2. **Use specific selectors** - Avoid generic classes
3. **Test one thing per test** - Keep tests focused
4. **Clean up after tests** - Reset state if needed
5. **Use Page Objects** - Reuse common interactions
6. **Take screenshots** - Especially on failures
7. **Watch tests run** - Use VNC viewer (port 7900)

## 📞 Help

For questions or issues:
1. Check this README
2. Review example tests
3. Check Selenium logs: `docker compose logs selenium`
4. Check application logs: `docker compose logs app`

---

**Happy Testing! 🧪**
