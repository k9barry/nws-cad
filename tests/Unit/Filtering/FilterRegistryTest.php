<?php
declare(strict_types=1);

namespace NwsCad\Tests\Unit\Filtering;

use NwsCad\Api\Filtering\FilterRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Filtering\FilterRegistry
 */
final class FilterRegistryTest extends TestCase
{
    public function testCallsControllerHasFullFilterSet(): void
    {
        $allowed = FilterRegistry::for('calls');
        $this->assertContains('preset', $allowed);
        $this->assertContains('from', $allowed);
        $this->assertContains('to', $allowed);
        $this->assertContains('date_field', $allowed);
        $this->assertContains('call_type', $allowed);
        $this->assertContains('incident_type', $allowed);
        $this->assertContains('nature_of_call', $allowed);
        $this->assertContains('agency', $allowed);
        $this->assertContains('ori', $allowed);
        $this->assertContains('fdid', $allowed);
        $this->assertContains('beat', $allowed);
        $this->assertContains('area', $allowed);
        $this->assertContains('city', $allowed);
        $this->assertContains('location', $allowed);
        $this->assertContains('call_id', $allowed);
        $this->assertContains('unit', $allowed);
        $this->assertContains('status', $allowed);
        $this->assertContains('q', $allowed);
    }

    public function testUnitsControllerOmitsLocationFilters(): void
    {
        $allowed = FilterRegistry::for('units');
        $this->assertContains('unit', $allowed);
        $this->assertContains('agency', $allowed);
        $this->assertContains('status', $allowed);
        $this->assertNotContains('beat', $allowed); // beat is a location field
    }

    public function testUnknownControllerReturnsEmptyAllowlist(): void
    {
        $this->assertSame([], FilterRegistry::for('does-not-exist'));
    }
}
