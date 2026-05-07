<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use NwsCad\Notifications\TopicSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\TopicSanitizer
 */
class TopicSanitizerTest extends TestCase
{
    public function testKeepsAlnumDashUnderscore(): void
    {
        $this->assertSame('Engine_1-A', TopicSanitizer::clean('Engine_1-A'));
    }

    public function testReplacesIllegalCharsWithUnderscore(): void
    {
        $this->assertSame('Fire_MCFD', TopicSanitizer::clean('Fire/MCFD'));
        $this->assertSame('A_B', TopicSanitizer::clean('A?B'));
    }

    public function testCollapsesRunsAndTrimsUnderscores(): void
    {
        $this->assertSame('A_B', TopicSanitizer::clean('  A//??B  '));
    }

    public function testReturnsNullWhenEmptyAfterClean(): void
    {
        $this->assertNull(TopicSanitizer::clean(''));
        $this->assertNull(TopicSanitizer::clean('  '));
        $this->assertNull(TopicSanitizer::clean('???'));
        $this->assertNull(TopicSanitizer::clean('___'));
    }

    public function testStripsCrlf(): void
    {
        $this->assertSame('A_B', TopicSanitizer::clean("A\r\nB"));
    }

    public function testHandlesMultibyte(): void
    {
        // Non-ASCII letters get byte-replaced and collapsed/trimmed.
        $this->assertSame('caf', TopicSanitizer::clean('café'));
        $this->assertSame('A', TopicSanitizer::clean('Aé'));
    }

    public function testRejectsPathTraversalSegment(): void
    {
        $this->assertNull(TopicSanitizer::clean('..'));
        $this->assertSame('a', TopicSanitizer::clean('../a'));
    }
}
