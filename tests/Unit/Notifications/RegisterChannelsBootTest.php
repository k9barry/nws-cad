<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\ChannelRegistry;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\Channels\PushoverChannel;
use NwsCad\Notifications\Channels\WebhookChannel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[UsesClass(ChannelRegistry::class)]
#[UsesClass(ChannelDescriptor::class)]
#[UsesClass(NtfyChannel::class)]
#[UsesClass(PushoverChannel::class)]
#[UsesClass(WebhookChannel::class)]
final class RegisterChannelsBootTest extends TestCase
{
    protected function setUp(): void
    {
        ChannelRegistry::clear();
    }

    protected function tearDown(): void
    {
        ChannelRegistry::clear();
    }

    public function testIncludePopulatesRegistry(): void
    {
        require __DIR__ . '/../../../src/Notifications/registerChannels.php';

        $this->assertEqualsCanonicalizing(
            ['ntfy', 'pushover', 'webhook'],
            ChannelRegistry::types(),
            'registerChannels.php must register the built-in channel types',
        );
    }
}
