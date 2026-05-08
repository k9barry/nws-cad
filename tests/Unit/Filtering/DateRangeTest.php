<?php
declare(strict_types=1);

namespace NwsCad\Tests\Unit\Filtering;

use NwsCad\Api\Filtering\DateRange;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

/**
 * @covers \NwsCad\Api\Filtering\DateRange
 */
final class DateRangeTest extends TestCase
{
    public function testFromPresetTodayResolvesToStartAndEndOfToday(): void
    {
        $tz = new \DateTimeZone('America/Indiana/Indianapolis');
        $r = DateRange::fromPreset('today', $tz);
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $this->assertSame($today . ' 00:00:00', $r->from->format('Y-m-d H:i:s'));
        $this->assertSame($today . ' 23:59:59', $r->to->format('Y-m-d H:i:s'));
    }

    public function testFromPresetLast7DaysSpansSevenDayWindow(): void
    {
        $tz = new \DateTimeZone('America/Indiana/Indianapolis');
        $r = DateRange::fromPreset('last_7_days', $tz);
        $diff = $r->to->getTimestamp() - $r->from->getTimestamp();
        $this->assertGreaterThan(6 * 86400, $diff);
        $this->assertLessThanOrEqual(7 * 86400, $diff);
    }

    public function testFromExplicitDateOnlyExpandsToEndOfDay(): void
    {
        $r = DateRange::fromExplicit('2026-05-01', '2026-05-08', new \DateTimeZone('UTC'));
        $this->assertSame('2026-05-01 00:00:00', $r->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-08 23:59:59', $r->to->format('Y-m-d H:i:s'));
    }

    public function testFromExplicitDateTimePassesThroughVerbatim(): void
    {
        $r = DateRange::fromExplicit('2026-05-01T08:00:00', '2026-05-01T17:30:00', new \DateTimeZone('UTC'));
        $this->assertSame('2026-05-01 08:00:00', $r->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-01 17:30:00', $r->to->format('Y-m-d H:i:s'));
    }

    public function testInvalidPresetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DateRange::fromPreset('next_century', new \DateTimeZone('UTC'));
    }
}
