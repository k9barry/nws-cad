<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration\Notifications;

use DateTimeImmutable;
use NwsCad\Database;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\Outbox\OutboxRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\Outbox\OutboxRepository
 * @uses \NwsCad\Notifications\Events\Intent
 */
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

        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json) VALUES ('ntfy_primary', 'ntfy', TRUE, 'https://x', '{}')");
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
        // next_attempt_at defaults to CURRENT_TIMESTAMP (eligible immediately),
        // replacing the old nullable "eligible when NULL" semantics.
        $this->assertNotNull($row['next_attempt_at']);
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
        $eightDaysAgo = date('Y-m-d H:i:s', time() - 8 * 86400);
        $oldDoneId = $this->insertPending();
        $this->repo->markDone($oldDoneId);
        self::$db->exec("UPDATE notification_outbox SET updated_at = '{$eightDaysAgo}' WHERE id = {$oldDoneId}");

        $recentDoneId = $this->insertPending();
        $this->repo->markDone($recentDoneId);

        $pendingId = $this->insertPending();
        self::$db->exec("UPDATE notification_outbox SET updated_at = '{$eightDaysAgo}' WHERE id = {$pendingId}");

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

    public function testClaimReturnsPendingRowsAndTransitionsThem(): void
    {
        $id1 = $this->insertPending();
        $id2 = $this->insertPending();

        $now = new DateTimeImmutable('2026-05-07 14:00:00');
        $claimed = $this->repo->claim('me:1:111', 10, $now);

        $this->assertCount(2, $claimed);
        $this->assertSame([$id1, $id2], array_map(static fn ($r) => (int) $r['id'], $claimed));
        foreach ([$id1, $id2] as $id) {
            $row = self::$db->query("SELECT status, claimed_by, claimed_at FROM notification_outbox WHERE id={$id}")
                ->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('in_flight', $row['status']);
            $this->assertSame('me:1:111', $row['claimed_by']);
            $this->assertSame('2026-05-07 14:00:00', $row['claimed_at']);
        }
    }

    public function testClaimRespectsBatchSize(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertPending();
        }
        $claimed = $this->repo->claim('me:1:111', 2, new DateTimeImmutable('2026-05-07 14:00:00'));
        $this->assertCount(2, $claimed);
    }

    public function testClaimRespectsNextAttemptAt(): void
    {
        $ready   = $this->insertPending();
        $waiting = $this->insertPending();
        self::$db->exec(
            "UPDATE notification_outbox SET next_attempt_at = '2026-05-07 15:00:00' WHERE id = {$waiting}"
        );

        $claimed = $this->repo->claim('me:1:111', 10, new DateTimeImmutable('2026-05-07 14:00:00'));

        $this->assertCount(1, $claimed);
        $this->assertSame($ready, (int) $claimed[0]['id']);
    }

    public function testClaimReturnsEmptyWhenNoPending(): void
    {
        $claimed = $this->repo->claim('me:1:111', 10, new DateTimeImmutable());
        $this->assertSame([], $claimed);
    }

    public function testListByStatusReturnsPendingWithChannelJoin(): void
    {
        $pending = $this->insertPending();
        $done    = $this->insertPending();
        $this->repo->markDone($done);

        $rows = $this->repo->listByStatus('pending', 50);

        $this->assertCount(1, $rows);
        $this->assertSame($pending, (int) $rows[0]['id']);
        $this->assertSame('ntfy_primary', $rows[0]['channel_name']);
        $this->assertSame('ntfy', $rows[0]['channel_type']);
        $this->assertSame('C-1', $rows[0]['call_number']);
    }

    public function testListByStatusFailedFiltersCorrectly(): void
    {
        $pending = $this->insertPending();
        $failed  = $this->insertPending();
        $this->repo->markFailed($failed, 5, 'retries exhausted');

        $rows = $this->repo->listByStatus('failed', 50);
        $this->assertCount(1, $rows);
        $this->assertSame($failed, (int) $rows[0]['id']);
        $this->assertSame('retries exhausted', $rows[0]['last_error']);
    }

    public function testListByStatusAllReturnsEverything(): void
    {
        $this->insertPending();
        $done = $this->insertPending();
        $this->repo->markDone($done);

        $rows = $this->repo->listByStatus('all', 50);
        $this->assertCount(2, $rows);
    }

    public function testListByStatusHonorsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertPending();
        }
        $rows = $this->repo->listByStatus('pending', 2);
        $this->assertCount(2, $rows);
    }

    public function testListByStatusOrdersByIdDesc(): void
    {
        $first  = $this->insertPending();
        $second = $this->insertPending();
        $third  = $this->insertPending();

        $rows = $this->repo->listByStatus('pending', 50);
        $ids  = array_map(static fn ($r) => (int) $r['id'], $rows);
        $this->assertSame([$third, $second, $first], $ids);
    }

    public function testRetryClearsFailedRowToPending(): void
    {
        $id = $this->insertPending();
        $this->repo->markFailed($id, 5, 'retries exhausted');

        $ok = $this->repo->retry($id);
        $this->assertTrue($ok);

        $row = self::$db->query("SELECT status, attempts, next_attempt_at, last_error FROM notification_outbox WHERE id={$id}")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
        // retry() resets next_attempt_at to CURRENT_TIMESTAMP (due now) rather
        // than NULL now that the column is NOT NULL.
        $this->assertNotNull($row['next_attempt_at']);
        $this->assertNull($row['last_error']);
    }

    public function testRetryReturnsFalseForMissingRow(): void
    {
        $this->assertFalse($this->repo->retry(99999));
    }

    public function testDeleteRemovesRow(): void
    {
        $id = $this->insertPending();
        $ok = $this->repo->delete($id);
        $this->assertTrue($ok);
        $count = (int) self::$db->query("SELECT COUNT(*) FROM notification_outbox WHERE id={$id}")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testDeleteReturnsFalseForMissingRow(): void
    {
        $this->assertFalse($this->repo->delete(99999));
    }

    public function testDeleteByStatusBulkDeletes(): void
    {
        $d1 = $this->insertPending();
        $d2 = $this->insertPending();
        $pending = $this->insertPending();
        $this->repo->markDone($d1);
        $this->repo->markDone($d2);

        $deleted = $this->repo->deleteByStatus('done');
        $this->assertSame(2, $deleted);
        $remaining = (int) self::$db->query("SELECT COUNT(*) FROM notification_outbox")->fetchColumn();
        $this->assertSame(1, $remaining);
    }

    public function testFindByIdReturnsJoinedRow(): void
    {
        $id = $this->insertPending();

        $row = $this->repo->findById($id);
        $this->assertIsArray($row);
        $this->assertSame($id, (int) $row['id']);
        $this->assertSame('ntfy_primary', $row['channel_name']);
        $this->assertSame('ntfy', $row['channel_type']);
        $this->assertSame('C-1', $row['call_number']);
    }

    public function testFindByIdReturnsNullForMissingRow(): void
    {
        $this->assertNull($this->repo->findById(99999));
    }

    public function testListSendHistoryFiltersByChannelCallAndIntent(): void
    {
        // Two matching entries plus one mismatching channel.
        self::$db->exec("INSERT INTO notification_send_log (channel_id, call_id, intent, topic, ok, http_status, duration_ms, error) VALUES ({$this->channelId}, {$this->callId}, 'Created', 't1', TRUE, 200, 42, NULL)");
        self::$db->exec("INSERT INTO notification_send_log (channel_id, call_id, intent, topic, ok, http_status, duration_ms, error) VALUES ({$this->channelId}, {$this->callId}, 'Created', 't1', FALSE, 503, 91, 'HTTP 503')");
        self::$db->exec("INSERT INTO notification_send_log (channel_id, call_id, intent, topic, ok, http_status, duration_ms, error) VALUES ({$this->channelId}, {$this->callId}, 'Updated', 't1', TRUE, 200, 50, NULL)");

        // Different channel.
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json) VALUES ('other', 'pushover', TRUE, 'https://x', '{}')");
        $otherChannelId = (int) self::$db->lastInsertId();
        self::$db->exec("INSERT INTO notification_send_log (channel_id, call_id, intent, topic, ok, http_status, duration_ms, error) VALUES ({$otherChannelId}, {$this->callId}, 'Created', 't1', TRUE, 200, 30, NULL)");

        $history = $this->repo->listSendHistory($this->channelId, $this->callId, 'Created', 50);
        $this->assertCount(2, $history);
        // Newest first.
        $this->assertSame(0, (int) $history[0]['ok']);
        $this->assertSame('HTTP 503', $history[0]['error']);
    }

    public function testListSendHistoryHonorsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::$db->exec("INSERT INTO notification_send_log (channel_id, call_id, intent, topic, ok, http_status, duration_ms, error) VALUES ({$this->channelId}, {$this->callId}, 'Created', 't1', TRUE, 200, 10, NULL)");
        }
        $history = $this->repo->listSendHistory($this->channelId, $this->callId, 'Created', 3);
        $this->assertCount(3, $history);
    }

    public function testRescheduleSetsNextAttemptAndKeepsAttemptsAndError(): void
    {
        $id = $this->insertPending();
        $this->repo->markFailed($id, 5, 'retries exhausted');

        $ok = $this->repo->reschedule($id, new DateTimeImmutable('2026-05-08 09:00:00'));
        $this->assertTrue($ok);

        $row = self::$db->query("SELECT status, attempts, next_attempt_at, last_error FROM notification_outbox WHERE id={$id}")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(5, (int) $row['attempts']);
        $this->assertSame('2026-05-08 09:00:00', $row['next_attempt_at']);
        $this->assertSame('retries exhausted', $row['last_error']);
    }

    public function testRescheduleAllowedForPendingRow(): void
    {
        $id = $this->insertPending();
        $ok = $this->repo->reschedule($id, new DateTimeImmutable('2026-05-08 09:00:00'));
        $this->assertTrue($ok);
        $row = self::$db->query("SELECT next_attempt_at FROM notification_outbox WHERE id={$id}")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('2026-05-08 09:00:00', $row['next_attempt_at']);
    }

    public function testRescheduleRejectsInFlightRow(): void
    {
        $id = $this->insertPending();
        self::$db->exec("UPDATE notification_outbox SET status='in_flight', claimed_by='w:1', claimed_at=NOW() WHERE id={$id}");

        $ok = $this->repo->reschedule($id, new DateTimeImmutable('2026-05-08 09:00:00'));
        $this->assertFalse($ok);
        $row = self::$db->query("SELECT status FROM notification_outbox WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('in_flight', $row['status']);
    }

    public function testRescheduleRejectsDoneRow(): void
    {
        $id = $this->insertPending();
        $this->repo->markDone($id);
        $this->assertFalse($this->repo->reschedule($id, new DateTimeImmutable('2026-05-08 09:00:00')));
    }

    public function testRescheduleReturnsFalseForMissingRow(): void
    {
        $this->assertFalse($this->repo->reschedule(99999, new DateTimeImmutable('2026-05-08 09:00:00')));
    }

    private function insertPending(): int
    {
        // insert() sets next_attempt_at to createDateTime (2026-05-07 12:00:00),
        // which is in the past relative to the fixed-clock claim tests, so the row
        // is due without further setup.
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
