<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\AegisXmlParser;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Characterization tests for AegisXmlParser::parseDateTime().
 *
 * These lock the *current* datetime-normalization behavior so the planned
 * importer refactor (#49, which extracts a standalone DateTimeParser) can be
 * verified as behavior-preserving. The golden outputs here were captured from
 * the live method — do not "fix" them; if a value looks wrong, that is a bug
 * to be addressed deliberately in the refactor, not silently in this test.
 *
 * parseDateTime() reads no instance state for any of these inputs (the only
 * `$this` use is a logger->warning in the strtotime catch, which our inputs
 * never trigger), so we exercise it on an object built without the
 * DB-connecting constructor — keeping this a pure, driver-independent unit.
 *
 * @covers \NwsCad\AegisXmlParser
 * @uses \NwsCad\Import\DateTimeParser
 */
class DateTimeParsingCharacterizationTest extends TestCase
{
    private static function invokeParseDateTime(?string $input): ?string
    {
        $parser = (new \ReflectionClass(AegisXmlParser::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AegisXmlParser::class, 'parseDateTime');
        $method->setAccessible(true);

        /** @var string|null $result */
        $result = $method->invoke($parser, $input);
        return $result;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function explicitFormatProvider(): array
    {
        // Each of the eight ordered formats parseDateTime() tries, plus the
        // strtotime() fallback, normalized to 'Y-m-d H:i:s'. Timezone offsets
        // are preserved as wall-clock time (format() does not shift the value).
        return [
            'ISO 8601 with literal Z'          => ['2024-01-15T13:45:30Z', '2024-01-15 13:45:30'],
            'ISO 8601 no timezone'             => ['2024-01-15T13:45:30', '2024-01-15 13:45:30'],
            'ISO 8601 with microseconds'       => ['2024-01-15T13:45:30.123456', '2024-01-15 13:45:30'],
            'ISO 8601 with offset'             => ['2024-01-15T13:45:30+05:00', '2024-01-15 13:45:30'],
            'ISO 8601 microseconds + offset'   => ['2024-01-15T13:45:30.123456+05:00', '2024-01-15 13:45:30'],
            'MySQL datetime'                   => ['2024-01-15 13:45:30', '2024-01-15 13:45:30'],
            'US 24-hour'                       => ['01/15/2024 13:45:30', '2024-01-15 13:45:30'],
            'US 12-hour with AM/PM'            => ['01/15/2024 01:45:30 PM', '2024-01-15 13:45:30'],
            'strtotime fallback (long form)'   => ['January 15, 2024 1:45pm', '2024-01-15 13:45:00'],
        ];
    }

    /**
     * @dataProvider explicitFormatProvider
     */
    public function testNormalizesEachSupportedFormat(string $input, string $expected): void
    {
        $this->assertSame($expected, self::invokeParseDateTime($input));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nullProvider(): array
    {
        return [
            'empty string'      => [''],
            'literal nil'       => ['nil'],
            'xsi nil attribute' => ['nil="true"'],
            'unparseable junk'  => ['not a date'],
        ];
    }

    /**
     * @dataProvider nullProvider
     */
    public function testReturnsNullForEmptyNilAndUnparseable(string $input): void
    {
        $this->assertNull(self::invokeParseDateTime($input));
    }

    public function testNullInputReturnsNull(): void
    {
        $this->assertNull(self::invokeParseDateTime(null));
    }

    /**
     * Overflowing components (month 13, day 45, hour 99...) are silently
     * normalized by PHP's date arithmetic rather than rejected. The exact
     * rolled-over value is PHP-version dependent and intentionally not pinned;
     * we only lock the observable contract that such input yields a non-null
     * normalized timestamp string (i.e. it does NOT fall through to null).
     */
    public function testOverflowingComponentsAreNormalizedNotRejected(): void
    {
        $result = self::invokeParseDateTime('2024-13-45T99:99:99');
        $this->assertNotNull($result);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $result
        );
    }
}
