<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Logging;

use Monolog\Handler\TestHandler;
use NwsCad\Logger;
use NwsCad\Logging\SecretRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Logger
 * @covers \NwsCad\Logging\RedactingProcessor
 */
class LoggerRedactionWiringTest extends TestCase
{
    public function testLoggerScrubsRegisteredSecretsBeforeHandlersSeeThem(): void
    {
        SecretRegistry::reset();
        SecretRegistry::register('topsecret-abc-123');

        $logger = Logger::getInstance();
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $logger->info('auth header was Bearer topsecret-abc-123');

        $records = $handler->getRecords();
        $logger->popHandler();

        $this->assertNotEmpty($records);
        $last = end($records);
        $this->assertStringContainsString('***', $last->message);
        $this->assertStringNotContainsString('topsecret-abc-123', $last->message);
    }
}
