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
}
