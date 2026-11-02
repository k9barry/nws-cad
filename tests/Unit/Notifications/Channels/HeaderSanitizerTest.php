<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Channels;

use NwsCad\Notifications\Channels\HeaderSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeaderSanitizer::class)]
final class HeaderSanitizerTest extends TestCase
{
    public function testCrlfAndControlCharsAreReplaced(): void
    {
        $this->assertSame(
            'Call: 1 Fire X-Evil: 1',
            HeaderSanitizer::cleanValue("Call: 1 Fire\r\nX-Evil: 1\x00\x7F"),
        );
    }

    public function testTabIsPreserved(): void
    {
        $this->assertSame("a\tb", HeaderSanitizer::cleanValue("a\tb"));
    }

    public function testInvalidNamesAreDropped(): void
    {
        $lines = HeaderSanitizer::lines([
            'Title' => "x\ny",
            "Bad\r\nName" => 'v',
            'With Space' => 'v',
            'Priority' => 4,
        ]);
        $this->assertSame(['Title: x y', 'Priority: 4'], $lines);
    }

    public function testValidName(): void
    {
        $this->assertTrue(HeaderSanitizer::isValidName('X-Api-Key'));
        $this->assertFalse(HeaderSanitizer::isValidName(''));
        $this->assertFalse(HeaderSanitizer::isValidName('X:Y'));
    }
}
