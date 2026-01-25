<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\Api\Request;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Request
 */
class ApiRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear superglobals
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
    }

    public function testQueryReturnsAllParametersWhenNoKeyProvided(): void
    {
        $_GET = ['foo' => 'bar', 'baz' => 'qux'];
        
        $result = Request::query();
        
        $this->assertIsArray($result);
        $this->assertEquals(['foo' => 'bar', 'baz' => 'qux'], $result);
    }

    public function testQueryReturnsSpecificParameter(): void
    {
        $_GET = ['key' => 'value'];
        
        $result = Request::query('key');
        
        $this->assertEquals('value', $result);
    }

    public function testQueryReturnsDefaultWhenKeyNotFound(): void
    {
        $_GET = [];
        
        $result = Request::query('missing', 'default');
        
        $this->assertEquals('default', $result);
    }

    public function testPostReturnsAllDataWhenNoKeyProvided(): void
    {
        $_POST = ['name' => 'John', 'age' => '30'];
        
        $result = Request::post();
        
        $this->assertIsArray($result);
        $this->assertEquals(['name' => 'John', 'age' => '30'], $result);
    }

    public function testPostReturnsSpecificValue(): void
    {
        $_POST = ['username' => 'testuser'];
        
        $result = Request::post('username');
        
        $this->assertEquals('testuser', $result);
    }

    public function testPostReturnsDefaultWhenKeyNotFound(): void
    {
        $_POST = [];
        
        $result = Request::post('missing', 'fallback');
        
        $this->assertEquals('fallback', $result);
    }

    public function testPaginationReturnsDefaultValues(): void
    {
        $pagination = Request::pagination();
        
        $this->assertIsArray($pagination);
        $this->assertArrayHasKey('page', $pagination);
        $this->assertArrayHasKey('per_page', $pagination);
        $this->assertEquals(1, $pagination['page']);
        $this->assertEquals(30, $pagination['per_page']);
    }

    public function testPaginationReturnsCustomValues(): void
    {
        $_GET = ['page' => '3', 'per_page' => '50'];
        
        $pagination = Request::pagination();
        
        $this->assertEquals(3, $pagination['page']);
        $this->assertEquals(50, $pagination['per_page']);
    }

    public function testPaginationEnforcesMinimumPage(): void
    {
        $_GET = ['page' => '-1'];
        
        $pagination = Request::pagination();
        
        $this->assertEquals(1, $pagination['page']);
    }

    public function testPaginationEnforcesMaximumPerPage(): void
    {
        $_GET = ['per_page' => '500'];
        
        $pagination = Request::pagination();
        
        $this->assertEquals(100, $pagination['per_page']);
    }

    public function testSearchReturnsSearchParameter(): void
    {
        $_GET = ['search' => 'test query'];
        
        $result = Request::search();
        
        $this->assertEquals('test query', $result);
    }

    public function testSearchReturnsQParameter(): void
    {
        $_GET = ['q' => 'test query'];
        
        $result = Request::search();
        
        $this->assertEquals('test query', $result);
    }

    public function testSearchReturnsNullWhenNotProvided(): void
    {
        $_GET = [];
        
        $result = Request::search();
        
        $this->assertNull($result);
    }

    public function testFiltersReturnsOnlyAllowedParameters(): void
    {
        $_GET = ['status' => 'active', 'type' => 'medical', 'ignored' => 'value'];
        
        $filters = Request::filters(['status', 'type']);
        
        $this->assertIsArray($filters);
        $this->assertArrayHasKey('status', $filters);
        $this->assertArrayHasKey('type', $filters);
        $this->assertArrayNotHasKey('ignored', $filters);
    }

    public function testSortingReturnsDefaultValues(): void
    {
        $_GET = [];
        
        $sorting = Request::sorting();
        
        $this->assertIsArray($sorting);
        $this->assertEquals('id', $sorting['sort']);
        $this->assertEquals('DESC', $sorting['order']);
    }

    public function testSortingReturnsCustomValues(): void
    {
        $_GET = ['sort' => 'name', 'order' => 'asc'];
        
        $sorting = Request::sorting();
        
        $this->assertEquals('name', $sorting['sort']);
        $this->assertEquals('ASC', $sorting['order']);
    }

    public function testSortingNormalizesOrderToUppercase(): void
    {
        $_GET = ['order' => 'AsC'];
        
        $sorting = Request::sorting();
        
        $this->assertEquals('ASC', $sorting['order']);
    }

    public function testSortingDefaultsToDescForInvalidOrder(): void
    {
        $_GET = ['order' => 'invalid'];
        
        $sorting = Request::sorting();
        
        $this->assertEquals('DESC', $sorting['order']);
    }
}
