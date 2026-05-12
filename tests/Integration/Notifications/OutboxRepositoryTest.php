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

    public function testMarkDone(): void
    {
        $id = $this->insertPending();
        $this->repo->markDone($id);

        $row = self::$db->query("SELECT status, last_error FROM notification_outbox WHERE id = {$id}")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('done', $row['status']);
        $this->assertNull($row['last_error']);
    }

    public function testMarkRetry(): void
    {
        $id = $this->insertPending();
        $this->repo->markRetry(
            rowId:         $id,
            attempts:      2,
            nextAttemptAt: new DateTimeImmutable('2026-05-07 13:00:00'),
            errorMessage:  'HTTP 503',
        );

        $row = self::$db->query("SELECT status, attempts, next_attempt_at, claimed_by, claimed_at, last_error
                                  FROM notification_outbox WHERE id = {$id}")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(2, (int) $row['attempts']);
        $this->assertSame('2026-05-07 13:00:00', $row['next_attempt_at']);
        $this->assertNull($row['claimed_by']);
        $this->assertNull($row['claimed_at']);
        $this->assertSame('HTTP 503', $row['last_error']);
    }

    public function testMarkFailed(): void
    {
        $id = $this->insertPending();
        $this->repo->markFailed($id, 5, 'retries exhausted');

        $row = self::$db->query("SELECT status, attempts, last_error FROM notification_outbox WHERE id = {$id}")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(5, (int) $row['attempts']);
        $this->assertSame('retries exhausted', $row['last_error']);
    }

    public function testPruneDeletesDoneRowsOlderThanThreshold(): void
    {
        $oldDoneId = $this->insertPending();
        $this->repo->markDone($oldDoneId);
        self::$db->exec("UPDATE notification_outbox SET updated_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE id = {$oldDoneId}");

        $recentDoneId = $this->insertPending();
        $this->repo->markDone($recentDoneId);

        $pendingId = $this->insertPending();
        self::$db->exec("UPDATE notification_outbox SET updated_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE id = {$pendingId}");

        $deleted = $this->repo->prune(7 * 86400);

        $this->assertSame(1, $deleted);
        $remaining = (int) self::$db->query("SELECT COUNT(*) FROM notification_outbox WHERE id = {$oldDoneId}")->fetchColumn();
        $this->assertSame(0, $remaining);
        $this->assertNotFalse(self::$db->query("SELECT id FROM notification_outbox WHERE id = {$recentDoneId}")->fetch());
        $this->assertNotFalse(self::$db->query("SELECT id FROM notification_outbox WHERE id = {$pendingId}")->fetch());
    }

    public function testResetOrphansClaimsForOtherWorkers(): void
    {
        $mineId  = $this->insertPending();
        $otherId = $this->insertPending();

        self::$db->exec(
            "UPDATE notification_outbox SET status='in_flight', claimed_by='me:1:111', claimed_at=NOW() WHERE id={$mineId}"
        );
        self::$db->exec(
            "UPDATE notification_outbox SET status='in_flight', claimed_by='other:2:222', claimed_at=NOW() WHERE id={$otherId}"
        );

        $reset = $this->repo->resetOrphans('me:1:111');

        $this->assertSame(1, $reset);
        $mine  = self::$db->query("SELECT status FROM notification_outbox WHERE id={$mineId}")->fetchColumn();
        $other = self::$db->query("SELECT status, claimed_by FROM notification_outbox WHERE id={$otherId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('in_flight', $mine);
        $this->assertSame('pending', $other['status']);
        $this->assertNull($other['claimed_by']);
    }

    private function insertPending(): int
    {
        return $this->repo->insert(
            callId:         $this->callId,
            channelId:      $this->channelId,
            intent:         Intent::Created,
            resendAll:      true,
            addedTopics:    [],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );
    }
}
