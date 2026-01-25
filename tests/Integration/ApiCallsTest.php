<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Calls API endpoints
 * @covers \NwsCad\Api\Controllers\CallsController
 */
class ApiCallsTest extends TestCase
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

    public function testCanInsertAndRetrieveCall(): void
    {
        cleanTestDatabase();
        
        // Insert test call
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, nature_of_call, create_datetime)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([12345, 'TEST-001', 'Medical Emergency', '2024-01-01 10:00:00']);
        
        // Retrieve call
        $stmt = self::$db->prepare("SELECT * FROM calls WHERE call_number = ?");
        $stmt->execute(['TEST-001']);
        $call = $stmt->fetch();
        
        $this->assertNotFalse($call);
        $this->assertEquals('TEST-001', $call['call_number']);
        $this->assertEquals('Medical Emergency', $call['nature_of_call']);
    }

    public function testCanFilterCallsByDateRange(): void
    {
        cleanTestDatabase();
        
        // Insert test calls with different dates
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $stmt->execute([2, 'CALL-002', '2024-01-15 10:00:00']);
        $stmt->execute([3, 'CALL-003', '2024-02-01 10:00:00']);
        
        // Query calls in date range
        $stmt = self::$db->prepare("
            SELECT * FROM calls 
            WHERE create_datetime BETWEEN ? AND ?
            ORDER BY create_datetime
        ");
        $stmt->execute(['2024-01-01', '2024-01-31']);
        $calls = $stmt->fetchAll();
        
        $this->assertCount(2, $calls);
        $this->assertEquals('CALL-001', $calls[0]['call_number']);
        $this->assertEquals('CALL-002', $calls[1]['call_number']);
    }

    public function testCanRetrieveCallWithRelatedData(): void
    {
        cleanTestDatabase();
        
        // Insert call
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $callId = (int)self::$db->lastInsertId();
        
        // Insert location
        $stmt = self::$db->prepare("
            INSERT INTO locations (call_id, full_address, city, state)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$callId, '123 Main St', 'Springfield', 'IL']);
        
        // Insert unit
        $stmt = self::$db->prepare("
            INSERT INTO units (call_id, unit_number, unit_type)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$callId, 'E-101', 'Engine']);
        
        // Retrieve call with related data
        $stmt = self::$db->prepare("
            SELECT c.*, l.full_address, l.city, l.state
            FROM calls c
            LEFT JOIN locations l ON c.id = l.call_id
            WHERE c.call_number = ?
        ");
        $stmt->execute(['CALL-001']);
        $call = $stmt->fetch();
        
        $this->assertNotFalse($call);
        $this->assertEquals('123 Main St', $call['full_address']);
        $this->assertEquals('Springfield', $call['city']);
        
        // Check units
        $stmt = self::$db->prepare("
            SELECT * FROM units WHERE call_id = ?
        ");
        $stmt->execute([$callId]);
        $units = $stmt->fetchAll();
        
        $this->assertCount(1, $units);
        $this->assertEquals('E-101', $units[0]['unit_number']);
    }

    public function testCanSearchCallsByText(): void
    {
        cleanTestDatabase();
        
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
            SELECT * FROM calls 
            WHERE nature_of_call LIKE ?
            ORDER BY create_datetime
        ");
        $stmt->execute(['%Medical%']);
        $calls = $stmt->fetchAll();
        
        $this->assertCount(2, $calls);
        $this->assertEquals('CALL-001', $calls[0]['call_number']);
        $this->assertEquals('CALL-003', $calls[1]['call_number']);
    }

    public function testCanPaginateCalls(): void
    {
        cleanTestDatabase();
        
        // Insert multiple calls
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        
        for ($i = 1; $i <= 50; $i++) {
            $stmt->execute([$i, "CALL-" . str_pad($i, 3, '0', STR_PAD_LEFT), '2024-01-01 10:00:00']);
        }
        
        // Get first page (10 per page)
        $stmt = self::$db->prepare("
            SELECT * FROM calls 
            ORDER BY id DESC 
            LIMIT ? OFFSET ?
        ");
        $perPage = 10;
        $page = 1;
        $offset = ($page - 1) * $perPage;
        
        $stmt->execute([$perPage, $offset]);
        $calls = $stmt->fetchAll();
        
        $this->assertCount(10, $calls);
        
        // Get total count
        $stmt = self::$db->query("SELECT COUNT(*) as total FROM calls");
        $result = $stmt->fetch();
        $this->assertEquals(50, $result['total']);
    }
}
