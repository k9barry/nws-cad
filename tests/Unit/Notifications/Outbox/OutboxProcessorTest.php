<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Outbox;

use DateTimeImmutable;
use Mockery;
use NwsCad\Notifications\ChannelFactoryInterface;
use NwsCad\Notifications\ChannelRepositoryInterface;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationChannel;
use NwsCad\Notifications\NotificationContext;
use NwsCad\Notifications\Outbox\OutboxProcessor;
use NwsCad\Notifications\Outbox\OutboxRepositoryInterface;
use NwsCad\Notifications\SendResult;
use NwsCad\Notifications\TopicResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxProcessor::class)]
#[UsesClass(TopicResolver::class)]
#[UsesClass(IncidentDto::class)]
#[UsesClass(NotificationContext::class)]
#[UsesClass(SendResult::class)]
#[UsesClass(Intent::class)]
#[UsesClass(\NwsCad\Config::class)]
#[UsesClass(\NwsCad\Logger::class)]
#[UsesClass(\NwsCad\Logging\RedactingProcessor::class)]
#[UsesClass(\NwsCad\Logging\SecretRegistry::class)]
final class OutboxProcessorTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function testTickRunsHousekeepingThenClaimsAndProcesses(): void
    {
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('prune')->once()->with(7 * 86400);
        $repo->shouldReceive('resetOrphans')->once()->with('me:1:111');
        $repo->shouldReceive('claim')->once()->andReturn([]);

        $factory  = Mockery::mock(ChannelFactoryInterface::class);
        $channels = Mockery::mock(ChannelRepositoryInterface::class);

        $processor = new OutboxProcessor(
            $repo,
            $factory,
            $channels,
            static fn (int $id): IncidentDto => self::fail('loader should not be called when no rows claimed'),
            batchSize:    10,
            maxAttempts:  5,
            workerId:     'me:1:111',
            clock:        static fn () => new DateTimeImmutable('2026-05-07 12:00:00'),
        );

        $processor->tick();
    }

    public function testTickIsolatedFromHousekeepingFailure(): void
    {
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('prune')->once()->andThrow(new \RuntimeException('db down briefly'));
        $repo->shouldReceive('resetOrphans')->never();
        $repo->shouldReceive('claim')->once()->andReturn([]);

        $factory  = Mockery::mock(ChannelFactoryInterface::class);
        $channels = Mockery::mock(ChannelRepositoryInterface::class);

        $processor = new OutboxProcessor(
            $repo,
            $factory,
            $channels,
            static fn (int $id): IncidentDto => self::fail('loader should not be called'),
            batchSize:    10,
            maxAttempts:  5,
            workerId:     'me:1:111',
        );

        $processor->tick();
    }

    private function row(array $overrides = []): array
    {
        return $overrides + [
            'id'                 => 1,
            'db_call_id'         => 100,
            'channel_id'         => 7,
            'intent'             => 'Created',
            'resend_all'         => 1,
            'added_topics_json'  => '[]',
            'create_datetime'    => '2026-05-07 12:00:00',
            'status'             => 'in_flight',
            'attempts'           => 0,
            'next_attempt_at'    => null,
            'claimed_at'         => '2026-05-07 12:05:00',
            'claimed_by'         => 'me:1:111',
            'last_error'         => null,
        ];
    }

    private function dto(): IncidentDto
    {
        return IncidentDto::fromRow([
            'id' => 100, 'call_id' => 100, 'call_number' => 'C-100',
            'agency_type' => 'Fire', 'jurisdiction' => 'IN048',
            'units' => 'E1', 'alarm_level' => 1, 'create_datetime' => '2026-05-07 12:00:00',
        ]);
    }

    private function channelRow(): array
    {
        return ['id' => 7, 'name' => 'ntfy_primary', 'type' => 'ntfy', 'enabled' => true, 'base_url' => 'u', 'config_json' => '{}'];
    }

    public function testProcessRowMarksDoneWhenAllResultsOk(): void
    {
        $row = $this->row();
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('prune')->once();
        $repo->shouldReceive('resetOrphans')->once();
        $repo->shouldReceive('claim')->once()->andReturn([$row]);
        $repo->shouldReceive('markDone')->once()->with(1);
        $repo->shouldReceive('markRetry')->never();
        $repo->shouldReceive('markFailed')->never();

        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldReceive('findById')->once()->with(7)->andReturn($this->channelRow());
        $channels->shouldReceive('recordSend')->once()
            ->with(7, 100, 'Created', Mockery::on(static fn (SendResult $r) => $r->ok));

        $channel = Mockery::mock(NotificationChannel::class);
        $channel->shouldReceive('send')->once()->andReturn([SendResult::ok(200, 10, 'IN048')]);

        $factory = Mockery::mock(ChannelFactoryInterface::class);
        $factory->shouldReceive('create')->once()->andReturn($channel);

        (new OutboxProcessor(
            $repo, $factory, $channels,
            fn (int $id): IncidentDto => $this->dto(),
            batchSize: 10, maxAttempts: 5, workerId: 'me:1:111',
            clock: static fn () => new DateTimeImmutable('2026-05-07 12:05:00'),
        ))->tick();
    }

    public function testProcessRowMarksRetryWhenAllResultsFail(): void
    {
        $row = $this->row(['attempts' => 1]);
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('prune')->once();
        $repo->shouldReceive('resetOrphans')->once();
        $repo->shouldReceive('claim')->once()->andReturn([$row]);
        $repo->shouldReceive('markDone')->never();
        $repo->shouldReceive('markRetry')->once()
            ->with(1, 2, Mockery::type(DateTimeImmutable::class), Mockery::pattern('/HTTP 503/'));

        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldReceive('findById')->once()->with(7)->andReturn($this->channelRow());
        $channels->shouldReceive('recordSend')->once();
        $channels->shouldReceive('markFailure')->once();

        $channel = Mockery::mock(NotificationChannel::class);
        $channel->shouldReceive('send')->once()->andReturn([SendResult::fail(503, 9, 'Service Unavailable', 'IN048')]);

        $factory = Mockery::mock(ChannelFactoryInterface::class);
        $factory->shouldReceive('create')->once()->andReturn($channel);

        (new OutboxProcessor(
            $repo, $factory, $channels,
            fn (int $id): IncidentDto => $this->dto(),
            batchSize: 10, maxAttempts: 5, workerId: 'me:1:111',
            clock: static fn () => new DateTimeImmutable('2026-05-07 12:05:00'),
        ))->tick();
    }

    public function testProcessRowMarksFailedAtMaxAttempts(): void
    {
        $row = $this->row(['attempts' => 4]);
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('prune')->once();
        $repo->shouldReceive('resetOrphans')->once();
        $repo->shouldReceive('claim')->once()->andReturn([$row]);
        $repo->shouldReceive('markFailed')->once()->with(1, 5, Mockery::pattern('/HTTP 503/'));
        $repo->shouldReceive('markRetry')->never();
        $repo->shouldReceive('markDone')->never();

        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldReceive('findById')->once()->andReturn($this->channelRow());
        $channels->shouldReceive('recordSend')->once();
        $channels->shouldReceive('markFailure')->once();

        $channel = Mockery::mock(NotificationChannel::class);
        $channel->shouldReceive('send')->once()->andReturn([SendResult::fail(503, 9, 'Service Unavailable', 'IN048')]);

        $factory = Mockery::mock(ChannelFactoryInterface::class);
        $factory->shouldReceive('create')->once()->andReturn($channel);

        (new OutboxProcessor(
            $repo, $factory, $channels,
            fn (int $id): IncidentDto => $this->dto(),
            batchSize: 10, maxAttempts: 5, workerId: 'me:1:111',
            clock: static fn () => new DateTimeImmutable('2026-05-07 12:05:00'),
        ))->tick();
    }

    public function testProcessRowMarksDoneWhenAnyResultOk(): void
    {
        $row = $this->row();
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('prune')->once();
        $repo->shouldReceive('resetOrphans')->once();
        $repo->shouldReceive('claim')->once()->andReturn([$row]);
        $repo->shouldReceive('markDone')->once()->with(1);

        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldReceive('findById')->once()->andReturn($this->channelRow());
        $channels->shouldReceive('recordSend')->twice();
        $channels->shouldReceive('markFailure')->once();

        $channel = Mockery::mock(NotificationChannel::class);
        $channel->shouldReceive('send')->once()->andReturn([
            SendResult::fail(503, 9, 'fail', 'IN048'),
            SendResult::ok(200, 10, 'E1'),
        ]);

        $factory = Mockery::mock(ChannelFactoryInterface::class);
        $factory->shouldReceive('create')->once()->andReturn($channel);

        (new OutboxProcessor(
            $repo, $factory, $channels,
            fn (int $id): IncidentDto => $this->dto(),
            batchSize: 10, maxAttempts: 5, workerId: 'me:1:111',
            clock: static fn () => new DateTimeImmutable('2026-05-07 12:05:00'),
        ))->tick();
    }

    public function testProcessRowMarksDoneWhenNoTopicsResolved(): void
    {
        $row = $this->row([
            'resend_all'        => 0,
            'added_topics_json' => '[]',
        ]);
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('prune')->once();
        $repo->shouldReceive('resetOrphans')->once();
        $repo->shouldReceive('claim')->once()->andReturn([$row]);
        $repo->shouldReceive('markDone')->once()->with(1);

        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldReceive('findById')->never();
        $factory = Mockery::mock(ChannelFactoryInterface::class);
        $factory->shouldReceive('create')->never();

        (new OutboxProcessor(
            $repo, $factory, $channels,
            fn (int $id): IncidentDto => $this->dto(),
            batchSize: 10, maxAttempts: 5, workerId: 'me:1:111',
            clock: static fn () => new DateTimeImmutable('2026-05-07 12:05:00'),
        ))->tick();
    }

    public function testProcessRowMarksFailedWhenChannelMissing(): void
    {
        $row = $this->row();
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('prune')->once();
        $repo->shouldReceive('resetOrphans')->once();
        $repo->shouldReceive('claim')->once()->andReturn([$row]);
        $repo->shouldReceive('markFailed')->once()->with(1, 1, Mockery::pattern('/missing/'));

        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldReceive('findById')->once()->andReturn(null);
        $factory = Mockery::mock(ChannelFactoryInterface::class);
        $factory->shouldReceive('create')->never();

        (new OutboxProcessor(
            $repo, $factory, $channels,
            fn (int $id): IncidentDto => $this->dto(),
            batchSize: 10, maxAttempts: 5, workerId: 'me:1:111',
            clock: static fn () => new DateTimeImmutable('2026-05-07 12:05:00'),
        ))->tick();
    }
}
