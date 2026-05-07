<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\Config;
use NwsCad\Exceptions\MissingSecretException;
use NwsCad\Logging\SecretRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Config
 * @uses \NwsCad\Logging\SecretRegistry
 * @uses \NwsCad\Exceptions\MissingSecretException
 */
class ConfigSecretTest extends TestCase
{
    protected function setUp(): void
    {
        SecretRegistry::reset();
    }

    public function testSecretReturnsValueFromEnvAndRegistersIt(): void
    {
        $_ENV['TEST_SECRET_VALUE'] = 'tok-abcdefg';

        $value = Config::getInstance()->secret('TEST_SECRET_VALUE');

        $this->assertSame('tok-abcdefg', $value);
        $this->assertContains('tok-abcdefg', SecretRegistry::getAll());

        unset($_ENV['TEST_SECRET_VALUE']);
    }

    public function testSecretThrowsWhenMissing(): void
    {
        unset($_ENV['UNSET_SECRET_KEY']);
        putenv('UNSET_SECRET_KEY');

        $this->expectException(MissingSecretException::class);
        $this->expectExceptionMessage('Required secret "UNSET_SECRET_KEY"');

        Config::getInstance()->secret('UNSET_SECRET_KEY');
    }

    public function testSecretOptionalReturnsNullWhenMissing(): void
    {
        unset($_ENV['UNSET_OPTIONAL_KEY']);
        putenv('UNSET_OPTIONAL_KEY');

        $this->assertNull(Config::getInstance()->secretOptional('UNSET_OPTIONAL_KEY'));
    }
}
