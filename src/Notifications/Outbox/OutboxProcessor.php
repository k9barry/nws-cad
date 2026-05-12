<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Outbox;

use DateTimeImmutable;
use NwsCad\Logger;
use NwsCad\Notifications\ChannelFactoryInterface;
use NwsCad\Notifications\ChannelRepositoryInterface;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationContext;
use NwsCad\Notifications\TopicResolver;
use Throwable;

final class OutboxProcessor
{
    public const PRUNE_OLDER_THAN_SECONDS = 7 * 86400;
    /** @var int[] indexed by attempts-1 */
    public const BACKOFF_SECONDS = [30, 120, 600, 1800, 7200];

    /** @var callable():DateTimeImmutable */
    private $clock;
    /** @var callable(int):IncidentDto */
    private $incidentLoader;

    public function __construct(
        private readonly OutboxRepositoryInterface $repo,
        private readonly ChannelFactoryInterface $factory,
        private readonly ChannelRepositoryInterface $channelRepo,
        callable $incidentLoader,
        private readonly int $batchSize,
        private readonly int $maxAttempts,
        private readonly string $workerId,
        ?callable $clock = null,
    ) {
        $this->incidentLoader = $incidentLoader;
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function tick(): void
    {
        $logger = Logger::getInstance();
        try {
            $this->repo->prune(self::PRUNE_OLDER_THAN_SECONDS);
            $this->repo->resetOrphans($this->workerId);
        } catch (Throwable $t) {
            $logger->error('Outbox tick: housekeeping failed', ['error' => $t->getMessage()]);
        }

        $now     = ($this->clock)();
        $claimed = $this->repo->claim($this->workerId, $this->batchSize, $now);
        foreach ($claimed as $row) {
            try {
                $this->processRow($row);
            } catch (Throwable $t) {
                $logger->error('Outbox tick: processRow threw', [
                    'outboxId' => $row['id'], 'error' => $t->getMessage(),
                ]);
                $this->markRetryOrFail($row, $t->getMessage());
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function processRow(array $row): void
    {
        throw new \LogicException('processRow: implemented in Task 11');
    }

    /** @param array<string,mixed> $row */
    private function markRetryOrFail(array $row, string $errorMessage): void
    {
        $attempts = (int) $row['attempts'] + 1;
        if ($attempts >= $this->maxAttempts) {
            $this->repo->markFailed((int) $row['id'], $attempts, $errorMessage);
            return;
        }
        $delaySec    = self::BACKOFF_SECONDS[$attempts - 1] ?? self::BACKOFF_SECONDS[count(self::BACKOFF_SECONDS) - 1];
        $nextAttempt = ($this->clock)()->modify("+{$delaySec} seconds");
        $this->repo->markRetry((int) $row['id'], $attempts, $nextAttempt, $errorMessage);
    }
}
