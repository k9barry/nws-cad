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

    /**
     * Per-tick memo of loaded incidents, keyed by db_call_id. Several outbox
     * rows in one batch commonly target the same call (one row per channel), and
     * the incident loader runs a multi-subquery SELECT — so cache within a tick.
     *
     * @var array<int, IncidentDto>
     */
    private array $incidentCache = [];

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
        $this->incidentCache = []; // fresh memo each tick — call state may have changed
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

    private function loadIncident(int $callId): IncidentDto
    {
        return $this->incidentCache[$callId] ??= ($this->incidentLoader)($callId);
    }

    /** @param array<string,mixed> $row */
    private function processRow(array $row): void
    {
        $logger      = Logger::getInstance();
        $outboxId    = (int) $row['id'];
        $callId      = (int) $row['db_call_id'];
        $channelId   = (int) $row['channel_id'];
        $intent      = Intent::from((string) $row['intent']);
        $resendAll   = (bool) (int) $row['resend_all'];
        $addedTopics = json_decode((string) $row['added_topics_json'], true) ?: [];

        $dto    = $this->loadIncident($callId);
        $topics = TopicResolver::resolveTopics($dto, $resendAll, $addedTopics);

        if ($topics === []) {
            $logger->info('Outbox processRow: no topics, marking done', ['outboxId' => $outboxId]);
            $this->repo->markDone($outboxId);
            return;
        }

        $channelRow = $this->channelRepo->findById($channelId);
        if ($channelRow === null) {
            $logger->warning('Outbox processRow: channel missing', [
                'outboxId' => $outboxId, 'channelId' => $channelId,
            ]);
            $attempts = (int) $row['attempts'] + 1;
            $this->repo->markFailed($outboxId, $attempts, "Channel #{$channelId} missing");
            return;
        }

        $channel = $this->factory->create($channelRow);
        $context = new NotificationContext(
            intent:         $intent,
            resendAll:      $resendAll,
            topicsToNotify: $topics,
            channelConfig:  [],
        );

        $results = $channel->send($dto, $context);

        if ($results === []) {
            $logger->info('Outbox processRow: channel returned no results, marking done', ['outboxId' => $outboxId]);
            $this->repo->markDone($outboxId);
            return;
        }

        $anyOk    = false;
        $firstErr = '';
        foreach ($results as $r) {
            $this->channelRepo->recordSend($channelId, $callId, $intent->value, $r);
            if ($r->ok) {
                $anyOk = true;
            } else {
                $msg = ($r->httpStatus ? "HTTP {$r->httpStatus}: " : '') . ($r->error ?? 'unknown');
                $this->channelRepo->markFailure($channelId, $msg);
                if ($firstErr === '') {
                    $firstErr = $msg;
                }
            }
        }

        if ($anyOk) {
            $this->repo->markDone($outboxId);
            return;
        }
        $this->markRetryOrFail($row, $firstErr);
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
