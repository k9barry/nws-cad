<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Outbox;

use NwsCad\Notifications\Outbox\WorkerId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerId::class)]
final class WorkerIdTest extends TestCase
{
    protected function setUp(): void
    {
        WorkerId::reset();
    }

    protected function tearDown(): void
    {
        WorkerId::reset();
    }

    public function testCurrentIsStableAcrossCalls(): void
    {
        $first  = WorkerId::current();
        $second = WorkerId::current();
        $this->assertSame($first, $second);
    }

    public function testFormatIsHostColonPidColonTimestamp(): void
    {
        $id = WorkerId::current();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+:[0-9]+:[0-9]+$/', $id);
    }

    public function testResetProducesNewTimestampOnNextCall(): void
    {
        $first = WorkerId::current();
        WorkerId::reset();
        sleep(1);
        $second = WorkerId::current();
        $this->assertNotSame($first, $second);
    }
}
