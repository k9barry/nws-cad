<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use DateTimeImmutable;
use NwsCad\Logger;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use Throwable;

final class NotificationDispatcher
{
    /** @var callable(int):IncidentDto */
    private $incidentLoader;
    /** @var callable(array<string,mixed>):NotificationChannel */
    private $channelFactory;

    private const RESEND_ALL_TRIGGERS = ['call_type', 'full_address', 'alarm_level'];

    public function __construct(
        private readonly ChannelRepository $channelRepo,
        callable $incidentLoader,
        callable $channelFactory,
        private readonly int $deltaSeconds,
        private readonly DateTimeImmutable $now = new DateTimeImmutable(),
    ) {
        $this->incidentLoader = $incidentLoader;
        $this->channelFactory = $channelFactory;
    }

    public function handle(CallProcessedEvent $event): void
    {
        $logger = Logger::getInstance();

        if ($event->intent === Intent::Closed) {
            $logger->info('Notification dispatch: Closed intent, no-op', ['dbCallId' => $event->dbCallId]);
            return;
        }

        $age = $this->now->getTimestamp() - $event->createDateTime->getTimestamp();
        if ($age > $this->deltaSeconds) {
            $logger->info('Notification dispatch: delta-time gate dropped event', [
                'dbCallId' => $event->dbCallId, 'age_seconds' => $age, 'limit' => $this->deltaSeconds,
            ]);
            return;
        }

        try {
            $dto = ($this->incidentLoader)($event->dbCallId);
        } catch (Throwable $t) {
            $logger->error('Notification dispatch: failed to load incident', [
                'dbCallId' => $event->dbCallId, 'error' => $t->getMessage(),
            ]);
            return;
        }

        $resendAll = $event->intent === Intent::Updated
            && count(array_intersect(self::RESEND_ALL_TRIGGERS, $event->changedFields)) > 0;

        $topics = $this->buildTopics($dto);

        $context = new NotificationContext(
            intent: $event->intent,
            resendAll: $resendAll,
            topicsToNotify: $topics,
            channelConfig: [],
        );

        foreach ($this->channelRepo->listEnabled() as $row) {
            try {
                $channel = ($this->channelFactory)($row);
            } catch (Throwable $t) {
                $logger->warning('Notification dispatch: channel factory failed', [
                    'channel' => $row['name'], 'error' => $t->getMessage(),
                ]);
                $this->channelRepo->markFailure((int) $row['id'], $t->getMessage());
                continue;
            }

            try {
                $results = $channel->send($dto, $context);
            } catch (Throwable $t) {
                $logger->error('Notification dispatch: channel send threw', [
                    'channel' => $row['name'], 'error' => $t->getMessage(),
                ]);
                $this->channelRepo->markFailure((int) $row['id'], $t->getMessage());
                continue;
            }

            foreach ($results as $r) {
                $this->channelRepo->recordSend(
                    (int) $row['id'],
                    $event->dbCallId,
                    $event->intent->value,
                    $r,
                );
                if (! $r->ok) {
                    $this->channelRepo->markFailure(
                        (int) $row['id'],
                        ($r->httpStatus ? "HTTP {$r->httpStatus}: " : '') . ($r->error ?? 'unknown'),
                    );
                }
            }
        }
    }

    /** @return string[] */
    private function buildTopics(IncidentDto $dto): array
    {
        $derived = array_filter([
            $dto->agencyType,
            $dto->jurisdiction,
            ...explode('|', $dto->units),
        ], static fn (?string $v) => $v !== null && $v !== '');

        return array_values(array_unique($derived));
    }
}
