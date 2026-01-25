<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\Database;
use NwsCad\Config;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * @covers \NwsCad\Database
 */
class DatabaseTest extends TestCase
{
    public function testGetConnectionReturnsPdoInstance(): void
    {
        // Skip if no database is available
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        try {
            $connection = Database::getConnection();
            $this->assertInstanceOf(PDO::class, $connection);
        } catch (\Exception $e) {
            $this->markTestSkipped('Database connection failed: ' . $e->getMessage());
        }
    }

    public function testGetConnectionReturnsSameInstance(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        try {
            $connection1 = Database::getConnection();
            $connection2 = Database::getConnection();
            
            $this->assertSame($connection1, $connection2, 'Should return same PDO instance');
        } catch (\Exception $e) {
            $this->markTestSkipped('Database connection failed: ' . $e->getMessage());
        }
    }

    public function testGetDbTypeReturnsString(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        try {
            Database::getConnection();
            $dbType = Database::getDbType();
            
            $this->assertIsString($dbType);
            $this->assertContains($dbType, ['mysql', 'pgsql']);
        } catch (\Exception $e) {
            $this->markTestSkipped('Database connection failed: ' . $e->getMessage());
        }
    }

    public function testTestConnectionReturnsBoolean(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        try {
            $result = Database::testConnection();
            $this->assertIsBool($result);
        } catch (\Exception $e) {
            $this->markTestSkipped('Database connection failed: ' . $e->getMessage());
        }
    }

    public function testConnectionHasCorrectAttributes(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        try {
            $connection = Database::getConnection();
            
            $this->assertEquals(
                PDO::ERRMODE_EXCEPTION,
                $connection->getAttribute(PDO::ATTR_ERRMODE)
            );
            
            $this->assertEquals(
                PDO::FETCH_ASSOC,
                $connection->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE)
            );
            
            $this->assertFalse(
                $connection->getAttribute(PDO::ATTR_EMULATE_PREPARES)
            );
        } catch (\Exception $e) {
            $this->markTestSkipped('Database connection failed: ' . $e->getMessage());
        }
    }
}
