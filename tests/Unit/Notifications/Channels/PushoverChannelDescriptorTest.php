<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Channels;

use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\Channels\PushoverChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushoverChannel::class)]
#[UsesClass(ChannelDescriptor::class)]
final class PushoverChannelDescriptorTest extends TestCase
{
    public function testDescriptorReportsTypeAndDefaults(): void
    {
        $d = PushoverChannel::descriptor();

        $this->assertInstanceOf(ChannelDescriptor::class, $d);
        $this->assertSame('pushover', $d->type);
        $this->assertSame('Pushover', $d->label);
        $this->assertSame('PUSHOVER_BASE_URL', $d->baseUrlEnv);
        $this->assertSame(['PUSHOVER_TOKEN', 'PUSHOVER_USER'], $d->requiredEnvs);
        $this->assertSame(
            ['token_env' => 'PUSHOVER_TOKEN', 'user_env' => 'PUSHOVER_USER'],
            $d->defaultConfig,
        );
    }
}
