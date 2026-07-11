<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\Import\DateTimeParser;
use PHPUnit\Framework\TestCase;

/**
 * Direct tests for the extracted DateTimeParser (#49). Mirrors the golden
 * values in DateTimeParsingCharacterizationTest, which locked the original
 * AegisXmlParser::parseDateTime() behavior this class was extracted from.
 *
 * @covers \NwsCad\Import\DateTimeParser
 */
class DateTimeParserTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function formatProvider(): array
    {
        return [
            'ISO 8601 with literal Z'        => ['2024-01-15T13:45:30Z', '2024-01-15 13:45:30'],
            'ISO 8601 no timezone'           => ['2024-01-15T13:45:30', '2024-01-15 13:45:30'],
            'ISO 8601 with microseconds'     => ['2024-01-15T13:45:30.123456', '2024-01-15 13:45:30'],
            'ISO 8601 with offset'           => ['2024-01-15T13:45:30+05:00', '2024-01-15 13:45:30'],
            'ISO 8601 microseconds + offset' => ['2024-01-15T13:45:30.123456+05:00', '2024-01-15 13:45:30'],
            'MySQL datetime'                 => ['2024-01-15 13:45:30', '2024-01-15 13:45:30'],
            'US 24-hour'                     => ['01/15/2024 13:45:30', '2024-01-15 13:45:30'],
            'US 12-hour with AM/PM'          => ['01/15/2024 01:45:30 PM', '2024-01-15 13:45:30'],
            'strtotime fallback (long form)' => ['January 15, 2024 1:45pm', '2024-01-15 13:45:00'],
        ];
    }

    /**
     * @dataProvider formatProvider
     */
    public function testNormalizesSupportedFormats(string $input, string $expected): void
    {
        $this->assertSame($expected, DateTimeParser::parse($input));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function nullProvider(): array
    {
        return [
            'null'              => [null],
            'empty string'      => [''],
            'literal nil'       => ['nil'],
            'xsi nil attribute' => ['nil="true"'],
            'unparseable junk'  => ['not a date'],
        ];
    }

    /**
     * @dataProvider nullProvider
     */
    public function testReturnsNullForEmptyNilAndUnparseable(?string $input): void
    {
        $this->assertNull(DateTimeParser::parse($input));
    }

    public function testOverflowingComponentsAreNormalizedNotRejected(): void
    {
        $result = DateTimeParser::parse('2024-13-45T99:99:99');
        $this->assertNotNull($result);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);
    }
}
