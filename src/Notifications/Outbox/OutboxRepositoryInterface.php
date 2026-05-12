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

    /**
     * List rows for operator inspection, joined with channel name/type and
     * call_number for display.
     *
     * @param string $status One of `pending`, `in_flight`, `done`, `failed`, `all`.
     * @return array<int,array<string,mixed>>
     */
    public function listByStatus(string $status, int $limit): array;

    /**
     * Operator-initiated retry: reset a failed (or any) row to pending with
     * cleared backoff state. Returns true if a row was updated.
     */
    public function retry(int $rowId): bool;

    /** Operator-initiated dismissal of a single row. Returns true if removed. */
    public function delete(int $rowId): bool;

    /** Bulk delete by status. Returns count removed. */
    public function deleteByStatus(string $status): int;
}
