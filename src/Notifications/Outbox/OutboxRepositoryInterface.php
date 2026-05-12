<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Outbox;

use DateTimeImmutable;
use NwsCad\Notifications\Events\Intent;

interface OutboxRepositoryInterface
{
    /**
     * @param string[] $addedTopics
     */
    public function insert(
        int $callId,
        int $channelId,
        Intent $intent,
        bool $resendAll,
        array $addedTopics,
        DateTimeImmutable $createDateTime,
    ): int;

    /** @return int rows deleted */
    public function prune(int $olderThanSeconds): int;

    /** @return int rows reset */
    public function resetOrphans(string $currentWorkerId): int;

    /**
     * Atomically claim up to $batchSize pending rows for $workerId, return the claimed rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public function claim(string $workerId, int $batchSize, DateTimeImmutable $now): array;

    public function markDone(int $rowId): void;

    public function markRetry(
        int $rowId,
        int $attempts,
        DateTimeImmutable $nextAttemptAt,
        string $errorMessage,
    ): void;

    public function markFailed(int $rowId, int $attempts, string $errorMessage): void;
}
