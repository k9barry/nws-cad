<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use Closure;
use NwsCad\Notifications\ChannelDescriptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelDescriptor::class)]
final class ChannelDescriptorTest extends TestCase
{
    public function testReadonlyPropertiesExposeConstructorArgs(): void
    {
        $factory = static fn (array $r, $cfg) => new \stdClass();

        $d = new ChannelDescriptor(
            type:          'demo',
            label:         'Demo Channel',
            baseUrlEnv:    'DEMO_BASE_URL',
            requiredEnvs:  ['DEMO_TOKEN'],
            defaultConfig: ['key' => 'value'],
            factory:       $factory,
        );

        $this->assertSame('demo', $d->type);
        $this->assertSame('Demo Channel', $d->label);
        $this->assertSame('DEMO_BASE_URL', $d->baseUrlEnv);
        $this->assertSame(['DEMO_TOKEN'], $d->requiredEnvs);
        $this->assertSame(['key' => 'value'], $d->defaultConfig);
        $this->assertInstanceOf(Closure::class, $d->factory);
    }

    public function testFactoryClosureIsInvokable(): void
    {
        $marker = new \stdClass();
        $factory = static fn (array $row, $cfg): object => $marker;

        $d = new ChannelDescriptor(
            type: 't', label: 'l', baseUrlEnv: 'E',
            requiredEnvs: [], defaultConfig: [],
            factory: $factory,
        );

        $result = ($d->factory)(['type' => 't'], null);
        $this->assertSame($marker, $result);
    }
}
