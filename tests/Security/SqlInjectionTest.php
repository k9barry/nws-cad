<?php

declare(strict_types=1);

namespace NwsCad\Tests\Security;

use NwsCad\Database;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Security tests for SQL injection prevention
 * Tests that all database queries use prepared statements correctly
 */
#[CoversNothing]
class SqlInjectionTest extends TestCase
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

    public function testPreparedStatementsPreventSqlInjectionInSelect(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        
        // Attempt SQL injection
        $maliciousInput = "CALL-001' OR '1'='1";
        
        $stmt = self::$db->prepare("SELECT * FROM calls WHERE call_number = ?");
        $stmt->execute([$maliciousInput]);
        $result = $stmt->fetch();
        
        // Should not return any results (injection failed)
        $this->assertFalse($result, 'SQL injection should be prevented by prepared statements');
    }

    public function testPreparedStatementsPreventSqlInjectionInUpdate(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, nature_of_call, create_datetime)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', 'Medical', '2024-01-01 10:00:00']);
        
        // Attempt SQL injection in update
        $maliciousInput = "Updated'; DROP TABLE calls; --";
        
        $stmt = self::$db->prepare("
            UPDATE calls SET nature_of_call = ? WHERE call_number = ?
        ");
        $stmt->execute([$maliciousInput, 'CALL-001']);
        
        // Verify table still exists and injection didn't execute
        $stmt = self::$db->query("SELECT COUNT(*) as count FROM calls");
        $result = $stmt->fetch();
        
        $this->assertEquals(1, $result['count'], 'Table should still exist and have 1 record');
        
        // Verify the malicious input was stored as literal text
        $stmt = self::$db->prepare("SELECT nature_of_call FROM calls WHERE call_number = ?");
        $stmt->execute(['CALL-001']);
        $call = $stmt->fetch();
        
        $this->assertEquals($maliciousInput, $call['nature_of_call']);
    }

    public function testPreparedStatementsPreventSqlInjectionInWhere(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        $stmt->execute([2, 'CALL-002', '2024-01-02 10:00:00']);
        
        // Attempt injection in WHERE clause
        $maliciousInput = "1 OR 1=1";
        
        $stmt = self::$db->prepare("SELECT * FROM calls WHERE call_id = ?");
        $stmt->execute([$maliciousInput]);
        $results = $stmt->fetchAll();
        
        // Should not return any results (treats input as literal value)
        $this->assertEmpty($results, 'SQL injection in WHERE clause should be prevented');
    }

    public function testPreparedStatementsPreventUnionBasedInjection(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        
        // Attempt UNION-based injection
        $maliciousInput = "CALL-001' UNION SELECT null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null FROM processed_files --";
        
        $stmt = self::$db->prepare("SELECT * FROM calls WHERE call_number = ?");
        $stmt->execute([$maliciousInput]);
        $result = $stmt->fetch();
        
        // Should not return results from other tables
        $this->assertFalse($result);
    }

    public function testPreparedStatementsPreventBooleanBasedInjection(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        
        // Boolean-based blind SQL injection attempt
        $maliciousInput = "1' AND '1'='1";
        
        $stmt = self::$db->prepare("SELECT * FROM calls WHERE call_id = ?");
        $stmt->execute([$maliciousInput]);
        $results = $stmt->fetchAll();
        
        // Should treat as literal and return nothing
        $this->assertEmpty($results);
    }

    public function testPreparedStatementsPreventCommentBasedInjection(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        
        // Comment-based injection
        $maliciousInput = "CALL-001' --";
        
        $stmt = self::$db->prepare("SELECT * FROM calls WHERE call_number = ?");
        $stmt->execute([$maliciousInput]);
        $result = $stmt->fetch();
        
        // Should not match (-- is treated as literal)
        $this->assertFalse($result);
    }

    public function testPreparedStatementsPreventTimeBasedInjection(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        
        // Time-based injection attempt
        $maliciousInput = "CALL-001' AND SLEEP(5) --";
        
        $start = microtime(true);
        $stmt = self::$db->prepare("SELECT * FROM calls WHERE call_number = ?");
        $stmt->execute([$maliciousInput]);
        $result = $stmt->fetch();
        $duration = microtime(true) - $start;
        
        // Should not execute SLEEP, query should be fast
        $this->assertLessThan(1.0, $duration, 'Query should not execute injected SLEEP command');
        $this->assertFalse($result);
    }

    public function testSpecialCharactersAreEscapedProperly(): void
    {
        // Test various special characters
        $specialChars = [
            "Test' with quote",
            'Test" with double quote',
            "Test\\ with backslash",
            "Test\n with newline",
            "Test\r with carriage return",
            "Test\t with tab",
            "Test\x00 with null byte",
        ];
        
        foreach ($specialChars as $index => $input) {
            $stmt = self::$db->prepare("
                INSERT INTO calls (call_id, call_number, nature_of_call, create_datetime)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([100 + $index, "SPECIAL-$index", $input, '2024-01-01 10:00:00']);
            
            // Verify it was stored correctly
            $stmt = self::$db->prepare("SELECT nature_of_call FROM calls WHERE call_number = ?");
            $stmt->execute(["SPECIAL-$index"]);
            $result = $stmt->fetch();
            
            $this->assertEquals($input, $result['nature_of_call'], "Special characters should be stored correctly");
        }
    }

    public function testIntegerParameterTypeEnforcement(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, create_datetime)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', '2024-01-01 10:00:00']);
        
        // Attempt to pass SQL injection in integer parameter
        $maliciousId = "1 OR 1=1";
        
        $stmt = self::$db->prepare("SELECT * FROM calls WHERE call_id = ?");
        $stmt->execute([$maliciousId]);
        $results = $stmt->fetchAll();
        
        // Should not return multiple results
        $this->assertEmpty($results, 'Type casting should prevent injection in integer fields');
    }

    public function testLikePatternInjection(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("
            INSERT INTO calls (call_id, call_number, nature_of_call, create_datetime)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([1, 'CALL-001', 'Medical Emergency', '2024-01-01 10:00:00']);
        $stmt->execute([2, 'CALL-002', 'Fire Alarm', '2024-01-02 10:00:00']);
        
        // Attempt injection via LIKE pattern
        $maliciousPattern = "%' OR '1'='1";
        
        $stmt = self::$db->prepare("
            SELECT * FROM calls WHERE nature_of_call LIKE ?
        ");
        $stmt->execute([$maliciousPattern]);
        $results = $stmt->fetchAll();
        
        // Should not return all records
        $this->assertEmpty($results, 'LIKE pattern injection should be prevented');
    }
}
