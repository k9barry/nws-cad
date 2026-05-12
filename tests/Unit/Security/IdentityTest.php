<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Security;

use NwsCad\Config;
use NwsCad\Security\Identity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Security\Identity
 * @uses \NwsCad\Config
 */
class IdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_SERVER['HTTP_X_AUTH_USER']);
        unset($GLOBALS['__identity']);
    }

    public function testExtractReturnsNullWhenHeaderMissing(): void
    {
        $id = Identity::extract(Config::getInstance());
        $this->assertNull($id->user);
    }

    public function testExtractReadsValidHeader(): void
    {
        $_SERVER['HTTP_X_AUTH_USER'] = 'k9barry';
        $id = Identity::extract(Config::getInstance());
        $this->assertSame('k9barry', $id->user);
    }

    public function testExtractRejectsCrLf(): void
    {
        $_SERVER['HTTP_X_AUTH_USER'] = "admin\r\nX-Forwarded-For: 10.0.0.1";
        $id = Identity::extract(Config::getInstance());
        $this->assertNull($id->user);
    }

    public function testExtractRejectsOversize(): void
    {
        $_SERVER['HTTP_X_AUTH_USER'] = str_repeat('a', 65);
        $id = Identity::extract(Config::getInstance());
        $this->assertNull($id->user);
    }

    public function testExtractAllowsAllowedSpecials(): void
    {
        $_SERVER['HTTP_X_AUTH_USER'] = 'k9.barry.admin@example-co';
        $id = Identity::extract(Config::getInstance());
        $this->assertSame('k9.barry.admin@example-co', $id->user);
    }

    public function testExtractRejectsSpace(): void
    {
        $_SERVER['HTTP_X_AUTH_USER'] = 'k9 barry';
        $id = Identity::extract(Config::getInstance());
        $this->assertNull($id->user);
    }

    public function testCurrentReturnsStashedIdentity(): void
    {
        $_SERVER['HTTP_X_AUTH_USER'] = 'someone';
        $GLOBALS['__identity'] = Identity::extract(Config::getInstance());

        $this->assertSame('someone', Identity::current()->user);
    }

    public function testCurrentReturnsAnonymousWhenNoStash(): void
    {
        unset($GLOBALS['__identity']);
        $this->assertNull(Identity::current()->user);
    }
}
