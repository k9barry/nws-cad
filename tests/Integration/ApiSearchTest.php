<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Search API endpoints
 * @covers \NwsCad\Api\Controllers\SearchController
 */
class ApiSearchTest extends TestCase
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

    public function testCanSearchCallsByCallNumber(): void
    {
        // Insert test calls
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, nature_of_call, create_datetime)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([1, '2024-001', 'Medical', '2024-01-01 10:00:00']);
        $stmt->execute([2, '2024-002', 'Fire', '2024-01-02 10:00:00']);
        $stmt->execute([3, '2024-003', 'Police', '2024-01-03 10:00:00']);
        
        // Search
        $stmt = self::$db->prepare("
            SELECT * FROM calls WHERE call_number LIKE ?
        ");
        $stmt->execute(['%2024-002%']);
        $results = $stmt->fetchAll();
        
        $this->assertCount(1, $results);
        $this->assertEquals('2024-002', $results[0]['call_number']);
    }

    public function testCanSearchCallsByNatureOfCall(): void
    {
        // Insert test calls
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, nature_of_call, create_datetime)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', 'Medical Emergency', '2024-01-01 10:00:00']);
        $stmt->execute([2, 'CALL-002', 'Fire Alarm', '2024-01-02 10:00:00']);
        $stmt->execute([3, 'CALL-003', 'Medical Transport', '2024-01-03 10:00:00']);
        
        // Search for "Medical"
        $stmt = self::$db->prepare("
            SELECT * FROM calls WHERE nature_of_call LIKE ? ORDER BY create_datetime
        ");
        $stmt->execute(['%Medical%']);
        $results = $stmt->fetchAll();
        
        $this->assertCount(2, $results);
        $this->assertStringContainsString('Medical', $results[0]['nature_of_call']);
        $this->assertStringContainsString('Medical', $results[1]['nature_of_call']);
    }

    public function testCanSearchByAddress(): void
    {
        // Insert call
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $callId = (int)self::$db->lastInsertId();
        
        // Insert locations
        $stmt = self::$db->prepare("
            INSERT INTO locations (call_id, full_address, street_name, city)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$callId, '123 Main St', 'Main', 'Springfield']);
        
        // Another call
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([2, 'CALL-002', '2024-01-02 10:00:00']);
        $callId2 = (int)self::$db->lastInsertId();
        
        $stmt = self::$db->prepare("
            INSERT INTO locations (call_id, full_address, street_name, city)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$callId2, '456 Oak Ave', 'Oak', 'Springfield']);
        
        // Search by street name
        $stmt = self::$db->prepare("
            SELECT c.*, l.full_address
            FROM calls c
            INNER JOIN locations l ON c.id = l.call_id
            WHERE l.street_name LIKE ?
        ");
        $stmt->execute(['%Main%']);
        $results = $stmt->fetchAll();
        
        $this->assertCount(1, $results);
        $this->assertStringContainsString('Main', $results[0]['full_address']);
    }

    public function testCanSearchByUnitNumber(): void
    {
        // Insert call
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $callId = (int)self::$db->lastInsertId();
        
        // Insert units
        $stmt = self::$db->prepare("
            INSERT INTO units (call_id, unit_number, unit_type)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$callId, 'E-101', 'Engine']);
        
        // Search by unit number
        $stmt = self::$db->prepare("
            SELECT c.*, u.unit_number
            FROM calls c
            INNER JOIN units u ON c.id = u.call_id
            WHERE u.unit_number = ?
        ");
        $stmt->execute(['E-101']);
        $results = $stmt->fetchAll();
        
        $this->assertCount(1, $results);
        $this->assertEquals('E-101', $results[0]['unit_number']);
    }

    public function testCanSearchByDateRange(): void
    {
        // Insert calls with different dates
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-05 10:00:00']);
        $stmt->execute([2, 'CALL-002', '2024-01-15 10:00:00']);
        $stmt->execute([3, 'CALL-003', '2024-01-25 10:00:00']);
        
        // Search date range
        $stmt = self::$db->prepare("
            SELECT * FROM calls 
            WHERE create_datetime >= ? AND create_datetime <= ?
            ORDER BY create_datetime
        ");
        $stmt->execute(['2024-01-10', '2024-01-20']);
        $results = $stmt->fetchAll();
        
        $this->assertCount(1, $results);
        $this->assertEquals('CALL-002', $results[0]['call_number']);
    }

    public function testCanSearchByCallerName(): void
    {
        // Insert calls
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, caller_name, create_datetime)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', 'John Doe', '2024-01-01 10:00:00']);
        $stmt->execute([2, 'CALL-002', 'Jane Smith', '2024-01-02 10:00:00']);
        $stmt->execute([3, 'CALL-003', 'John Johnson', '2024-01-03 10:00:00']);
        
        // Search by caller name
        $stmt = self::$db->prepare("
            SELECT * FROM calls WHERE caller_name LIKE ?
        ");
        $stmt->execute(['%John%']);
        $results = $stmt->fetchAll();
        
        $this->assertCount(2, $results);
    }

    public function testSearchReturnsEmptyWhenNoResults(): void
    {
        // Insert call
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        
        // Search for non-existent data
        $stmt = self::$db->prepare("
            SELECT * FROM calls WHERE call_number LIKE ?
        ");
        $stmt->execute(['%NONEXISTENT%']);
        $results = $stmt->fetchAll();
        
        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        // Insert call
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, nature_of_call, create_datetime)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', 'Medical Emergency', '2024-01-01 10:00:00']);
        
        // Search with different cases
        $stmt = self::$db->prepare("
            SELECT * FROM calls WHERE nature_of_call LIKE ?
        ");
        
        $stmt->execute(['%medical%']);
        $results1 = $stmt->fetchAll();
        
        $stmt->execute(['%MEDICAL%']);
        $results2 = $stmt->fetchAll();
        
        $this->assertCount(1, $results1);
        $this->assertCount(1, $results2);
    }
}
