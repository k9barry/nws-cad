<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\Api\Router;
use NwsCad\Api\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Router
 */
class ApiRouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router('/api');
    }

    public function testRouterCanBeInstantiated(): void
    {
        $this->assertInstanceOf(Router::class, $this->router);
    }

    public function testCanRegisterGetRoute(): void
    {
        $called = false;
        
        $this->router->get('/test', function() use (&$called) {
            $called = true;
        });
        
        $this->assertTrue(true, 'Route registration should not throw exception');
    }

    public function testCanRegisterPostRoute(): void
    {
        $called = false;
        
        $this->router->post('/test', function() use (&$called) {
            $called = true;
        });
        
        $this->assertTrue(true, 'Route registration should not throw exception');
    }

    public function testCanRegisterPutRoute(): void
    {
        $this->router->put('/test', function() {});
        $this->assertTrue(true, 'Route registration should not throw exception');
    }

    public function testCanRegisterDeleteRoute(): void
    {
        $this->router->delete('/test', function() {});
        $this->assertTrue(true, 'Route registration should not throw exception');
    }

    public function testGetMethodReturnsString(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $method = Router::getMethod();
        
        $this->assertIsString($method);
        $this->assertEquals('GET', $method);
    }

    public function testGetUriReturnsString(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/test';
        $uri = Router::getUri();
        
        $this->assertIsString($uri);
        $this->assertEquals('/api/test', $uri);
    }

    public function testRouterWithDifferentBasePath(): void
    {
        $router = new Router('/v1/api');
        $this->assertInstanceOf(Router::class, $router);
    }

    public function testRouterWithEmptyBasePath(): void
    {
        $router = new Router('');
        $this->assertInstanceOf(Router::class, $router);
    }

    public function testRouterWithTrailingSlash(): void
    {
        $router = new Router('/api/');
        $this->assertInstanceOf(Router::class, $router);
    }

    public function testRouteWithParameters(): void
    {
        $this->router->get('/calls/{id}', function($params) {
            $this->assertArrayHasKey('id', $params);
        });
        
        $this->assertTrue(true, 'Parameterized route registration should not throw exception');
    }

    public function testRouteWithMultipleParameters(): void
    {
        $this->router->get('/calls/{id}/units/{unitId}', function($params) {
            $this->assertArrayHasKey('id', $params);
            $this->assertArrayHasKey('unitId', $params);
        });
        
        $this->assertTrue(true, 'Multi-parameter route registration should not throw exception');
    }
}
