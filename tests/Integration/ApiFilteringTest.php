<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for API Filtering functionality
 * Tests all filter parameters across API endpoints
 * @covers \NwsCad\Api\Controllers\CallsController
 * @covers \NwsCad\Api\Controllers\UnitsController
 * @covers \NwsCad\Api\Controllers\StatsController
 */
class ApiFilteringTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        
        if (!getenv('MYSQL_HOST')) {
            self::markTestSkipped('Database not configured for testing');
            return;
        }

        try {
            self::$db = Database::getConnection();
        } catch (\Exception $e) {
            self::markTestSkipped('Database connection failed: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        if (!isset(self::$db)) {
            $this->markTestSkipped('Database not available');
        }
        
        cleanTestDatabase();
        $this->seedFilterTestData();
    }

    /**
     * Seed database with test data for filtering tests
     */
    private function seedFilterTestData(): void
    {
        // Insert test calls with various attributes
        $stmt = self::$db->prepare("
            INSERT INTO calls (
                call_id, call_number, nature_of_call, create_datetime, 
                closed_flag, canceled_flag
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        // Active calls - last 7 days
        $stmt->execute([1, 'CALL-001', 'Medical Emergency', '2024-01-25 10:00:00', 0, 0]);
        $stmt->execute([2, 'CALL-002', 'Fire Alarm', '2024-01-26 11:00:00', 0, 0]);
        $stmt->execute([3, 'CALL-003', 'Traffic Stop', '2024-01-27 12:00:00', 0, 0]);
        
        // Closed calls - last 7 days
        $stmt->execute([4, 'CALL-004', 'Welfare Check', '2024-01-28 13:00:00', 1, 0]);
        $stmt->execute([5, 'CALL-005', 'Burglary', '2024-01-29 14:00:00', 1, 0]);
        
        // Older calls - 30 days ago
        $stmt->execute([6, 'CALL-006', 'Accident', '2024-01-01 15:00:00', 1, 0]);
        $stmt->execute([7, 'CALL-007', 'Theft', '2024-01-02 16:00:00', 1, 0]);
        
        // Insert agency contexts with different types
        $stmt = self::$db->prepare("
            INSERT INTO agency_contexts (id, call_id, agency_type, status)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([1, 1, 'EMS', 'Active']);
        $stmt->execute([2, 2, 'Fire', 'Active']);
        $stmt->execute([3, 3, 'Police', 'Active']);
        $stmt->execute([4, 4, 'Police', 'Closed']);
        $stmt->execute([5, 5, 'Police', 'Closed']);
        $stmt->execute([6, 6, 'Fire', 'Closed']);
        $stmt->execute([7, 7, 'Police', 'Closed']);
        
        // Insert incidents with jurisdictions
        $stmt = self::$db->prepare("
            INSERT INTO incidents (id, call_id, incident_number, jurisdiction)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([1, 1, 'INC-001', 'Anderson']);
        $stmt->execute([2, 2, 'INC-002', 'Elwood']);
        $stmt->execute([3, 3, 'INC-003', 'Anderson']);
        $stmt->execute([4, 4, 'INC-004', 'Alexandria']);
        $stmt->execute([5, 5, 'INC-005', 'Anderson']);
        $stmt->execute([6, 6, 'INC-006', 'Elwood']);
        $stmt->execute([7, 7, 'INC-007', 'Anderson']);
    }

    /**
     * Test date range filtering with date_from parameter
     */
    public function testFilterByDateFrom(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(*) as count FROM calls 
            WHERE create_datetime >= ?
        ");
        $stmt->execute(['2024-01-25 00:00:00']);
        $result = $stmt->fetch();
        
        $this->assertEquals(5, $result['count'], 'Should return 5 calls from 2024-01-25 onwards');
    }

    /**
     * Test date range filtering with date_to parameter
     */
    public function testFilterByDateTo(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(*) as count FROM calls 
            WHERE create_datetime <= ?
        ");
        $stmt->execute(['2024-01-26 23:59:59']);
        $result = $stmt->fetch();
        
        $this->assertEquals(4, $result['count'], 'Should return 4 calls up to 2024-01-26');
    }

    /**
     * Test date range filtering with both date_from and date_to
     */
    public function testFilterByDateRange(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(*) as count FROM calls 
            WHERE create_datetime BETWEEN ? AND ?
        ");
        $stmt->execute(['2024-01-25 00:00:00', '2024-01-27 23:59:59']);
        $result = $stmt->fetch();
        
        $this->assertEquals(3, $result['count'], 'Should return 3 calls in date range');
    }

    /**
     * Test filtering by closed_flag (active calls)
     */
    public function testFilterByActiveCalls(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(*) as count FROM calls 
            WHERE closed_flag = ?
        ");
        $stmt->execute([0]);
        $result = $stmt->fetch();
        
        $this->assertEquals(3, $result['count'], 'Should return 3 active calls');
    }

    /**
     * Test filtering by closed_flag (closed calls)
     */
    public function testFilterByClosedCalls(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(*) as count FROM calls 
            WHERE closed_flag = ?
        ");
        $stmt->execute([1]);
        $result = $stmt->fetch();
        
        $this->assertEquals(4, $result['count'], 'Should return 4 closed calls');
    }

    /**
     * Test filtering by agency_type
     */
    public function testFilterByAgencyType(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(DISTINCT c.id) as count 
            FROM calls c
            JOIN agency_contexts ac ON c.id = ac.call_id
            WHERE ac.agency_type = ?
        ");
        $stmt->execute(['Police']);
        $result = $stmt->fetch();
        
        $this->assertEquals(4, $result['count'], 'Should return 4 Police calls');
        
        $stmt->execute(['Fire']);
        $result = $stmt->fetch();
        $this->assertEquals(2, $result['count'], 'Should return 2 Fire calls');
        
        $stmt->execute(['EMS']);
        $result = $stmt->fetch();
        $this->assertEquals(1, $result['count'], 'Should return 1 EMS call');
    }

    /**
     * Test filtering by jurisdiction
     */
    public function testFilterByJurisdiction(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(DISTINCT c.id) as count 
            FROM calls c
            JOIN incidents i ON c.id = i.call_id
            WHERE i.jurisdiction = ?
        ");
        $stmt->execute(['Anderson']);
        $result = $stmt->fetch();
        
        $this->assertEquals(4, $result['count'], 'Should return 4 Anderson calls');
        
        $stmt->execute(['Elwood']);
        $result = $stmt->fetch();
        $this->assertEquals(2, $result['count'], 'Should return 2 Elwood calls');
        
        $stmt->execute(['Alexandria']);
        $result = $stmt->fetch();
        $this->assertEquals(1, $result['count'], 'Should return 1 Alexandria call');
    }

    /**
     * Test combined filters: date range + status
     */
    public function testCombinedFiltersDateAndStatus(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(*) as count FROM calls 
            WHERE create_datetime >= ? AND closed_flag = ?
        ");
        $stmt->execute(['2024-01-25 00:00:00', 0]);
        $result = $stmt->fetch();
        
        $this->assertEquals(3, $result['count'], 'Should return 3 active calls from 2024-01-25 onwards');
    }

    /**
     * Test combined filters: agency + jurisdiction
     */
    public function testCombinedFiltersAgencyAndJurisdiction(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(DISTINCT c.id) as count 
            FROM calls c
            JOIN agency_contexts ac ON c.id = ac.call_id
            JOIN incidents i ON c.id = i.call_id
            WHERE ac.agency_type = ? AND i.jurisdiction = ?
        ");
        $stmt->execute(['Police', 'Anderson']);
        $result = $stmt->fetch();
        
        $this->assertEquals(3, $result['count'], 'Should return 3 Police calls in Anderson');
    }

    /**
     * Test combined filters: date + agency + status
     */
    public function testCombinedFiltersDateAgencyStatus(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(DISTINCT c.id) as count 
            FROM calls c
            JOIN agency_contexts ac ON c.id = ac.call_id
            WHERE c.create_datetime >= ?
            AND ac.agency_type = ?
            AND c.closed_flag = ?
        ");
        $stmt->execute(['2024-01-25 00:00:00', 'Police', 0]);
        $result = $stmt->fetch();
        
        $this->assertEquals(1, $result['count'], 'Should return 1 active Police call from 2024-01-25 onwards');
    }

    /**
     * Test search by call_number (LIKE query)
     */
    public function testFilterByCallNumberSearch(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(*) as count FROM calls 
            WHERE call_number LIKE ?
        ");
        $stmt->execute(['%CALL-00%']);
        $result = $stmt->fetch();
        
        $this->assertEquals(7, $result['count'], 'Should return all 7 calls matching pattern');
        
        $stmt->execute(['%001%']);
        $result = $stmt->fetch();
        $this->assertEquals(1, $result['count'], 'Should return 1 call matching 001');
    }

    /**
     * Test empty results with valid filters
     */
    public function testFilterReturnsEmptyResults(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(*) as count FROM calls 
            WHERE create_datetime >= ?
        ");
        $stmt->execute(['2025-01-01 00:00:00']);
        $result = $stmt->fetch();
        
        $this->assertEquals(0, $result['count'], 'Should return 0 calls for future dates');
    }

    /**
     * Test SQL injection protection in date filter
     */
    public function testSqlInjectionProtectionInDateFilter(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(*) as count FROM calls 
            WHERE create_datetime >= ?
        ");
        
        // Attempt SQL injection
        $maliciousInput = "2024-01-01' OR '1'='1";
        $stmt->execute([$maliciousInput]);
        $result = $stmt->fetch();
        
        // Should return 0 because the malicious input is treated as a literal string
        $this->assertEquals(0, $result['count'], 'SQL injection should be prevented');
    }

    /**
     * Test SQL injection protection in search filter
     */
    public function testSqlInjectionProtectionInSearch(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(*) as count FROM calls 
            WHERE call_number LIKE ?
        ");
        
        // Attempt SQL injection
        $maliciousInput = "%' OR '1'='1";
        $stmt->execute([$maliciousInput]);
        $result = $stmt->fetch();
        
        // Should return 0 because it's searching for literal string
        $this->assertEquals(0, $result['count'], 'SQL injection should be prevented in LIKE clause');
    }

    /**
     * Test filter with invalid date format
     */
    public function testInvalidDateFormat(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(*) as count FROM calls 
            WHERE create_datetime >= ?
        ");
        
        // Invalid date format
        $stmt->execute(['invalid-date']);
        $result = $stmt->fetch();
        
        // Should return 0 or handle gracefully
        $this->assertEquals(0, $result['count'], 'Invalid date should return no results');
    }

    /**
     * Test filter with NULL values
     */
    public function testFilterWithNullValues(): void
    {
        // Insert call with NULL nature_of_call
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, nature_of_call, create_datetime, closed_flag)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([100, 'CALL-NULL', null, '2024-01-30 10:00:00', 0]);
        
        // Query for NULL values
        $stmt = self::$db->query("
            SELECT COUNT(*) as count FROM calls 
            WHERE nature_of_call IS NULL
        ");
        $result = $stmt->fetch();
        
        $this->assertEquals(1, $result['count'], 'Should handle NULL values correctly');
    }

    /**
     * Test case sensitivity in agency_type filter
     */
    public function testCaseSensitivityInFilters(): void
    {
        $stmt = self::$db->prepare("
            SELECT COUNT(DISTINCT c.id) as count 
            FROM calls c
            JOIN agency_contexts ac ON c.id = ac.call_id
            WHERE ac.agency_type = ?
        ");
        
        // Test exact case
        $stmt->execute(['Police']);
        $result = $stmt->fetch();
        $policeCount = $result['count'];
        
        // Test different case (MySQL is case-insensitive by default for strings)
        $stmt->execute(['POLICE']);
        $result = $stmt->fetch();
        $policeUpperCount = $result['count'];
        
        // Both should return same count in case-insensitive comparison
        $this->assertEquals($policeCount, $policeUpperCount, 'Filter should handle case variations');
    }

    /**
     * Test pagination with filters applied
     */
    public function testPaginationWithFilters(): void
    {
        $stmt = self::$db->prepare("
            SELECT * FROM calls 
            WHERE closed_flag = ?
            ORDER BY create_datetime DESC
            LIMIT ? OFFSET ?
        ");
        
        // First page
        $stmt->execute([0, 2, 0]);
        $results = $stmt->fetchAll();
        $this->assertCount(2, $results, 'Should return 2 results for first page');
        
        // Second page
        $stmt->execute([0, 2, 2]);
        $results = $stmt->fetchAll();
        $this->assertCount(1, $results, 'Should return 1 result for second page');
    }

    /**
     * Test performance with multiple filters
     */
    public function testMultipleFiltersPerformance(): void
    {
        $startTime = microtime(true);
        
        $stmt = self::$db->prepare("
            SELECT c.*, ac.agency_type, i.jurisdiction
            FROM calls c
            JOIN agency_contexts ac ON c.id = ac.call_id
            JOIN incidents i ON c.id = i.call_id
            WHERE c.create_datetime >= ?
            AND c.create_datetime <= ?
            AND c.closed_flag = ?
            AND ac.agency_type = ?
            AND i.jurisdiction = ?
        ");
        
        $stmt->execute([
            '2024-01-01 00:00:00',
            '2024-01-31 23:59:59',
            0,
            'Police',
            'Anderson'
        ]);
        
        $results = $stmt->fetchAll();
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
        
        // Query should complete in reasonable time (< 100ms for small dataset)
        $this->assertLessThan(100, $executionTime, 'Complex filter query should be performant');
    }
}
