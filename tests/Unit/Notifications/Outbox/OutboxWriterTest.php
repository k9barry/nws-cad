<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Outbox;

use DateTimeImmutable;
use Mockery;
use NwsCad\Notifications\ChannelRepositoryInterface;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\Outbox\OutboxRepositoryInterface;
use NwsCad\Notifications\Outbox\OutboxWriter;
use NwsCad\Notifications\TopicResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxWriter::class)]
#[UsesClass(TopicResolver::class)]
#[UsesClass(CallProcessedEvent::class)]
#[UsesClass(Intent::class)]
#[UsesClass(\NwsCad\Config::class)]
#[UsesClass(\NwsCad\Logger::class)]
#[UsesClass(\NwsCad\Logging\RedactingProcessor::class)]
#[UsesClass(\NwsCad\Logging\SecretRegistry::class)]
final class OutboxWriterTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;


    private function writer(
        OutboxRepositoryInterface $repo,
        ChannelRepositoryInterface $channelRepo,
        int $deltaSeconds = 900,
        ?DateTimeImmutable $now = null,
    ): OutboxWriter {
        return new OutboxWriter(
            $repo,
            $channelRepo,
            $deltaSeconds,
            static fn (): DateTimeImmutable => $now ?? new DateTimeImmutable('2026-05-07 12:05:00'),
        );
    }

    public function testClosedIntentIsNoop(): void
    {
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldNotReceive('insert');
        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldNotReceive('listEnabled');

        $event = new CallProcessedEvent(
            dbCallId: 1, intent: Intent::Closed, changedFields: [],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );
        $this->writer($repo, $channels)->handle($event);
        $this->assertTrue(true);
    }

    public function testDeltaTimeGateSkipsOldEvents(): void
    {
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldNotReceive('insert');
        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldNotReceive('listEnabled');

        $event = new CallProcessedEvent(
            dbCallId: 1, intent: Intent::Created, changedFields: [],
            createDateTime: new DateTimeImmutable('2026-05-07 11:00:00'),
        );
        $this->writer($repo, $channels, 900, new DateTimeImmutable('2026-05-07 12:30:00'))->handle($event);
        $this->assertTrue(true);
    }

    public function testCreatedInsertsOneRowPerEnabledChannelWithResendAllTrue(): void
    {
        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldReceive('listEnabled')->once()->andReturn([
            ['id' => 7,  'name' => 'ntfy_primary',     'type' => 'ntfy',     'enabled' => true, 'base_url' => 'u', 'config_json' => '{}'],
            ['id' => 11, 'name' => 'pushover_primary', 'type' => 'pushover', 'enabled' => true, 'base_url' => 'u', 'config_json' => '{}'],
        ]);

        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('insert')->once()
            ->with(42, 7, Intent::Created, true, ['IN048'], Mockery::type(DateTimeImmutable::class))
            ->andReturn(101);
        $repo->shouldReceive('insert')->once()
            ->with(42, 11, Intent::Created, true, ['IN048'], Mockery::type(DateTimeImmutable::class))
            ->andReturn(102);

        $event = new CallProcessedEvent(
            dbCallId: 42, intent: Intent::Created, changedFields: [],
            addedTopics: ['IN048'],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );
        $this->writer($repo, $channels)->handle($event);
    }

    public function testUpdatedWithoutTriggerFieldSetsResendAllFalse(): void
    {
        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldReceive('listEnabled')->once()->andReturn([
            ['id' => 7, 'name' => 'ntfy_primary', 'type' => 'ntfy', 'enabled' => true, 'base_url' => 'u', 'config_json' => '{}'],
        ]);

        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('insert')->once()
            ->with(42, 7, Intent::Updated, false, ['E2'], Mockery::type(DateTimeImmutable::class))
            ->andReturn(101);

        $event = new CallProcessedEvent(
            dbCallId: 42, intent: Intent::Updated, changedFields: ['narrative'],
            addedTopics: ['E2'],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );
        $this->writer($repo, $channels)->handle($event);
    }

    public function testUpdatedWithTriggerFieldSetsResendAllTrue(): void
    {
        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldReceive('listEnabled')->once()->andReturn([
            ['id' => 7, 'name' => 'ntfy_primary', 'type' => 'ntfy', 'enabled' => true, 'base_url' => 'u', 'config_json' => '{}'],
        ]);

        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('insert')->once()
            ->with(42, 7, Intent::Updated, true, ['E2'], Mockery::type(DateTimeImmutable::class))
            ->andReturn(101);

        $event = new CallProcessedEvent(
            dbCallId: 42, intent: Intent::Updated, changedFields: ['call_type'],
            addedTopics: ['E2'],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );
        $this->writer($repo, $channels)->handle($event);
    }

    public function testNoEnabledChannelsIsNoop(): void
    {
        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldReceive('listEnabled')->once()->andReturn([]);
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldNotReceive('insert');

        $event = new CallProcessedEvent(
            dbCallId: 42, intent: Intent::Created, changedFields: [],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );
        $this->writer($repo, $channels)->handle($event);
        $this->assertTrue(true);
    }
}
