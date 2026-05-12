<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Outbox;

use DateTimeImmutable;
use NwsCad\Logger;
use NwsCad\Notifications\ChannelRepositoryInterface;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\TopicResolver;

final class OutboxWriter
{
    /** @var callable():DateTimeImmutable */
    private $clock;

    public function __construct(
        private readonly OutboxRepositoryInterface $repo,
        private readonly ChannelRepositoryInterface $channelRepo,
        private readonly int $deltaSeconds,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function handle(CallProcessedEvent $event): void
    {
        $logger = Logger::getInstance();

        if ($event->intent === Intent::Closed) {
            $logger->info('Outbox writer: Closed intent, no-op', ['dbCallId' => $event->dbCallId]);
            return;
        }

        $now = ($this->clock)();
        $age = $now->getTimestamp() - $event->createDateTime->getTimestamp();
        if ($age > $this->deltaSeconds) {
            $logger->info('Outbox writer: delta-time gate dropped event', [
                'dbCallId' => $event->dbCallId, 'age_seconds' => $age, 'limit' => $this->deltaSeconds,
            ]);
            return;
        }

        $channels = $this->channelRepo->listEnabled();
        if ($channels === []) {
            $logger->info('Outbox writer: no enabled channels', ['dbCallId' => $event->dbCallId]);
            return;
        }

        $resendAll = TopicResolver::shouldResendAll($event->intent, $event->changedFields);
        $inserted  = 0;
        foreach ($channels as $row) {
            $this->repo->insert(
                callId:         $event->dbCallId,
                channelId:      (int) $row['id'],
                intent:         $event->intent,
                resendAll:      $resendAll,
                addedTopics:    $event->addedTopics,
                createDateTime: $event->createDateTime,
            );
            $inserted++;
        }
        $logger->info('Outbox writer: queued', [
            'dbCallId' => $event->dbCallId,
            'intent'   => $event->intent->value,
            'rows'     => $inserted,
        ]);
    }
}
