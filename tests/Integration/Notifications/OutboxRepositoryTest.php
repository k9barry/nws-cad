<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration\Notifications;

use DateTimeImmutable;
use NwsCad\Database;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\Outbox\OutboxRepository;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxRepository::class)]
#[UsesClass(Intent::class)]
final class OutboxRepositoryTest extends TestCase
{
    private static PDO $db;
    private OutboxRepository $repo;
    private int $callId;
    private int $channelId;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = Database::getConnection();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database not available');
        }
    }

    protected function setUp(): void
    {
        cleanTestDatabase();
        $this->repo = new OutboxRepository(self::$db);

        self::$db->exec("INSERT INTO calls (call_id, call_number, create_datetime) VALUES (1, 'C-1', '2026-05-07 12:00:00')");
        $this->callId = (int) self::$db->lastInsertId();

        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json) VALUES ('ntfy_primary', 'ntfy', 1, 'https://x', '{}')");
        $this->channelId = (int) self::$db->lastInsertId();
    }

    public function testInsertWritesRowWithExpectedFields(): void
    {
        $id = $this->repo->insert(
            callId:        $this->callId,
            channelId:     $this->channelId,
            intent:        Intent::Created,
            resendAll:     true,
            addedTopics:   ['IN048', 'E1'],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );

        $this->assertGreaterThan(0, $id);

        $row = self::$db->query("SELECT * FROM notification_outbox WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($this->callId, (int) $row['db_call_id']);
        $this->assertSame($this->channelId, (int) $row['channel_id']);
        $this->assertSame('Created', $row['intent']);
        $this->assertSame(1, (int) $row['resend_all']);
        $this->assertSame(['IN048', 'E1'], json_decode($row['added_topics_json'], true));
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNull($row['next_attempt_at']);
        $this->assertNull($row['claimed_at']);
        $this->assertNull($row['claimed_by']);
        $this->assertNull($row['last_error']);
    }
}
