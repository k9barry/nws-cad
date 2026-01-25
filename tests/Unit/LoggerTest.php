<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\Logger;
use NwsCad\Config;
use PHPUnit\Framework\TestCase;
use Monolog\Logger as MonologLogger;

/**
 * @covers \NwsCad\Logger
 */
class LoggerTest extends TestCase
{
    public function testGetInstanceReturnsMonologLogger(): void
    {
        $logger = Logger::getInstance();
        
        $this->assertInstanceOf(MonologLogger::class, $logger);
    }

    public function testGetInstanceReturnsSameInstance(): void
    {
        $logger1 = Logger::getInstance();
        $logger2 = Logger::getInstance();
        
        $this->assertSame($logger1, $logger2, 'Logger should be singleton');
    }

    public function testLoggerHasCorrectName(): void
    {
        $logger = Logger::getInstance();
        
        $this->assertEquals('nws-cad', $logger->getName());
    }

    public function testLoggerHasHandlers(): void
    {
        $logger = Logger::getInstance();
        $handlers = $logger->getHandlers();
        
        $this->assertNotEmpty($handlers, 'Logger should have at least one handler');
    }

    public function testLoggerCanLogDebugMessage(): void
    {
        $logger = Logger::getInstance();
        
        // Should not throw exception
        $logger->debug('Test debug message');
        $this->assertTrue(true);
    }

    public function testLoggerCanLogInfoMessage(): void
    {
        $logger = Logger::getInstance();
        
        // Should not throw exception
        $logger->info('Test info message');
        $this->assertTrue(true);
    }

    public function testLoggerCanLogWarningMessage(): void
    {
        $logger = Logger::getInstance();
        
        // Should not throw exception
        $logger->warning('Test warning message');
        $this->assertTrue(true);
    }

    public function testLoggerCanLogErrorMessage(): void
    {
        $logger = Logger::getInstance();
        
        // Should not throw exception
        $logger->error('Test error message');
        $this->assertTrue(true);
    }

    public function testLoggerCanLogWithContext(): void
    {
        $logger = Logger::getInstance();
        
        // Should not throw exception
        $logger->info('Test message with context', ['key' => 'value', 'user_id' => 123]);
        $this->assertTrue(true);
    }

    public function testLoggerHandlesSpecialCharacters(): void
    {
        $logger = Logger::getInstance();
        
        // Should not throw exception
        $logger->info('Test with special chars: <>&"\'');
        $this->assertTrue(true);
    }
}
