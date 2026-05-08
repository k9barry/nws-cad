<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Database;
use NwsCad\Notifications\ChannelRepository;
use NwsCad\Notifications\SendResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\ChannelRepository
 * @uses \NwsCad\Config
 * @uses \NwsCad\Database
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 * @uses \NwsCad\Notifications\SendResult
 */
class ChannelRepositoryTest extends TestCase
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
        $_ENV['NTFY_AUTH_TOKEN'] = 'tok-abc';
        $_ENV['NTFY_BASE_URL'] = 'https://ntfy.example';
    }

    public function testListEnabledReturnsOnlyEnabledChannels(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('on', 'ntfy', 1, 'https://ntfy.example', '{\"auth_token_env\":\"NTFY_AUTH_TOKEN\"}'),
                   ('off', 'ntfy', 0, 'https://ntfy.example', '{}')");

        $repo = new ChannelRepository();
        $rows = $repo->listEnabled();

        $this->assertCount(1, $rows);
        $this->assertSame('on', $rows[0]['name']);
    }

    public function testRecordSendInsertsAndPrunesPerChannel(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('c', 'ntfy', 0, 'u', '{}')");
        $channelId = (int) self::$db->lastInsertId();

        $repo = new ChannelRepository();
        for ($i = 0; $i < 105; $i++) {
            $repo->recordSend($channelId, null, 'Created', SendResult::ok(200, 5, "T{$i}"));
        }

        $count = (int) self::$db->query("SELECT COUNT(*) FROM notification_send_log WHERE channel_id={$channelId}")->fetchColumn();
        $this->assertSame(100, $count, 'send log should be pruned to 100 rows per channel');
    }

    public function testMarkFailureUpdatesLastErrorFields(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('f', 'ntfy', 1, 'u', '{}')");
        $channelId = (int) self::$db->lastInsertId();

        $repo = new ChannelRepository();
        $repo->markFailure($channelId, 'HTTP 502 bad gateway');

        $row = self::$db->query("SELECT last_error_at, last_error_message FROM notification_channels WHERE id={$channelId}")->fetch();
        $this->assertNotNull($row['last_error_at']);
        $this->assertSame('HTTP 502 bad gateway', $row['last_error_message']);
    }
}
