<?php
declare(strict_types=1);

namespace NwsCad\Tests\Unit\Filtering;

use NwsCad\Api\Filtering\FilterCriteria;
use NwsCad\Api\Filtering\FilterRegistry;
use NwsCad\Api\Filtering\InvalidFilterException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Filtering\FilterCriteria
 * @uses \NwsCad\Api\Filtering\DateRange
 * @uses \NwsCad\Api\Filtering\FilterRegistry
 * @uses \NwsCad\Api\Filtering\InvalidFilterException
 */
final class FilterCriteriaTest extends TestCase
{
    private array $allowed;

    protected function setUp(): void
    {
        $this->allowed = FilterRegistry::for('calls');
    }

    public function testEmptyQueryProducesEmptyCriteria(): void
    {
        $c = FilterCriteria::fromQuery([], $this->allowed);
        $this->assertNull($c->dateRange);
        $this->assertSame([], $c->callType);
        $this->assertSame([], $c->status);
    }

    public function testParsesCsvIntoArray(): void
    {
        $c = FilterCriteria::fromQuery(['call_type' => 'Police,Fire,EMS'], $this->allowed);
        $this->assertSame(['Police', 'Fire', 'EMS'], $c->callType);
    }

    public function testTrimsAndDropsEmptyValues(): void
    {
        $c = FilterCriteria::fromQuery(['call_type' => ' Police , ,Fire '], $this->allowed);
        $this->assertSame(['Police', 'Fire'], $c->callType);
    }

    public function testDropsParamsNotInAllowlist(): void
    {
        $c = FilterCriteria::fromQuery(['unauthorized' => 'x', 'call_type' => 'Police'], $this->allowed);
        $this->assertSame(['Police'], $c->callType);
    }

    public function testEnforcesFiftyValueCap(): void
    {
        $values = implode(',', array_fill(0, 51, 'Police'));
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessage('Too many values');
        FilterCriteria::fromQuery(['call_type' => $values], $this->allowed);
    }

    public function testEnforces256CharCapPerValue(): void
    {
        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromQuery(['nature_of_call' => str_repeat('a', 257)], $this->allowed);
    }

    public function testStatusEnumValidated(): void
    {
        $c = FilterCriteria::fromQuery(['status' => 'open,closed'], $this->allowed);
        $this->assertSame(['open', 'closed'], $c->status);

        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromQuery(['status' => 'banana'], $this->allowed);
    }

    public function testDateFieldEnumValidated(): void
    {
        $c = FilterCriteria::fromQuery(['date_field' => 'closed'], $this->allowed);
        $this->assertSame('closed', $c->dateField);

        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromQuery(['date_field' => 'invented'], $this->allowed);
    }

    public function testPresetResolvesToDateRange(): void
    {
        $c = FilterCriteria::fromQuery(['preset' => 'today'], $this->allowed);
        $this->assertNotNull($c->dateRange);
    }

    public function testExplicitFromToOverridesPreset(): void
    {
        $c = FilterCriteria::fromQuery(
            ['preset' => 'today', 'from' => '2026-01-01', 'to' => '2026-01-31'],
            $this->allowed
        );
        $this->assertSame('2026-01-01 00:00:00', $c->dateRange->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-31 23:59:59', $c->dateRange->to->format('Y-m-d H:i:s'));
    }

    public function testToArrayRoundTripsParseable(): void
    {
        $c = FilterCriteria::fromQuery(
            ['call_type' => 'Police,Fire', 'status' => 'open', 'q' => 'jane'],
            $this->allowed
        );
        $arr = $c->toArray();
        $this->assertSame(['Police', 'Fire'], $arr['call_type']);
        $this->assertSame(['open'], $arr['status']);
        $this->assertSame('jane', $arr['q']);
    }
}
