<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Events;

use DateTimeImmutable;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\Events\CallProcessedEvent
 * @covers \NwsCad\Notifications\Events\Intent
 */
class CallProcessedEventTest extends TestCase
{
    public function testHoldsAllFields(): void
    {
        $now = new DateTimeImmutable('2026-05-07 12:34:56');

        $e = new CallProcessedEvent(
            dbCallId: 42,
            intent: Intent::Created,
            changedFields: ['call_type', 'alarm_level'],
            createDateTime: $now,
        );

        $this->assertSame(42, $e->dbCallId);
        $this->assertSame(Intent::Created, $e->intent);
        $this->assertSame(['call_type', 'alarm_level'], $e->changedFields);
        $this->assertSame($now, $e->createDateTime);
    }

    public function testChangedFieldsDefaultsToEmpty(): void
    {
        $e = new CallProcessedEvent(
            dbCallId: 1,
            intent: Intent::Closed,
            changedFields: [],
            createDateTime: new DateTimeImmutable(),
        );

        $this->assertSame([], $e->changedFields);
    }
}
