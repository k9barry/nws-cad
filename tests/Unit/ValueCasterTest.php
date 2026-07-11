<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\Import\ValueCaster;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Import\ValueCaster
 */
class ValueCasterTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function boolProvider(): array
    {
        return [
            'true'        => ['true', 1],
            'TRUE upper'  => ['TRUE', 1],
            'padded true' => ['  true  ', 1],
            'one'         => ['1', 1],
            'yes'         => ['yes', 1],
            'false'       => ['false', 0],
            'zero'        => ['0', 0],
            'no'          => ['no', 0],
            'empty'       => ['', 0],
            'arbitrary'   => ['maybe', 0],
        ];
    }

    /**
     * @dataProvider boolProvider
     */
    public function testToBool(mixed $input, int $expected): void
    {
        $this->assertSame($expected, ValueCaster::toBool($input));
    }

    public function testToIntParsesAndNullsNilMarkers(): void
    {
        $this->assertSame(42, ValueCaster::toInt('42'));
        $this->assertSame(-7, ValueCaster::toInt('-7'));
        $this->assertNull(ValueCaster::toInt(null));
        $this->assertNull(ValueCaster::toInt(''));
        $this->assertNull(ValueCaster::toInt('nil'));
        $this->assertNull(ValueCaster::toInt('nil="true"'));
    }

    public function testToDecimalParsesAndNullsNilMarkers(): void
    {
        $this->assertSame(3.14, ValueCaster::toDecimal('3.14'));
        $this->assertSame(-90.5, ValueCaster::toDecimal('-90.5'));
        $this->assertNull(ValueCaster::toDecimal(null));
        $this->assertNull(ValueCaster::toDecimal(''));
        $this->assertNull(ValueCaster::toDecimal('nil'));
        $this->assertNull(ValueCaster::toDecimal('nil="true"'));
    }
}
