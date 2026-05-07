<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use NwsCad\Notifications\SendResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\SendResult
 */
class SendResultTest extends TestCase
{
    public function testOkFactory(): void
    {
        $r = SendResult::ok(httpStatus: 200, durationMs: 17, topic: 'Fire_MCFD');

        $this->assertTrue($r->ok);
        $this->assertSame(200, $r->httpStatus);
        $this->assertSame(17, $r->durationMs);
        $this->assertSame('Fire_MCFD', $r->topic);
        $this->assertNull($r->error);
    }

    public function testFailFactory(): void
    {
        $r = SendResult::fail(httpStatus: 502, durationMs: 30, error: 'bad gateway');

        $this->assertFalse($r->ok);
        $this->assertSame(502, $r->httpStatus);
        $this->assertSame('bad gateway', $r->error);
        $this->assertNull($r->topic);
    }
}
