<?php

declare(strict_types=1);

namespace NwsCad\Tests\Security;

use NwsCad\Api\Response;
use NwsCad\Config;
use NwsCad\Security\RateLimiter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Exercises RateLimiter's exempt path, fail-open behavior, and (where APCu is
 * available) the 429 threshold. enforce() emits via Response, which in tests
 * echoes into an output buffer rather than exiting.
 */
#[CoversNothing]
class RateLimiterTest extends TestCase
{
    private int $initialObLevel = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Response::resetForTesting();
        unset($GLOBALS['__identity']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $this->initialObLevel = ob_get_level();
        ob_start();
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->initialObLevel) {
            ob_end_clean();
        }
        unset($_SERVER['REQUEST_URI'], $_SERVER['REMOTE_ADDR']);
        putenv('RATE_LIMIT_PER_MINUTE');
        parent::tearDown();
    }

    public function testHealthEndpointIsExempt(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/health';
        putenv('RATE_LIMIT_PER_MINUTE=1');
        // Even well over the limit, health is never throttled.
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::enforce(Config::getInstance());
        }
        $this->assertSame('', (string) ob_get_clean(), 'health check must never be throttled');
        ob_start();
    }

    public function testFailsOpenWhenApcuUnavailable(): void
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $this->markTestSkipped('APCu is available; this test covers the fail-open path');
        }
        $_SERVER['REQUEST_URI'] = '/api/calls';
        putenv('RATE_LIMIT_PER_MINUTE=1');
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::enforce(Config::getInstance());
        }
        $this->assertSame('', (string) ob_get_clean(), 'without APCu the limiter must fail open');
        ob_start();
    }

    public function testDisabledWhenLimitIsZero(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/calls';
        putenv('RATE_LIMIT_PER_MINUTE=0');
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::enforce(Config::getInstance());
        }
        $this->assertSame('', (string) ob_get_clean(), 'a zero limit disables throttling');
        ob_start();
    }

    public function testExceedingLimitReturns429(): void
    {
        if (! (function_exists('apcu_enabled') && apcu_enabled())) {
            $this->markTestSkipped('APCu not available; cannot exercise the throttling path');
        }
        // Unique client so the fixed-window counter starts fresh.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int(1, 254);
        $_SERVER['REQUEST_URI'] = '/api/calls';
        putenv('RATE_LIMIT_PER_MINUTE=2');

        RateLimiter::enforce(Config::getInstance()); // 1 - ok
        RateLimiter::enforce(Config::getInstance()); // 2 - ok
        RateLimiter::enforce(Config::getInstance()); // 3 - over limit

        $payload = json_decode((string) ob_get_clean(), true);
        ob_start();
        $this->assertIsArray($payload);
        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('Rate limit', $payload['error']);
    }
}
