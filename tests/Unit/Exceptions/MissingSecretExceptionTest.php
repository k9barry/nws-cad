<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Exceptions;

use NwsCad\Exceptions\MissingSecretException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \NwsCad\Exceptions\MissingSecretException
 */
class MissingSecretExceptionTest extends TestCase
{
    public function testForKeyProducesPredictableMessage(): void
    {
        $e = MissingSecretException::forKey('NTFY_AUTH_TOKEN');

        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertSame('Required secret "NTFY_AUTH_TOKEN" is not set in the environment.', $e->getMessage());
        $this->assertSame('NTFY_AUTH_TOKEN', $e->getKey());
    }
}
