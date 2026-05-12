<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Security;

use NwsCad\Security\SecurityHeaders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecurityHeaders::class)]
final class SecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        SecurityHeaders::resetNonceForTesting();
    }

    protected function tearDown(): void
    {
        SecurityHeaders::resetNonceForTesting();
    }

    public function testNonceIsStableWithinRequest(): void
    {
        $first  = SecurityHeaders::nonce();
        $second = SecurityHeaders::nonce();
        $this->assertSame($first, $second);
    }

    public function testNonceFormatIsBase64WithoutPadding(): void
    {
        $nonce = SecurityHeaders::nonce();
        // 16 random bytes → 24 chars base64 → 22 chars without `==` padding.
        $this->assertSame(22, strlen($nonce));
        // CSP source-list grammar forbids whitespace and a few other chars,
        // but base64's [A-Za-z0-9+/] is all safe. We allow url-safe variants
        // too (the encoder may swap +/ for -_ in some PHP builds), so accept
        // both alphabets.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/_-]{22}$/', $nonce);
    }

    public function testResetGeneratesNewValue(): void
    {
        $first = SecurityHeaders::nonce();
        SecurityHeaders::resetNonceForTesting();
        $second = SecurityHeaders::nonce();
        $this->assertNotSame($first, $second);
    }

    public function testTwoIndependentNoncesAreUnique(): void
    {
        // Generate enough nonces to make a collision astronomically unlikely.
        // With 128 bits of entropy a collision would imply we drew the same
        // 16 random bytes twice in two PHP calls — random_bytes(16) failing
        // open is a far bigger problem than this test could catch.
        $values = [];
        for ($i = 0; $i < 20; $i++) {
            SecurityHeaders::resetNonceForTesting();
            $values[] = SecurityHeaders::nonce();
        }
        $this->assertCount(20, array_unique($values));
    }
}
