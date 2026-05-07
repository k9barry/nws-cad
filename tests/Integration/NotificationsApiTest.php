<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Api\Controllers\NotificationsController;
use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Controllers\NotificationsController
 */
class NotificationsApiTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = Database::getConnection();
        } catch (\Exception $e) {
            self::markTestSkipped('Database not available');
        }
    }

    protected function setUp(): void
    {
        cleanTestDatabase();
    }

    public function testChannelsReturnsEmpty(): void
    {
        $controller = new NotificationsController();
        ob_start();
        $controller->channels();
        $body = (string) ob_get_clean();
        $payload = json_decode($body, true);

        $this->assertTrue($payload['success']);
        $this->assertSame([], $payload['data']['items']);
    }

    public function testChannelsReturnsRows(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('one', 'ntfy', 1, 'u', '{}'),
                   ('two', 'pushover', 0, 'u', '{}')");

        $controller = new NotificationsController();
        ob_start();
        $controller->channels();
        $payload = json_decode((string) ob_get_clean(), true);

        $names = array_column($payload['data']['items'], 'name');
        sort($names);
        $this->assertSame(['one', 'two'], $names);
    }

    public function testLogReturnsRecentRowsForChannel(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('c', 'ntfy', 1, 'u', '{}')");
        $channelId = (int) self::$db->lastInsertId();

        $stmt = self::$db->prepare("INSERT INTO notification_send_log
            (channel_id, intent, topic, ok, http_status, duration_ms) VALUES (?, ?, ?, ?, ?, ?)");
        for ($i = 1; $i <= 3; $i++) {
            $stmt->execute([$channelId, 'Created', "T{$i}", 1, 200, $i * 10]);
        }

        $_GET['channel'] = (string) $channelId;
        $_GET['limit'] = '2';
        $controller = new NotificationsController();
        ob_start();
        $controller->log();
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertCount(2, $payload['data']['items']);
        $this->assertSame('T3', $payload['data']['items'][0]['topic']);
    }
}
