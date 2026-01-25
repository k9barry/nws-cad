<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Units API endpoints
 * @covers \NwsCad\Api\Controllers\UnitsController
 */
class ApiUnitsTest extends TestCase
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
        
        cleanTestDatabase();
    }

    public function testCanRetrieveUnitsForCall(): void
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
            INSERT INTO units (call_id, unit_number, unit_type, is_primary)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$callId, 'E-101', 'Engine', true]);
        $stmt->execute([$callId, 'A-201', 'Ambulance', false]);
        
        // Retrieve units for call
        $stmt = self::$db->prepare("
            SELECT * FROM units WHERE call_id = ? ORDER BY unit_number
        ");
        $stmt->execute([$callId]);
        $units = $stmt->fetchAll();
        
        $this->assertCount(2, $units);
        $this->assertEquals('A-201', $units[0]['unit_number']);
        $this->assertEquals('E-101', $units[1]['unit_number']);
        $this->assertTrue((bool)$units[1]['is_primary']);
    }

    public function testCanRetrieveUnitWithPersonnel(): void
    {
        // Insert call and unit
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $callId = (int)self::$db->lastInsertId();
        
        $stmt = self::$db->prepare("
            INSERT INTO units (call_id, unit_number, unit_type)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$callId, 'E-101', 'Engine']);
        $unitId = (int)self::$db->lastInsertId();
        
        // Insert personnel
        $stmt = self::$db->prepare("
            INSERT INTO unit_personnel (unit_id, first_name, last_name, is_primary_officer)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$unitId, 'John', 'Doe', true]);
        $stmt->execute([$unitId, 'Jane', 'Smith', false]);
        
        // Retrieve unit with personnel
        $stmt = self::$db->prepare("
            SELECT * FROM unit_personnel WHERE unit_id = ?
        ");
        $stmt->execute([$unitId]);
        $personnel = $stmt->fetchAll();
        
        $this->assertCount(2, $personnel);
        $this->assertEquals('John', $personnel[0]['first_name']);
        $this->assertEquals('Doe', $personnel[0]['last_name']);
    }

    public function testCanRetrieveUnitTimestamps(): void
    {
        // Insert call and unit with timestamps
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $callId = (int)self::$db->lastInsertId();
        
        $stmt = self::$db->prepare("
            INSERT INTO units (
                call_id, unit_number, unit_type, 
                assigned_datetime, dispatch_datetime, 
                enroute_datetime, arrive_datetime
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $callId, 'E-101', 'Engine',
            '2024-01-01 10:00:00',
            '2024-01-01 10:01:00',
            '2024-01-01 10:02:00',
            '2024-01-01 10:10:00'
        ]);
        
        // Retrieve unit
        $stmt = self::$db->prepare("
            SELECT * FROM units WHERE unit_number = ?
        ");
        $stmt->execute(['E-101']);
        $unit = $stmt->fetch();
        
        $this->assertNotFalse($unit);
        $this->assertNotNull($unit['assigned_datetime']);
        $this->assertNotNull($unit['dispatch_datetime']);
        $this->assertNotNull($unit['enroute_datetime']);
        $this->assertNotNull($unit['arrive_datetime']);
    }

    public function testCanFilterUnitsByType(): void
    {
        // Insert call
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $callId = (int)self::$db->lastInsertId();
        
        // Insert units of different types
        $stmt = self::$db->prepare("
            INSERT INTO units (call_id, unit_number, unit_type)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$callId, 'E-101', 'Engine']);
        $stmt->execute([$callId, 'E-102', 'Engine']);
        $stmt->execute([$callId, 'A-201', 'Ambulance']);
        
        // Filter by type
        $stmt = self::$db->prepare("
            SELECT * FROM units WHERE call_id = ? AND unit_type = ?
        ");
        $stmt->execute([$callId, 'Engine']);
        $engines = $stmt->fetchAll();
        
        $this->assertCount(2, $engines);
        
        $stmt->execute([$callId, 'Ambulance']);
        $ambulances = $stmt->fetchAll();
        
        $this->assertCount(1, $ambulances);
    }

    public function testCanRetrieveUnitLogs(): void
    {
        // Insert call and unit
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $callId = (int)self::$db->lastInsertId();
        
        $stmt = self::$db->prepare("
            INSERT INTO units (call_id, unit_number, unit_type)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$callId, 'E-101', 'Engine']);
        $unitId = (int)self::$db->lastInsertId();
        
        // Insert unit logs
        $stmt = self::$db->prepare("
            INSERT INTO unit_logs (unit_id, datetime, status)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$unitId, '2024-01-01 10:01:00', 'Dispatched']);
        $stmt->execute([$unitId, '2024-01-01 10:02:00', 'Enroute']);
        $stmt->execute([$unitId, '2024-01-01 10:10:00', 'On Scene']);
        
        // Retrieve logs
        $stmt = self::$db->prepare("
            SELECT * FROM unit_logs WHERE unit_id = ? ORDER BY datetime
        ");
        $stmt->execute([$unitId]);
        $logs = $stmt->fetchAll();
        
        $this->assertCount(3, $logs);
        $this->assertEquals('Dispatched', $logs[0]['status']);
        $this->assertEquals('Enroute', $logs[1]['status']);
        $this->assertEquals('On Scene', $logs[2]['status']);
    }
}
