<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IntentResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\IntentResolver
 */
class IntentResolverTest extends TestCase
{
    public function testNoExistingRowProducesCreated(): void
    {
        [$intent, $changed] = IntentResolver::resolve(
            existing: null,
            incoming: $this->incoming(['closed_flag' => false]),
        );
        $this->assertSame(Intent::Created, $intent);
        $this->assertSame([], $changed);
    }

    public function testClosedFlagTrueProducesClosed(): void
    {
        [$intent, $changed] = IntentResolver::resolve(
            existing: $this->existing(),
            incoming: $this->incoming(['closed_flag' => true]),
        );
        $this->assertSame(Intent::Closed, $intent);
        $this->assertSame([], $changed);
    }

    public function testCallTypeChangeProducesUpdatedWithField(): void
    {
        [$intent, $changed] = IntentResolver::resolve(
            existing: $this->existing(['call_type' => 'Medical']),
            incoming: $this->incoming(['call_type' => 'Structure Fire']),
        );
        $this->assertSame(Intent::Updated, $intent);
        $this->assertSame(['call_type'], $changed);
    }

    public function testNewUnitProducesUpdatedWithUnitsField(): void
    {
        [$intent, $changed] = IntentResolver::resolve(
            existing: $this->existing(['units' => 'ENGINE1']),
            incoming: $this->incoming(['units' => 'ENGINE1|TRUCK1']),
        );
        $this->assertSame(Intent::Updated, $intent);
        $this->assertContains('assigned_units', $changed);
    }

    public function testNoMaterialChangeReturnsNull(): void
    {
        [$intent, $changed] = IntentResolver::resolve(
            existing: $this->existing(),
            incoming: $this->incoming(),
        );
        $this->assertNull($intent);
        $this->assertSame([], $changed);
    }

    /** @return array<string,mixed> */
    private function existing(array $overrides = []): array
    {
        return array_merge([
            'call_type' => 'Fire',
            'full_address' => '123 Main',
            'alarm_level' => 1,
            'units' => 'ENGINE1',
            'jurisdictions' => 'MCFD',
            'agencies' => 'Fire',
        ], $overrides);
    }

    private function incoming(array $overrides = []): array
    {
        return array_merge([
            'call_type' => 'Fire',
            'full_address' => '123 Main',
            'alarm_level' => 1,
            'units' => 'ENGINE1',
            'jurisdictions' => 'MCFD',
            'agencies' => 'Fire',
            'closed_flag' => false,
        ], $overrides);
    }
}
