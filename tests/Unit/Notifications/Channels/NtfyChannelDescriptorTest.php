<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Channels;

use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\Channels\NtfyChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NtfyChannel::class)]
#[UsesClass(ChannelDescriptor::class)]
final class NtfyChannelDescriptorTest extends TestCase
{
    public function testDescriptorReportsTypeAndDefaults(): void
    {
        $d = NtfyChannel::descriptor();

        $this->assertInstanceOf(ChannelDescriptor::class, $d);
        $this->assertSame('ntfy', $d->type);
        $this->assertSame('ntfy.sh', $d->label);
        $this->assertSame('NTFY_BASE_URL', $d->baseUrlEnv);
        $this->assertSame(['NTFY_AUTH_TOKEN'], $d->requiredEnvs);
        $this->assertSame(['auth_token_env' => 'NTFY_AUTH_TOKEN'], $d->defaultConfig);
    }
}
