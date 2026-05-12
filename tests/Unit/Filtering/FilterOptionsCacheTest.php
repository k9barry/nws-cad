<?php
declare(strict_types=1);

namespace NwsCad\Tests\Unit\Filtering;

use NwsCad\Api\Filtering\FilterOptionsCache;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Filtering\FilterOptionsCache
 */
final class FilterOptionsCacheTest extends TestCase
{
    protected function setUp(): void
    {
        FilterOptionsCache::clear();
    }

    public function testStoresAndRetrievesByKey(): void
    {
        FilterOptionsCache::put('agency', ['Police', 'Fire']);
        $this->assertSame(['Police', 'Fire'], FilterOptionsCache::get('agency'));
    }

    public function testReturnsNullOnMiss(): void
    {
        $this->assertNull(FilterOptionsCache::get('nope'));
    }

    public function testInvalidateRemovesKey(): void
    {
        FilterOptionsCache::put('city', ['Pendleton']);
        FilterOptionsCache::invalidate(['city']);
        $this->assertNull(FilterOptionsCache::get('city'));
    }

    public function testEntriesExpireAfterTtl(): void
    {
        FilterOptionsCache::putAt('agency', ['Police'], time() - 400); // older than 300s
        $this->assertNull(FilterOptionsCache::get('agency'));
    }

    public function testRespectsCustomTtl(): void
    {
        FilterOptionsCache::put('agency', ['Police']);
        // get() within 5 minutes — still hot
        $this->assertSame(['Police'], FilterOptionsCache::get('agency'));
    }
}
