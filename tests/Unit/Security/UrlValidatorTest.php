<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Security;

use NwsCad\Config;
use NwsCad\Security\UrlValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Security\UrlValidator
 * @uses \NwsCad\Config
 * @uses \NwsCad\Security\InputValidator
 * @uses \NwsCad\Security\TrustedProxy
 */
class UrlValidatorTest extends TestCase
{
    private Config $cfg;

    protected function setUp(): void
    {
        parent::setUp();
        unset($_ENV['NOTIFICATION_BASE_URL_ALLOWLIST']);
        unset($_ENV['NOTIFICATION_ALLOW_HTTP_PRIVATE']);
        putenv('NOTIFICATION_BASE_URL_ALLOWLIST');
        putenv('NOTIFICATION_ALLOW_HTTP_PRIVATE');

        $this->resetConfig();
        $this->cfg = Config::getInstance();
    }

    public function testAcceptsHttpsUrl(): void
    {
        $r = UrlValidator::validateChannelBaseUrl('https://ntfy.example.com', $this->cfg);
        $this->assertTrue($r['ok']);
    }

    public function testRejectsHttpScheme(): void
    {
        $r = UrlValidator::validateChannelBaseUrl('http://ntfy.example.com', $this->cfg);
        $this->assertFalse($r['ok']);
        $this->assertSame('scheme', $r['reason']);
    }

    public function testRejectsCrLf(): void
    {
        $r = UrlValidator::validateChannelBaseUrl("https://a.example\r\nX: y", $this->cfg);
        $this->assertFalse($r['ok']);
        $this->assertSame('crlf', $r['reason']);
    }

    public function testRejectsMalformed(): void
    {
        $r = UrlValidator::validateChannelBaseUrl('not a url', $this->cfg);
        $this->assertFalse($r['ok']);
        $this->assertSame('malformed', $r['reason']);
    }

    public function testRejectsHostNotInAllowlist(): void
    {
        $_ENV['NOTIFICATION_BASE_URL_ALLOWLIST'] = 'ntfy.example.com,push.example.com';
        $this->resetConfig();
        $cfg = Config::getInstance();

        $r = UrlValidator::validateChannelBaseUrl('https://attacker.example/', $cfg);
        $this->assertFalse($r['ok']);
        $this->assertSame('host', $r['reason']);
    }

    public function testAcceptsHostInAllowlist(): void
    {
        $_ENV['NOTIFICATION_BASE_URL_ALLOWLIST'] = 'ntfy.example.com';
        $this->resetConfig();
        $cfg = Config::getInstance();

        $r = UrlValidator::validateChannelBaseUrl('https://ntfy.example.com/topic', $cfg);
        $this->assertTrue($r['ok']);
    }

    public function testRejectsHttpToPrivateWhenFlagOff(): void
    {
        $r = UrlValidator::validateChannelBaseUrl('http://127.0.0.1:8080', $this->cfg);
        $this->assertFalse($r['ok']);
        $this->assertSame('scheme', $r['reason']);
    }

    public function testAcceptsHttpToPrivateWhenFlagOn(): void
    {
        $_ENV['NOTIFICATION_ALLOW_HTTP_PRIVATE'] = 'true';
        $this->resetConfig();
        $cfg = Config::getInstance();

        $r = UrlValidator::validateChannelBaseUrl('http://127.0.0.1:8080', $cfg);
        $this->assertTrue($r['ok']);
    }

    public function testRejectsHttpToPublicEvenWithFlagOn(): void
    {
        $_ENV['NOTIFICATION_ALLOW_HTTP_PRIVATE'] = 'true';
        $this->resetConfig();
        $cfg = Config::getInstance();

        $r = UrlValidator::validateChannelBaseUrl('http://attacker.example/', $cfg);
        $this->assertFalse($r['ok']);
        $this->assertSame('scheme', $r['reason']);
    }

    public function testRejectsLinkLocalSsrf(): void
    {
        // Link-local must NOT be treated as private for the HTTP allowance.
        $_ENV['NOTIFICATION_ALLOW_HTTP_PRIVATE'] = 'true';
        $this->resetConfig();
        $cfg = Config::getInstance();

        $r = UrlValidator::validateChannelBaseUrl('http://169.254.169.254/', $cfg);
        $this->assertFalse($r['ok']);
        $this->assertSame('scheme', $r['reason']);
    }

    private function resetConfig(): void
    {
        $reflection = new \ReflectionClass(Config::class);
        $prop = $reflection->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}
