<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Logging;

use NwsCad\Logging\SecretRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Logging\SecretRegistry
 */
class SecretRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        SecretRegistry::reset();
    }

    public function testRegisterAndGetAll(): void
    {
        SecretRegistry::register('hunter2');
        SecretRegistry::register('s3cr3t');

        $values = SecretRegistry::getAll();

        sort($values);
        $this->assertSame(['hunter2', 's3cr3t'], $values);
    }

    public function testRegisterDeduplicates(): void
    {
        SecretRegistry::register('abc');
        SecretRegistry::register('abc');

        $this->assertCount(1, SecretRegistry::getAll());
    }

    public function testRegisterIgnoresEmptyAndShortValues(): void
    {
        SecretRegistry::register('');
        SecretRegistry::register('xy');

        $this->assertSame([], SecretRegistry::getAll());
    }

    public function testReset(): void
    {
        SecretRegistry::register('abc');
        SecretRegistry::reset();

        $this->assertSame([], SecretRegistry::getAll());
    }
}
