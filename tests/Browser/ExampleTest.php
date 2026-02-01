<?php

namespace NwsCad\Tests\Browser;

use Symfony\Component\Panther\PantherTestCase;

/**
 * Example Browser Test - Getting Started
 * 
 * Run with: vendor/bin/phpunit tests/Browser/ExampleTest.php
 */
class ExampleTest extends PantherTestCase
{
    private static string $baseUrl = 'http://localhost:8080';
    
    /**
     * Test: Dashboard page loads successfully
     */
    public function testDashboardLoads(): void
    {
        // Create browser client
        $client = static::createPantherClient([
            'browser' => static::CHROME,
            'external_base_uri' => self::$baseUrl,
        ]);
        
        // Navigate to dashboard
        $crawler = $client->request('GET', '/');
        
        // Wait for page to load
        $client->waitFor('h1', 10);
        
        // Verify page title
        $this->assertSelectorTextContains('h1', 'CAD Dashboard');
        
        // Verify FilterManager loaded
        $filterManagerLoaded = $client->executeScript(
            'return typeof FilterManager !== "undefined";'
        );
        $this->assertTrue($filterManagerLoaded, 'FilterManager should be loaded');
        
        echo "\n✓ Dashboard loads successfully\n";
        echo "✓ FilterManager loaded\n";
    }
    
    /**
     * Test: Filter modal opens
     */
    public function testFilterModalOpens(): void
    {
        $client = static::createPantherClient([
            'external_base_uri' => self::$baseUrl,
        ]);
        
        $client->request('GET', '/calls');
        $client->waitFor('#calls-table', 10);
        
        // Find and click filter button
        $filterButton = $client->findElement([
            'css' => '[data-bs-target="#filterModal"]'
        ]);
        $filterButton->click();
        
        // Wait for modal to appear
        $client->waitFor('#filterModal.show', 5);
        
        // Verify modal is visible
        $modal = $client->findElement(['css' => '#filterModal']);
        $this->assertTrue($modal->isDisplayed(), 'Filter modal should be visible');
        
        echo "\n✓ Filter modal opens successfully\n";
    }
    
    /**
     * Test: Real-time search works
     */
    public function testRealTimeSearch(): void
    {
        $client = static::createPantherClient([
            'external_base_uri' => self::$baseUrl,
        ]);
        
        $client->request('GET', '/calls');
        $client->waitFor('#calls-table', 10);
        
        // Open filter modal
        $client->findElement(['css' => '[data-bs-target="#filterModal"]'])->click();
        $client->waitFor('#filterModal.show', 5);
        
        // Type in search field
        $searchInput = $client->findElement(['css' => '#dashboard-search']);
        $searchInput->sendKeys('Fire');
        
        // Wait for debounce (300ms) + processing
        $client->wait(1);
        
        // Check sessionStorage
        $filters = $client->executeScript(
            "return sessionStorage.getItem('nws_cad_filters');"
        );
        
        $this->assertNotNull($filters, 'Filters should be in sessionStorage');
        $this->assertStringContainsString('Fire', $filters, 'Search term should be in filters');
        
        echo "\n✓ Real-time search works\n";
        echo "  - Debounce: 300ms\n";
        echo "  - SessionStorage updated\n";
    }
    
    /**
     * Test: URL parameters load filters
     */
    public function testURLParametersLoadFilters(): void
    {
        $client = static::createPantherClient([
            'external_base_uri' => self::$baseUrl,
        ]);
        
        // Visit with URL parameters
        $client->request('GET', '/calls?status=active&jurisdiction=Anderson');
        $client->waitFor('#calls-table', 10);
        
        // Wait for FilterManager to process URL params
        $client->wait(1);
        
        // Check sessionStorage
        $filters = $client->executeScript(
            "return sessionStorage.getItem('nws_cad_filters');"
        );
        
        $this->assertNotNull($filters, 'Filters should be loaded from URL');
        
        $filtersArray = json_decode($filters, true);
        $this->assertEquals('active', $filtersArray['status'] ?? null, 'Status should be active');
        $this->assertEquals('Anderson', $filtersArray['jurisdiction'] ?? null, 'Jurisdiction should be Anderson');
        
        echo "\n✓ URL parameters load filters correctly\n";
        echo "  - status: active\n";
        echo "  - jurisdiction: Anderson\n";
    }
}
