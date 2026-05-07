<?php

declare(strict_types=1);

namespace NwsCad\Tests\Security;

use Monolog\Handler\TestHandler;
use NwsCad\Config;
use NwsCad\Logger;
use NwsCad\Logging\SecretRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Logging\RedactingProcessor
 * @covers \NwsCad\Config
 */
class SecretRedactionTest extends TestCase
{
    public function testSecretsAreScrubbedFromMessageAndContext(): void
    {
        SecretRegistry::reset();
        $_ENV['REDACTION_TEST_TOKEN'] = 'super-secret-abc-123';
        Config::getInstance()->secret('REDACTION_TEST_TOKEN');

        $logger = Logger::getInstance();
        $h = new TestHandler();
        $logger->pushHandler($h);

        $logger->info(
            'sent header Authorization: Bearer super-secret-abc-123',
            ['payload' => ['header' => 'Bearer super-secret-abc-123']],
        );

        $records = $h->getRecords();
        $logger->popHandler();
        $r = end($records);

        $this->assertStringNotContainsString('super-secret-abc-123', $r->message);
        $this->assertStringNotContainsString('super-secret-abc-123', json_encode($r->context));
        $this->assertStringContainsString('***', $r->message);

        unset($_ENV['REDACTION_TEST_TOKEN']);
    }
}
