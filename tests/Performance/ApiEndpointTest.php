<?php

declare(strict_types=1);

namespace NwsCad\Tests\Performance;

use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * Performance tests for API endpoints
 * Tests response times to ensure acceptable performance
 */
class ApiEndpointTest extends TestCase
{
    private static \PDO $db;
    private const MAX_RESPONSE_TIME_MS = 200; // Maximum acceptable API response time

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        
        if (!getenv('MYSQL_HOST')) {
            self::markTestSkipped('Database not configured for testing');
            return;
        }

        try {
            self::$db = Database::getConnection();
            cleanTestDatabase();
            self::seedTestData();
        } catch (\Exception $e) {
            self::markTestSkipped('Database connection failed: ' . $e->getMessage());
        }
    }

    private static function seedTestData(): void
    {
        // Insert test calls
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, nature_of_call, create_datetime)
            VALUES (?, ?, ?, ?)
        ");
        
        for ($i = 1; $i <= 100; $i++) {
            $stmt->execute([
                $i,
                sprintf('CALL-%03d', $i),
                'Medical Emergency',
                '2024-01-01 10:00:00'
            ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        if (!isset(self::$db)) {
            $this->markTestSkipped('Database not available');
        }
    }

    public function testListCallsEndpointPerformance(): void
    {
        $start = microtime(true);
        
        // Simulate /api/calls endpoint
        $stmt = self::$db->prepare("
            SELECT * FROM calls 
            ORDER BY id DESC 
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([30, 0]);
        $results = $stmt->fetchAll();
        
        // Get total count
        $countStmt = self::$db->query("SELECT COUNT(*) as total FROM calls");
        $total = $countStmt->fetch();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertNotEmpty($results);
        $this->assertLessThan(
            self::MAX_RESPONSE_TIME_MS,
            $duration,
            "List calls endpoint took {$duration}ms, expected less than " . self::MAX_RESPONSE_TIME_MS . "ms"
        );
    }

    public function testGetSingleCallEndpointPerformance(): void
    {
        $start = microtime(true);
        
        // Simulate /api/calls/{id} endpoint
        $stmt = self::$db->prepare("
            SELECT c.*, 
                   (SELECT COUNT(*) FROM units WHERE call_id = c.id) as unit_count
            FROM calls c
            WHERE c.id = ?
        ");
        $stmt->execute([1]);
        $result = $stmt->fetch();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertNotFalse($result);
        $this->assertLessThan(
            self::MAX_RESPONSE_TIME_MS,
            $duration,
            "Get single call endpoint took {$duration}ms, expected less than " . self::MAX_RESPONSE_TIME_MS . "ms"
        );
    }

    public function testSearchEndpointPerformance(): void
    {
        $start = microtime(true);
        
        // Simulate /api/search endpoint
        $searchTerm = 'Medical';
        $stmt = self::$db->prepare("
            SELECT * FROM calls 
            WHERE nature_of_call LIKE ? 
               OR call_number LIKE ?
               OR caller_name LIKE ?
            ORDER BY create_datetime DESC
            LIMIT 30
        ");
        $searchParam = "%{$searchTerm}%";
        $stmt->execute([$searchParam, $searchParam, $searchParam]);
        $results = $stmt->fetchAll();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertNotEmpty($results);
        $this->assertLessThan(
            self::MAX_RESPONSE_TIME_MS * 1.5, // Allow slightly more time for search
            $duration,
            "Search endpoint took {$duration}ms, expected less than " . (self::MAX_RESPONSE_TIME_MS * 1.5) . "ms"
        );
    }

    public function testStatsEndpointPerformance(): void
    {
        $start = microtime(true);
        
        // Simulate /api/stats endpoint with multiple aggregations
        $stats = [];
        
        // Total calls
        $stmt = self::$db->query("SELECT COUNT(*) as total FROM calls");
        $stats['total_calls'] = $stmt->fetch()['total'];
        
        // Calls by type
        $stmt = self::$db->query("
            SELECT nature_of_call, COUNT(*) as count
            FROM calls
            GROUP BY nature_of_call
        ");
        $stats['by_type'] = $stmt->fetchAll();
        
        // Calls by date
        $stmt = self::$db->query("
            SELECT DATE(create_datetime) as date, COUNT(*) as count
            FROM calls
            GROUP BY date
            LIMIT 30
        ");
        $stats['by_date'] = $stmt->fetchAll();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertNotEmpty($stats);
        $this->assertLessThan(
            self::MAX_RESPONSE_TIME_MS * 2, // Allow more time for stats
            $duration,
            "Stats endpoint took {$duration}ms, expected less than " . (self::MAX_RESPONSE_TIME_MS * 2) . "ms"
        );
    }

    public function testFilteredListPerformance(): void
    {
        $start = microtime(true);
        
        // Simulate /api/calls?nature_of_call=Medical&date_from=2024-01-01
        $stmt = self::$db->prepare("
            SELECT * FROM calls 
            WHERE nature_of_call = ?
              AND create_datetime >= ?
            ORDER BY create_datetime DESC
            LIMIT 30
        ");
        $stmt->execute(['Medical Emergency', '2024-01-01']);
        $results = $stmt->fetchAll();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertNotEmpty($results);
        $this->assertLessThan(
            self::MAX_RESPONSE_TIME_MS,
            $duration,
            "Filtered list took {$duration}ms, expected less than " . self::MAX_RESPONSE_TIME_MS . "ms"
        );
    }

    public function testUnitsForCallPerformance(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO units (call_id, unit_number, unit_type)
            VALUES (?, ?, ?)
        ");
        
        for ($i = 1; $i <= 5; $i++) {
            $stmt->execute([1, "UNIT-$i", 'Engine']);
        }
        
        $start = microtime(true);
        
        // Simulate /api/calls/{id}/units endpoint
        $stmt = self::$db->prepare("
            SELECT u.*, 
                   (SELECT COUNT(*) FROM unit_personnel WHERE unit_id = u.id) as personnel_count
            FROM units u
            WHERE u.call_id = ?
            ORDER BY u.unit_number
        ");
        $stmt->execute([1]);
        $results = $stmt->fetchAll();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertNotEmpty($results);
        $this->assertLessThan(
            self::MAX_RESPONSE_TIME_MS,
            $duration,
            "Units for call endpoint took {$duration}ms, expected less than " . self::MAX_RESPONSE_TIME_MS . "ms"
        );
    }

    public function testConcurrentQueriesPerformance(): void
    {
        $start = microtime(true);
        
        // Simulate multiple concurrent operations
        $queries = [
            "SELECT COUNT(*) FROM calls",
            "SELECT * FROM calls ORDER BY id DESC LIMIT 10",
            "SELECT nature_of_call, COUNT(*) as count FROM calls GROUP BY nature_of_call",
        ];
        
        $results = [];
        foreach ($queries as $query) {
            $stmt = self::$db->query($query);
            $results[] = $stmt->fetchAll();
        }
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertCount(3, $results);
        $this->assertLessThan(
            self::MAX_RESPONSE_TIME_MS * 2,
            $duration,
            "Concurrent queries took {$duration}ms, expected less than " . (self::MAX_RESPONSE_TIME_MS * 2) . "ms"
        );
    }
}
