<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\ChannelRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelRegistry::class)]
#[UsesClass(ChannelDescriptor::class)]
final class ChannelRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        ChannelRegistry::clear();
    }

    protected function tearDown(): void
    {
        ChannelRegistry::clear();
    }

    private function descriptor(string $type): ChannelDescriptor
    {
        return new ChannelDescriptor(
            type: $type, label: ucfirst($type),
            baseUrlEnv: strtoupper($type) . '_BASE_URL',
            requiredEnvs: [], defaultConfig: [],
            factory: static fn (array $r, $c) => new \stdClass(),
        );
    }

    public function testEmptyRegistry(): void
    {
        $this->assertSame([], ChannelRegistry::types());
        $this->assertSame([], ChannelRegistry::all());
        $this->assertFalse(ChannelRegistry::has('ntfy'));
        $this->assertNull(ChannelRegistry::get('ntfy'));
    }

    public function testRegisterAndRetrieve(): void
    {
        $d = $this->descriptor('demo');
        ChannelRegistry::register($d);

        $this->assertTrue(ChannelRegistry::has('demo'));
        $this->assertSame($d, ChannelRegistry::get('demo'));
        $this->assertSame(['demo'], ChannelRegistry::types());
        $this->assertSame(['demo' => $d], ChannelRegistry::all());
    }

    public function testDuplicateTypeOverwrites(): void
    {
        $first  = $this->descriptor('demo');
        $second = $this->descriptor('demo');
        ChannelRegistry::register($first);
        ChannelRegistry::register($second);

        $this->assertSame($second, ChannelRegistry::get('demo'));
        $this->assertCount(1, ChannelRegistry::all());
    }

    public function testClearWipesState(): void
    {
        ChannelRegistry::register($this->descriptor('a'));
        ChannelRegistry::register($this->descriptor('b'));
        ChannelRegistry::clear();

        $this->assertSame([], ChannelRegistry::types());
    }
}
