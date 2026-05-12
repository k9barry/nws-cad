<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration\Security;

use NwsCad\Api\Response;
use NwsCad\Config;
use NwsCad\Security\TrustedProxy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Security\TrustedProxy
 * @uses \NwsCad\Api\Response
 * @uses \NwsCad\Config
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 */
class BootstrapTrustGuardTest extends TestCase
{
    private int $initialObLevel = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Response::resetForTesting();
        $this->initialObLevel = ob_get_level();
        ob_start();
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->initialObLevel) {
            ob_end_clean();
        }
        parent::tearDown();
    }

    public function testTrustedLoopbackPassesThrough(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        try {
            TrustedProxy::guard(Config::getInstance());
        } catch (\Exception $e) {
            $this->fail('TrustedProxy::guard threw on trusted loopback: ' . $e->getMessage());
        }
        ob_end_clean();
        $this->assertTrue(true);
    }

    public function testUntrustedRemoteIs403(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        TrustedProxy::guard(Config::getInstance());

        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('Direct access not permitted', $payload['error']);
    }
}
