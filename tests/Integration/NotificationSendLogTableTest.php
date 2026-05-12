<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Database
 */
class NotificationSendLogTableTest extends TestCase
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
            VALUES ('test', 'ntfy', 0, 'u', '{}')");
        $channelId = (int) self::$db->lastInsertId();

        $stmt = self::$db->prepare("INSERT INTO notification_send_log
            (channel_id, call_id, intent, topic, ok, http_status, duration_ms, error)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$channelId, null, 'Created', 'Fire_MCFD_E1', 1, 200, 42, null]);

        $count = (int) self::$db->query("SELECT COUNT(*) FROM notification_send_log WHERE channel_id={$channelId}")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCascadeDeletesWithChannel(): void
    {
        cleanTestDatabase();

        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('cascade', 'ntfy', 0, 'u', '{}')");
        $channelId = (int) self::$db->lastInsertId();

        self::$db->prepare("INSERT INTO notification_send_log
            (channel_id, ok, duration_ms) VALUES (?, ?, ?)")->execute([$channelId, 1, 0]);

        self::$db->exec("DELETE FROM notification_channels WHERE id={$channelId}");

        $count = (int) self::$db->query("SELECT COUNT(*) FROM notification_send_log WHERE channel_id={$channelId}")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testHasActorColumn(): void
    {
        $row = self::$db->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'notification_send_log'
             AND COLUMN_NAME = 'actor'"
        )->fetch();

        $this->assertNotFalse($row, 'notification_send_log.actor column missing');
    }
}
