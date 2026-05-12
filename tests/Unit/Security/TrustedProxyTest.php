<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Security;

use NwsCad\Security\TrustedProxy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Security\TrustedProxy
 */
class TrustedProxyTest extends TestCase
{
    public function testLoopbackV4IsInDefaults(): void
    {
        $this->assertTrue(TrustedProxy::inAny('127.0.0.1', ['127.0.0.1/32', '::1/128']));
    }

    public function testLoopbackV6IsInDefaults(): void
    {
        $this->assertTrue(TrustedProxy::inAny('::1', ['127.0.0.1/32', '::1/128']));
    }

    public function testExternalV4Rejected(): void
    {
        $this->assertFalse(TrustedProxy::inAny('203.0.113.1', ['127.0.0.1/32', '::1/128']));
    }

    public function testCidrMaskV4(): void
    {
        $this->assertTrue(TrustedProxy::inAny('10.0.5.20', ['10.0.0.0/16']));
        $this->assertTrue(TrustedProxy::inAny('10.0.255.255', ['10.0.0.0/16']));
        $this->assertFalse(TrustedProxy::inAny('10.1.0.0', ['10.0.0.0/16']));
    }

    public function testCidrMaskV6(): void
    {
        $this->assertTrue(TrustedProxy::inAny('fd00::1', ['fd00::/8']));
        $this->assertFalse(TrustedProxy::inAny('fe80::1', ['fd00::/8']));
    }

    public function testHostBitsMaskedNotInterpreted(): void
    {
        $this->assertTrue(TrustedProxy::inAny('192.168.1.5', ['192.168.1.0/24']));
    }

    public function testEmptyIpReturnsFalse(): void
    {
        $this->assertFalse(TrustedProxy::inAny('', ['127.0.0.1/32']));
    }

    public function testMalformedCidrReturnsFalse(): void
    {
        $this->assertFalse(TrustedProxy::inAny('127.0.0.1', ['not-a-cidr']));
    }

    public function testMixedV4V6ListsHandled(): void
    {
        $cidrs = ['127.0.0.1/32', '::1/128', '10.0.0.0/8'];
        $this->assertTrue(TrustedProxy::inAny('10.5.5.5', $cidrs));
        $this->assertTrue(TrustedProxy::inAny('::1', $cidrs));
        $this->assertFalse(TrustedProxy::inAny('2001:db8::1', $cidrs));
    }
}
