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
        throw new \LogicException('not implemented');
    }

    public function resetOrphans(string $currentWorkerId): int
    {
        throw new \LogicException('not implemented');
    }

    public function claim(string $workerId, int $batchSize, DateTimeImmutable $now): array
    {
        throw new \LogicException('not implemented');
    }

    public function markDone(int $rowId): void
    {
        throw new \LogicException('not implemented');
    }

    public function markRetry(
        int $rowId,
        int $attempts,
        DateTimeImmutable $nextAttemptAt,
        string $errorMessage,
    ): void {
        throw new \LogicException('not implemented');
    }

    public function markFailed(int $rowId, int $attempts, string $errorMessage): void
    {
        throw new \LogicException('not implemented');
    }
}
