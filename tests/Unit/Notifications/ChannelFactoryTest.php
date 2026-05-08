<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use InvalidArgumentException;
use NwsCad\Config;
use NwsCad\Notifications\ChannelFactory;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\Channels\PushoverChannel;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\ChannelFactory
 * @uses \NwsCad\Config
 * @uses \NwsCad\Notifications\Channels\NtfyChannel
 * @uses \NwsCad\Notifications\Channels\PushoverChannel
 * @uses \NwsCad\Logging\SecretRegistry
 */
class ChannelFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['NTFY_AUTH_TOKEN']  = 'ntfy-token';
        $_ENV['PUSHOVER_TOKEN']   = 'pushover-token';
        $_ENV['PUSHOVER_USER']    = 'pushover-user';
    }

    protected function tearDown(): void
    {
        unset($_ENV['NTFY_AUTH_TOKEN'], $_ENV['PUSHOVER_TOKEN'], $_ENV['PUSHOVER_USER']);
        putenv('NTFY_AUTH_TOKEN');
        putenv('PUSHOVER_TOKEN');
        putenv('PUSHOVER_USER');
    }

    public function testCreatesNtfyChannel(): void
    {
        $factory = new ChannelFactory(Config::getInstance());
        $channel = $factory->create([
            'type'        => 'ntfy',
            'base_url'    => 'https://ntfy.example',
            'config_json' => '{"auth_token_env":"NTFY_AUTH_TOKEN"}',
        ]);
        $this->assertInstanceOf(NtfyChannel::class, $channel);
    }

    public function testCreatesPushoverChannel(): void
    {
        $factory = new ChannelFactory(Config::getInstance());
        $channel = $factory->create([
            'type'        => 'pushover',
            'base_url'    => 'https://api.pushover.net',
            'config_json' => '{"token_env":"PUSHOVER_TOKEN","user_env":"PUSHOVER_USER"}',
        ]);
        $this->assertInstanceOf(PushoverChannel::class, $channel);
    }

    public function testThrowsOnUnknownType(): void
    {
        $factory = new ChannelFactory(Config::getInstance());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown channel type: webhook');
        $factory->create([
            'type'        => 'webhook',
            'base_url'    => 'https://x',
            'config_json' => '{}',
        ]);
    }

    public function testHandlesEmptyConfigJson(): void
    {
        $factory = new ChannelFactory(Config::getInstance());
        $channel = $factory->create([
            'type'        => 'ntfy',
            'base_url'    => 'https://ntfy.example',
            'config_json' => '',
        ]);
        $this->assertInstanceOf(NtfyChannel::class, $channel);
    }
}
