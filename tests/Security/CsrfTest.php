<?php

declare(strict_types=1);

namespace NwsCad\Tests\Security;

use NwsCad\Api\Response;
use NwsCad\Config;
use NwsCad\Security\SameOriginGuard;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Verifies SameOriginGuard rejects cross-origin state-changing requests while
 * allowing same-origin requests, safe methods, and non-browser clients. The
 * guard emits a 403 JSON body via Response (which, in tests, echoes into an
 * output buffer instead of exiting), so we assert on the captured output.
 */
#[CoversNothing]
class CsrfTest extends TestCase
{
    private int $initialObLevel = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Response::resetForTesting();
        // Clean any origin/fetch state from a prior test.
        unset(
            $_SERVER['HTTP_SEC_FETCH_SITE'],
            $_SERVER['HTTP_ORIGIN'],
            $_SERVER['HTTP_HOST'],
            $_SERVER['HTTPS'],
            $_SERVER['HTTP_X_FORWARDED_PROTO']
        );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->initialObLevel = ob_get_level();
        ob_start();
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->initialObLevel) {
            ob_end_clean();
        }
        unset(
            $_SERVER['HTTP_SEC_FETCH_SITE'],
            $_SERVER['HTTP_ORIGIN'],
            $_SERVER['HTTP_HOST'],
            $_SERVER['REQUEST_METHOD']
        );
        parent::tearDown();
    }

    private function assertRejected(): void
    {
        $payload = json_decode((string) ob_get_clean(), true);
        ob_start();
        $this->assertIsArray($payload, 'Guard should have emitted a JSON body');
        $this->assertFalse($payload['success']);
        $this->assertSame('Cross-origin request not permitted', $payload['error']);
    }

    private function assertAllowed(): void
    {
        $output = (string) ob_get_clean();
        ob_start();
        $this->assertSame('', $output, 'Guard should not emit for an allowed request');
    }

    public function testSafeMethodIsAlwaysAllowed(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
        SameOriginGuard::guard(Config::getInstance());
        $this->assertAllowed();
    }

    public function testCrossSiteUnsafeMethodIsRejected(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
        SameOriginGuard::guard(Config::getInstance());
        $this->assertRejected();
    }

    public function testSameOriginUnsafeMethodIsAllowed(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
        SameOriginGuard::guard(Config::getInstance());
        $this->assertAllowed();
    }

    public function testCrossOriginViaOriginHeaderIsRejected(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        $_SERVER['HTTP_HOST'] = 'dashboard.example';
        SameOriginGuard::guard(Config::getInstance());
        $this->assertRejected();
    }

    public function testSameOriginViaOriginHeaderIsAllowed(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_ORIGIN'] = 'http://dashboard.example';
        $_SERVER['HTTP_HOST'] = 'dashboard.example';
        SameOriginGuard::guard(Config::getInstance());
        $this->assertAllowed();
    }

    public function testNonBrowserClientWithNoOriginHeadersIsAllowed(): void
    {
        // curl / server-to-server / the file watcher send neither signal.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        SameOriginGuard::guard(Config::getInstance());
        $this->assertAllowed();
    }
}
