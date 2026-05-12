<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Outbox;

use DateTimeImmutable;
use NwsCad\Database;
use NwsCad\Notifications\Events\Intent;
use PDO;

final class OutboxRepository implements OutboxRepositoryInterface
{
    private ?PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    /**
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    private function exec(callable $fn): mixed
    {
        if ($this->db !== null) {
            return $fn($this->db);
        }
        return Database::run($fn);
    }

    public function insert(
        int $callId,
        int $channelId,
        Intent $intent,
        bool $resendAll,
        array $addedTopics,
        DateTimeImmutable $createDateTime,
    ): int {
        return $this->exec(function (PDO $db) use ($callId, $channelId, $intent, $resendAll, $addedTopics, $createDateTime): int {
            $stmt = $db->prepare(
                "INSERT INTO notification_outbox
                 (db_call_id, channel_id, intent, resend_all, added_topics_json, create_datetime)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $callId,
                $channelId,
                $intent->value,
                $resendAll ? 1 : 0,
                json_encode(array_values($addedTopics), JSON_UNESCAPED_SLASHES),
                $createDateTime->format('Y-m-d H:i:s'),
            ]);
            return (int) $db->lastInsertId();
        });
    }

    public function prune(int $olderThanSeconds): int
    {
        return $this->exec(function (PDO $db) use ($olderThanSeconds): int {
            $cutoff = (new DateTimeImmutable())->modify("-{$olderThanSeconds} seconds")->format('Y-m-d H:i:s');
            $stmt = $db->prepare(
                "DELETE FROM notification_outbox
                 WHERE status = 'done' AND updated_at < ?"
            );
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        });
    }

    public function resetOrphans(string $currentWorkerId): int
    {
        return $this->exec(function (PDO $db) use ($currentWorkerId): int {
            $stmt = $db->prepare(
                "UPDATE notification_outbox
                 SET status = 'pending',
                     claimed_at = NULL,
                     claimed_by = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE status = 'in_flight' AND (claimed_by IS NULL OR claimed_by <> ?)"
            );
            $stmt->execute([$currentWorkerId]);
            return $stmt->rowCount();
        });
    }

    public function claim(string $workerId, int $batchSize, DateTimeImmutable $now): array
    {
        throw new \LogicException('not implemented');
    }

    public function markDone(int $rowId): void
    {
        $this->exec(function (PDO $db) use ($rowId): void {
            $stmt = $db->prepare(
                "UPDATE notification_outbox
                 SET status = 'done', last_error = NULL,
                     claimed_at = NULL, claimed_by = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([$rowId]);
        });
    }

    public function markRetry(
        int $rowId,
        int $attempts,
        DateTimeImmutable $nextAttemptAt,
        string $errorMessage,
    ): void {
        $this->exec(function (PDO $db) use ($rowId, $attempts, $nextAttemptAt, $errorMessage): void {
            $stmt = $db->prepare(
                "UPDATE notification_outbox
                 SET status = 'pending',
                     attempts = ?,
                     next_attempt_at = ?,
                     claimed_at = NULL,
                     claimed_by = NULL,
                     last_error = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([
                $attempts,
                $nextAttemptAt->format('Y-m-d H:i:s'),
                $errorMessage,
                $rowId,
            ]);
        });
    }

    public function markFailed(int $rowId, int $attempts, string $errorMessage): void
    {
        $this->exec(function (PDO $db) use ($rowId, $attempts, $errorMessage): void {
            $stmt = $db->prepare(
                "UPDATE notification_outbox
                 SET status = 'failed',
                     attempts = ?,
                     last_error = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([$attempts, $errorMessage, $rowId]);
        });
    }
}
