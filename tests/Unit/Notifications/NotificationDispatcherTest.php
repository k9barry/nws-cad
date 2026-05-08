<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use DateTimeImmutable;
use Mockery;
use NwsCad\Notifications\ChannelRepositoryInterface;
use NwsCad\Notifications\NotificationChannel;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationDispatcher;
use NwsCad\Notifications\SendResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\NotificationDispatcher
 * @uses \NwsCad\Config
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 * @uses \NwsCad\Notifications\Events\CallProcessedEvent
 * @uses \NwsCad\Notifications\Events\Intent
 * @uses \NwsCad\Notifications\IncidentDto
 * @uses \NwsCad\Notifications\NotificationContext
 * @uses \NwsCad\Notifications\SendResult
 */
class NotificationDispatcherTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testDeltaTimeGateSkipsOldEvents(): void
    {
        $repo = Mockery::mock(ChannelRepositoryInterface::class);
        $repo->shouldNotReceive('listEnabled');
        $loader = function (int $id): IncidentDto {
            $this->fail('loader should not be called');
        };
        $factory = fn () => $this->fail('channel factory should not be called');

        $dispatcher = new NotificationDispatcher(
            channelRepo: $repo,
            incidentLoader: $loader,
            channelFactory: $factory,
            deltaSeconds: 900,
            clock: fn () => new DateTimeImmutable('2026-05-07 12:30:00'),
        );

        $event = new CallProcessedEvent(
            dbCallId: 1,
            intent: Intent::Created,
            changedFields: [],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),  // 30 min ago
        );

        $dispatcher->handle($event);
        $this->assertTrue(true);  // assertion is the mock expectations above
    }

    public function testClosedIntentDoesNotCallChannels(): void
    {
        $repo = Mockery::mock(ChannelRepositoryInterface::class);
        $repo->shouldNotReceive('listEnabled');

        $dispatcher = new NotificationDispatcher(
            channelRepo: $repo,
            incidentLoader: fn () => $this->dto(),
            channelFactory: fn () => $this->fail(),
            deltaSeconds: 900,
            clock: fn () => new DateTimeImmutable('2026-05-07 12:00:30'),
        );

        $dispatcher->handle(new CallProcessedEvent(
            dbCallId: 1, intent: Intent::Closed, changedFields: [],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        ));
        $this->assertTrue(true);
    }

    public function testCreatedIntentSendsToAllDerivedTopics(): void
    {
        $repo = Mockery::mock(ChannelRepositoryInterface::class);
        $repo->shouldReceive('listEnabled')->andReturn([
            ['id' => 1, 'name' => 'n', 'type' => 'ntfy', 'enabled' => true,
             'base_url' => 'u', 'config_json' => '{}'],
        ]);
        $repo->shouldReceive('recordSend')->atLeast()->once();

        $channel = Mockery::mock(NotificationChannel::class);
        $channel->shouldReceive('send')
            ->once()
            ->withArgs(function ($dto, $ctx) {
                return $ctx->intent === Intent::Created
                    && $ctx->resendAll === true
                    && in_array('Fire', $ctx->topicsToNotify, true)
                    && in_array('MCFD', $ctx->topicsToNotify, true)
                    && in_array('ENGINE1', $ctx->topicsToNotify, true);
            })
            ->andReturn([SendResult::ok(200, 5, 'Fire')]);

        $dispatcher = new NotificationDispatcher(
            channelRepo: $repo,
            incidentLoader: fn () => $this->dto(),
            channelFactory: fn () => $channel,
            deltaSeconds: 900,
            clock: fn () => new DateTimeImmutable('2026-05-07 12:00:30'),
        );

        $dispatcher->handle(new CallProcessedEvent(
            dbCallId: 1, intent: Intent::Created, changedFields: [],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        ));
        $this->addToAssertionCount(1);  // Mockery withArgs validates ctx
    }

    public function testUpdatedIntentResendsAllWhenCallTypeChanged(): void
    {
        $repo = Mockery::mock(ChannelRepositoryInterface::class);
        $repo->shouldReceive('listEnabled')->andReturn([
            ['id' => 1, 'name' => 'n', 'type' => 'ntfy', 'enabled' => true,
             'base_url' => 'u', 'config_json' => '{}'],
        ]);
        $repo->shouldReceive('recordSend')->atLeast()->once();

        $channel = Mockery::mock(NotificationChannel::class);
        $channel->shouldReceive('send')->once()->withArgs(function ($dto, $ctx) {
            return $ctx->resendAll === true
                && in_array('Fire', $ctx->topicsToNotify, true)
                && in_array('MCFD', $ctx->topicsToNotify, true)
                && in_array('ENGINE1', $ctx->topicsToNotify, true);
        })->andReturn([SendResult::ok(200, 5, 'T')]);

        $dispatcher = new NotificationDispatcher(
            channelRepo: $repo,
            incidentLoader: fn () => $this->dto(),
            channelFactory: fn () => $channel,
            deltaSeconds: 900,
            clock: fn () => new DateTimeImmutable('2026-05-07 12:00:30'),
        );

        $dispatcher->handle(new CallProcessedEvent(
            dbCallId: 1, intent: Intent::Updated, changedFields: ['call_type'],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
            addedTopics: [],
        ));
        $this->addToAssertionCount(1);  // Mockery withArgs validates ctx
    }

    public function testUpdatedIntentWithOnlyNewUnitsSendsOnlyToAddedTopics(): void
    {
        $repo = Mockery::mock(ChannelRepositoryInterface::class);
        $repo->shouldReceive('listEnabled')->andReturn([
            ['id' => 1, 'name' => 'n', 'type' => 'ntfy', 'enabled' => true,
             'base_url' => 'u', 'config_json' => '{}'],
        ]);
        $repo->shouldReceive('recordSend')->atLeast()->once();

        $channel = Mockery::mock(NotificationChannel::class);
        $channel->shouldReceive('send')->once()->withArgs(function ($dto, $ctx) {
            return $ctx->resendAll === false
                && $ctx->topicsToNotify === ['TRUCK1'];
        })->andReturn([SendResult::ok(200, 5, 'TRUCK1')]);

        $dispatcher = new NotificationDispatcher(
            channelRepo: $repo,
            incidentLoader: fn () => $this->dto(),
            channelFactory: fn () => $channel,
            deltaSeconds: 900,
            clock: fn () => new DateTimeImmutable('2026-05-07 12:00:30'),
        );

        $dispatcher->handle(new CallProcessedEvent(
            dbCallId: 1, intent: Intent::Updated, changedFields: ['assigned_units'],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
            addedTopics: ['TRUCK1'],
        ));
        $this->addToAssertionCount(1);  // Mockery withArgs validates ctx
    }

    public function testUpdatedIntentWithNoAddedTopicsAndNoResendTriggerSendsNothing(): void
    {
        $repo = Mockery::mock(ChannelRepositoryInterface::class);
        $repo->shouldNotReceive('listEnabled');

        $dispatcher = new NotificationDispatcher(
            channelRepo: $repo,
            incidentLoader: fn () => $this->dto(),
            channelFactory: fn () => $this->fail('factory should not be called'),
            deltaSeconds: 900,
            clock: fn () => new DateTimeImmutable('2026-05-07 12:00:30'),
        );

        $dispatcher->handle(new CallProcessedEvent(
            dbCallId: 1, intent: Intent::Updated, changedFields: ['assigned_units'],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
            addedTopics: [],
        ));
        $this->assertTrue(true);  // assertion is the mock expectations above
    }

    private function dto(): IncidentDto
    {
        return IncidentDto::fromRow([
            'id' => 1, 'call_id' => 100, 'call_number' => 'C',
            'call_type' => 'Fire',
            'agency_type' => 'Fire',
            'jurisdiction' => 'MCFD',
            'units' => 'ENGINE1',
            'create_datetime' => '2026-05-07 12:00:00',
        ]);
    }
}
