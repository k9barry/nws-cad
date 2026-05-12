<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use InvalidArgumentException;
use NwsCad\Config;
use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\ChannelFactory;
use NwsCad\Notifications\ChannelRegistry;
use NwsCad\Notifications\NotificationChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelFactory::class)]
#[UsesClass(ChannelRegistry::class)]
#[UsesClass(ChannelDescriptor::class)]
#[UsesClass(Config::class)]
final class ChannelFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        ChannelRegistry::clear();
    }

    protected function tearDown(): void
    {
        ChannelRegistry::clear();
    }

    public function testUnknownTypeThrows(): void
    {
        $factory = new ChannelFactory(Config::getInstance());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown channel type: bogus');

        $factory->create(['type' => 'bogus', 'base_url' => '', 'config_json' => '']);
    }

    public function testRegistryDispatchInvokesDescriptorFactory(): void
    {
        $stub = $this->createStub(NotificationChannel::class);

        ChannelRegistry::register(new ChannelDescriptor(
            type: 'demo', label: 'Demo', baseUrlEnv: 'DEMO_URL',
            requiredEnvs: [], defaultConfig: [],
            factory: static fn (array $row, Config $cfg): NotificationChannel => $stub,
        ));

        $factory = new ChannelFactory(Config::getInstance());
        $result  = $factory->create([
            'type'        => 'demo',
            'base_url'    => 'http://example.test',
            'config_json' => '{}',
        ]);

        $this->assertSame($stub, $result);
    }
}
