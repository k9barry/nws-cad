<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Logging;

use Monolog\Level;
use Monolog\LogRecord;
use NwsCad\Logging\RedactingProcessor;
use NwsCad\Logging\SecretRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Logging\RedactingProcessor
 */
class RedactingProcessorTest extends TestCase
{
    protected function setUp(): void
    {
        SecretRegistry::reset();
    }

    private function record(string $message, array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    public function testRedactsSecretInMessage(): void
    {
        SecretRegistry::register('hunter2');
        $proc = new RedactingProcessor();

        $out = ($proc)($this->record('login token=hunter2 ok'));

        $this->assertSame('login token=*** ok', $out->message);
    }

    public function testRedactsSecretInNestedContext(): void
    {
        SecretRegistry::register('topsecret');
        $proc = new RedactingProcessor();

        $out = ($proc)($this->record('hello', ['headers' => ['Authorization' => 'Bearer topsecret']]));

        $this->assertSame('Bearer ***', $out->context['headers']['Authorization']);
    }

    public function testRedactsSecretInExtra(): void
    {
        SecretRegistry::register('xyzabc');
        $proc = new RedactingProcessor();

        $out = ($proc)($this->record('m', [], ['raw' => 'value:xyzabc']));

        $this->assertSame('value:***', $out->extra['raw']);
    }

    public function testNoOpWhenNoSecretsRegistered(): void
    {
        $proc = new RedactingProcessor();

        $out = ($proc)($this->record('plain message', ['k' => 'v']));

        $this->assertSame('plain message', $out->message);
        $this->assertSame(['k' => 'v'], $out->context);
    }

    public function testHandlesNonStringScalarsWithoutModification(): void
    {
        SecretRegistry::register('abc');
        $proc = new RedactingProcessor();

        $out = ($proc)($this->record('m', ['count' => 42, 'flag' => true, 'pi' => 3.14, 'nil' => null]));

        $this->assertSame(42, $out->context['count']);
        $this->assertTrue($out->context['flag']);
        $this->assertSame(3.14, $out->context['pi']);
        $this->assertNull($out->context['nil']);
    }
}
