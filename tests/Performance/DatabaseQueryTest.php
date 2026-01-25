<?php

declare(strict_types=1);

namespace NwsCad\Tests\Performance;

use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * Performance tests for database queries
 * Tests query execution times to ensure optimal performance
 */
class DatabaseQueryTest extends TestCase
{
    private static \PDO $db;
    private const MAX_QUERY_TIME_MS = 100; // Maximum acceptable query time in milliseconds
    private const BULK_INSERT_COUNT = 1000;

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
    }

    public function testSelectSingleCallPerformance(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        
        // Measure query time
        $start = microtime(true);
        
        $stmt = self::$db->prepare("SELECT * FROM calls WHERE call_number = ?");
        $stmt->execute(['CALL-001']);
        $result = $stmt->fetch();
        
        $duration = (microtime(true) - $start) * 1000; // Convert to milliseconds
        
        $this->assertNotFalse($result);
        $this->assertLessThan(
            self::MAX_QUERY_TIME_MS,
            $duration,
            "Query took {$duration}ms, expected less than " . self::MAX_QUERY_TIME_MS . "ms"
        );
    }

    public function testSelectCallsWithPaginationPerformance(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        
        for ($i = 1; $i <= 100; $i++) {
            $stmt->execute([$i, "CALL-$i", '2024-01-01 10:00:00']);
        }
        
        // Measure query time
        $start = microtime(true);
        
        $stmt = self::$db->prepare("
            SELECT * FROM calls 
            ORDER BY id DESC 
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([30, 0]);
        $results = $stmt->fetchAll();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertCount(30, $results);
        $this->assertLessThan(
            self::MAX_QUERY_TIME_MS,
            $duration,
            "Pagination query took {$duration}ms, expected less than " . self::MAX_QUERY_TIME_MS . "ms"
        );
    }

    public function testJoinCallsWithLocationsPerformance(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $callId = (int)self::$db->lastInsertId();
        
        $stmt = self::$db->prepare("
            INSERT INTO locations (call_id, full_address, city, state)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$callId, '123 Main St', 'Springfield', 'IL']);
        
        // Measure query time
        $start = microtime(true);
        
        $stmt = self::$db->prepare("
            SELECT c.*, l.full_address, l.city, l.state
            FROM calls c
            LEFT JOIN locations l ON c.id = l.call_id
            WHERE c.call_number = ?
        ");
        $stmt->execute(['CALL-001']);
        $result = $stmt->fetch();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertNotFalse($result);
        $this->assertLessThan(
            self::MAX_QUERY_TIME_MS,
            $duration,
            "JOIN query took {$duration}ms, expected less than " . self::MAX_QUERY_TIME_MS . "ms"
        );
    }

    public function testSearchQueryPerformance(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, nature_of_call, create_datetime)
            VALUES (?, ?, ?, ?)
        ");
        
        for ($i = 1; $i <= 100; $i++) {
            $stmt->execute([$i, "CALL-$i", "Medical Emergency $i", '2024-01-01 10:00:00']);
        }
        
        // Measure query time
        $start = microtime(true);
        
        $stmt = self::$db->prepare("
            SELECT * FROM calls 
            WHERE nature_of_call LIKE ?
            LIMIT 30
        ");
        $stmt->execute(['%Medical%']);
        $results = $stmt->fetchAll();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertNotEmpty($results);
        $this->assertLessThan(
            self::MAX_QUERY_TIME_MS * 2, // Allow more time for LIKE queries
            $duration,
            "Search query took {$duration}ms, expected less than " . (self::MAX_QUERY_TIME_MS * 2) . "ms"
        );
    }

    public function testAggregationQueryPerformance(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, nature_of_call, create_datetime)
            VALUES (?, ?, ?, ?)
        ");
        
        for ($i = 1; $i <= 100; $i++) {
            $nature = ($i % 2 == 0) ? 'Medical' : 'Fire';
            $stmt->execute([$i, "CALL-$i", $nature, '2024-01-01 10:00:00']);
        }
        
        // Measure query time
        $start = microtime(true);
        
        $stmt = self::$db->query("
            SELECT nature_of_call, COUNT(*) as count
            FROM calls
            GROUP BY nature_of_call
        ");
        $results = $stmt->fetchAll();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertCount(2, $results);
        $this->assertLessThan(
            self::MAX_QUERY_TIME_MS,
            $duration,
            "Aggregation query took {$duration}ms, expected less than " . self::MAX_QUERY_TIME_MS . "ms"
        );
    }

    public function testComplexJoinPerformance(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $callId = (int)self::$db->lastInsertId();
        
        $stmt = self::$db->prepare("
            INSERT INTO locations (call_id, full_address)
            VALUES (?, ?)
        ");
        $stmt->execute([$callId, '123 Main St']);
        
        $stmt = self::$db->prepare("
            INSERT INTO units (call_id, unit_number)
            VALUES (?, ?)
        ");
        $stmt->execute([$callId, 'E-101']);
        
        // Measure query time
        $start = microtime(true);
        
        $stmt = self::$db->prepare("
            SELECT c.*, l.full_address, u.unit_number
            FROM calls c
            LEFT JOIN locations l ON c.id = l.call_id
            LEFT JOIN units u ON c.id = u.call_id
            WHERE c.call_number = ?
        ");
        $stmt->execute(['CALL-001']);
        $result = $stmt->fetch();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertNotFalse($result);
        $this->assertLessThan(
            self::MAX_QUERY_TIME_MS * 2,
            $duration,
            "Complex JOIN query took {$duration}ms, expected less than " . (self::MAX_QUERY_TIME_MS * 2) . "ms"
        );
    }

    public function testDateRangeQueryPerformance(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        
        for ($i = 1; $i <= 100; $i++) {
            $date = date('Y-m-d H:i:s', strtotime("2024-01-01 10:00:00 +{$i} hours"));
            $stmt->execute([$i, "CALL-$i", $date]);
        }
        
        // Measure query time
        $start = microtime(true);
        
        $stmt = self::$db->prepare("
            SELECT * FROM calls 
            WHERE create_datetime BETWEEN ? AND ?
        ");
        $stmt->execute(['2024-01-01', '2024-01-03']);
        $results = $stmt->fetchAll();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertNotEmpty($results);
        $this->assertLessThan(
            self::MAX_QUERY_TIME_MS,
            $duration,
            "Date range query took {$duration}ms, expected less than " . self::MAX_QUERY_TIME_MS . "ms"
        );
    }
}
