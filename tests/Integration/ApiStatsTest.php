<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Stats API endpoints
 * @covers \NwsCad\Api\Controllers\StatsController
 */
class ApiStatsTest extends TestCase
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
    }

    public function testCanCountTotalCalls(): void
    {
        // Insert calls
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        
        for ($i = 1; $i <= 10; $i++) {
            $stmt->execute([$i, "CALL-$i", '2024-01-01 10:00:00']);
        }
        
        // Get count
        $stmt = self::$db->query("SELECT COUNT(*) as total FROM calls");
        $result = $stmt->fetch();
        
        $this->assertEquals(10, $result['total']);
    }

    public function testCanCountCallsByType(): void
    {
        // Insert calls with different types
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, nature_of_call, create_datetime)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([1, 'CALL-001', 'Medical', '2024-01-01 10:00:00']);
        $stmt->execute([2, 'CALL-002', 'Medical', '2024-01-01 11:00:00']);
        $stmt->execute([3, 'CALL-003', 'Fire', '2024-01-01 12:00:00']);
        $stmt->execute([4, 'CALL-004', 'Medical', '2024-01-01 13:00:00']);
        
        // Group by type
        $stmt = self::$db->query("
            SELECT nature_of_call, COUNT(*) as count
            FROM calls
            GROUP BY nature_of_call
            ORDER BY count DESC
        ");
        $results = $stmt->fetchAll();
        
        $this->assertCount(2, $results);
        $this->assertEquals('Medical', $results[0]['nature_of_call']);
        $this->assertEquals(3, $results[0]['count']);
        $this->assertEquals('Fire', $results[1]['nature_of_call']);
        $this->assertEquals(1, $results[1]['count']);
    }

    public function testCanCalculateAverageResponseTime(): void
    {
        // Insert call
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $callId = (int)self::$db->lastInsertId();
        
        // Insert unit with timestamps
        $stmt = self::$db->prepare("
            INSERT INTO units (
                call_id, unit_number, 
                dispatch_datetime, arrive_datetime
            ) VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $callId, 'E-101',
            '2024-01-01 10:01:00',
            '2024-01-01 10:08:00'
        ]);
        
        // Calculate response time
        $stmt = self::$db->query("
            SELECT 
                AVG(TIMESTAMPDIFF(MINUTE, dispatch_datetime, arrive_datetime)) as avg_response_time
            FROM units
            WHERE dispatch_datetime IS NOT NULL 
            AND arrive_datetime IS NOT NULL
        ");
        $result = $stmt->fetch();
        
        $this->assertEquals(7, (int)$result['avg_response_time']);
    }

    public function testCanCountCallsByHourOfDay(): void
    {
        // Insert calls at different hours
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $stmt->execute([2, 'CALL-002', '2024-01-01 10:30:00']);
        $stmt->execute([3, 'CALL-003', '2024-01-01 14:00:00']);
        
        // Group by hour
        $stmt = self::$db->query("
            SELECT 
                HOUR(create_datetime) as hour,
                COUNT(*) as count
            FROM calls
            GROUP BY hour
            ORDER BY hour
        ");
        $results = $stmt->fetchAll();
        
        $this->assertCount(2, $results);
        $this->assertEquals(10, $results[0]['hour']);
        $this->assertEquals(2, $results[0]['count']);
        $this->assertEquals(14, $results[1]['hour']);
        $this->assertEquals(1, $results[1]['count']);
    }

    public function testCanCountCallsByDay(): void
    {
        // Insert calls on different days
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $stmt->execute([2, 'CALL-002', '2024-01-01 11:00:00']);
        $stmt->execute([3, 'CALL-003', '2024-01-02 10:00:00']);
        
        // Group by date
        $stmt = self::$db->query("
            SELECT 
                DATE(create_datetime) as date,
                COUNT(*) as count
            FROM calls
            GROUP BY date
            ORDER BY date
        ");
        $results = $stmt->fetchAll();
        
        $this->assertCount(2, $results);
        $this->assertEquals(2, $results[0]['count']);
        $this->assertEquals(1, $results[1]['count']);
    }

    public function testCanCountActiveUnits(): void
    {
        // Insert call
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $callId = (int)self::$db->lastInsertId();
        
        // Insert units - some active, some cleared
        $stmt = self::$db->prepare("
            INSERT INTO units (call_id, unit_number, clear_datetime)
            VALUES (?, ?, ?)
        ");
        
        $stmt->execute([$callId, 'E-101', null]); // Active
        $stmt->execute([$callId, 'E-102', null]); // Active
        $stmt->execute([$callId, 'A-201', '2024-01-01 11:00:00']); // Cleared
        
        // Count active units
        $stmt = self::$db->query("
            SELECT COUNT(*) as active_units
            FROM units
            WHERE clear_datetime IS NULL
        ");
        $result = $stmt->fetch();
        
        $this->assertEquals(2, $result['active_units']);
    }

    public function testCanGetCallsByPriority(): void
    {
        // Insert call
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $callId = (int)self::$db->lastInsertId();
        
        // Insert agency contexts with priorities
        $stmt = self::$db->prepare("
            INSERT INTO agency_contexts (call_id, priority)
            VALUES (?, ?)
        ");
        
        $stmt->execute([$callId, 'High']);
        
        // Another call
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([2, 'CALL-002', '2024-01-01 11:00:00']);
        $callId2 = (int)self::$db->lastInsertId();
        
        $stmt = self::$db->prepare("
            INSERT INTO agency_contexts (call_id, priority)
            VALUES (?, ?)
        ");
        $stmt->execute([$callId2, 'Low']);
        
        // Group by priority
        $stmt = self::$db->query("
            SELECT priority, COUNT(*) as count
            FROM agency_contexts
            GROUP BY priority
        ");
        $results = $stmt->fetchAll();
        
        $this->assertCount(2, $results);
    }

    /**
     * Test aggregate stats endpoint
     */
    public function testAggregateStatsEndpoint(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, nature_of_call, create_datetime, closed_flag)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', 'Medical', '2024-01-01 10:00:00', 0]);
        $stmt->execute([2, 'CALL-002', 'Fire', '2024-01-01 11:00:00', 1]);
        $callId1 = 1;
        $callId2 = 2;
        
        // Insert units
        $stmt = self::$db->prepare("
            INSERT INTO units (call_id, unit_number, assigned_datetime, dispatch_datetime, arrive_datetime)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$callId1, 'E-101', '2024-01-01 10:00:00', '2024-01-01 10:01:00', '2024-01-01 10:08:00']);
        $stmt->execute([$callId2, 'E-102', '2024-01-01 11:00:00', '2024-01-01 11:01:00', '2024-01-01 11:09:00']);
        
        // Call the controller
        $controller = new \NwsCad\Api\Controllers\StatsController();
        
        ob_start();
        $controller->index();
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('total_calls', $data['data']);
        $this->assertArrayHasKey('calls_by_status', $data['data']);
        $this->assertArrayHasKey('top_call_types', $data['data']);
        $this->assertArrayHasKey('total_units', $data['data']); // Should be at top level
        $this->assertArrayHasKey('response_times', $data['data']);
        
        $this->assertEquals(2, $data['data']['total_calls']);
        $this->assertIsArray($data['data']['calls_by_status']);
        $this->assertIsArray($data['data']['top_call_types']);
        $this->assertIsInt($data['data']['total_units']);
        $this->assertIsArray($data['data']['response_times']);
    }

    /**
     * Test aggregate stats with date filters
     */
    public function testAggregateStatsWithFilters(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $stmt->execute([2, 'CALL-002', '2024-01-10 11:00:00']);
        
        // Set filters
        $_GET['date_from'] = '2024-01-09';
        $_GET['date_to'] = '2024-01-11';
        
        $controller = new \NwsCad\Api\Controllers\StatsController();
        
        ob_start();
        $controller->index();
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['data']['total_calls']); // Only one call in date range
        
        // Clean up
        unset($_GET['date_from']);
        unset($_GET['date_to']);
    }

    /**
     * Test aggregate stats without filters (empty WHERE clause)
     */
    public function testAggregateStatsWithoutFilters(): void
    {
        // Insert minimal test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, nature_of_call, create_datetime)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', 'Test', '2024-01-01 10:00:00']);
        
        $controller = new \NwsCad\Api\Controllers\StatsController();
        
        ob_start();
        $controller->index();
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        // Should not throw SQL errors with empty WHERE clause
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('total_calls', $data['data']);
        $this->assertGreaterThanOrEqual(1, $data['data']['total_calls']);
    }
}
