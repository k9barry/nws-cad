<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use DateTimeImmutable;
use NwsCad\Notifications\EventDispatcher;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \NwsCad\Notifications\EventDispatcher
 */
class EventDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        EventDispatcher::reset();
    }

    public function testDispatchesToAllSubscribers(): void
    {
        $seen = [];
        EventDispatcher::subscribe(function (CallProcessedEvent $e) use (&$seen): void {
            $seen[] = "a:{$e->dbCallId}";
        });
        EventDispatcher::subscribe(function (CallProcessedEvent $e) use (&$seen): void {
            $seen[] = "b:{$e->dbCallId}";
        });

        EventDispatcher::dispatch(new CallProcessedEvent(
            dbCallId: 7,
            intent: Intent::Created,
            changedFields: [],
            createDateTime: new DateTimeImmutable(),
        ));

        $this->assertSame(['a:7', 'b:7'], $seen);
    }

    public function testSubscriberExceptionIsCaughtAndOtherSubscribersStillRun(): void
    {
        $seen = [];
        EventDispatcher::subscribe(function (): void {
            throw new RuntimeException('boom');
        });
        EventDispatcher::subscribe(function (CallProcessedEvent $e) use (&$seen): void {
            $seen[] = $e->dbCallId;
        });

        EventDispatcher::dispatch(new CallProcessedEvent(
            dbCallId: 9,
            intent: Intent::Updated,
            changedFields: ['call_type'],
            createDateTime: new DateTimeImmutable(),
        ));

        $this->assertSame([9], $seen);
    }

    public function testResetClearsSubscribers(): void
    {
        $seen = false;
        EventDispatcher::subscribe(function () use (&$seen): void {
            $seen = true;
        });

        EventDispatcher::reset();

        EventDispatcher::dispatch(new CallProcessedEvent(
            dbCallId: 1,
            intent: Intent::Closed,
            changedFields: [],
            createDateTime: new DateTimeImmutable(),
        ));

        $this->assertFalse($seen);
    }
}
