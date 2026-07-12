<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Database
 */
class NotificationChannelsTableTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = Database::getConnection();
        } catch (\Exception $e) {
            self::markTestSkipped('Database not available: ' . $e->getMessage());
        }
    }

    public function testTableExistsAndAcceptsRow(): void
    {
        cleanTestDatabase();

        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('ntfy_primary', 'ntfy', FALSE, 'https://ntfy.example', '{}')");

        $row = self::$db->query("SELECT name, type, enabled, base_url FROM notification_channels WHERE name='ntfy_primary'")->fetch();

        $this->assertSame('ntfy_primary', $row['name']);
        $this->assertSame('ntfy', $row['type']);
        $this->assertSame('https://ntfy.example', $row['base_url']);
    }

    public function testNameIsUnique(): void
    {
        cleanTestDatabase();
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('dup', 'ntfy', FALSE, 'u', '{}')");

        $this->expectException(\PDOException::class);
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('dup', 'pushover', FALSE, 'u', '{}')");
    }

    public function testHasLastUpdatedActorColumn(): void
    {
        $row = self::$db->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'notification_channels'
             AND COLUMN_NAME = 'last_updated_actor'"
        )->fetch();

        $this->assertNotFalse($row, 'notification_channels.last_updated_actor column missing');
    }
}
