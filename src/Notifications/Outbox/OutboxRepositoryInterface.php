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
     * Fetch a single row joined with channel name/type and call_number for the
     * per-row inspector. Returns null if not found.
     *
     * @return array<string,mixed>|null
     */
    public function findById(int $rowId): ?array;

    /**
     * Recent send_log entries matching the outbox row's (channel, call, intent)
     * tuple, newest first. Used by the inspector to show send-attempt history.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listSendHistory(int $channelId, int $callId, string $intent, int $limit): array;

    /**
     * Reschedule a row: set next_attempt_at to the supplied time and put it
     * back in 'pending' so the worker re-picks it at that time. Keeps attempts
     * and last_error intact (the reschedule is purely a deferral). Only
     * pending and failed rows can be rescheduled. Returns true if updated.
     */
    public function reschedule(int $rowId, DateTimeImmutable $nextAttemptAt): bool;

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
