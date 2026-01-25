<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\Api\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Response
 */
class ApiResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Prevent actual output during tests
        ob_start();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean output buffer
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    public function testJsonResponseFormatsCorrectly(): void
    {
        $data = ['key' => 'value', 'number' => 123];
        
        try {
            Response::json($data, 200);
        } catch (\Exception $e) {
            // exit() is called, which we can't test directly
        }
        
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        
        $this->assertIsArray($decoded);
        $this->assertEquals('value', $decoded['key']);
        $this->assertEquals(123, $decoded['number']);
    }

    public function testSuccessResponseStructure(): void
    {
        $data = ['id' => 1, 'name' => 'Test'];
        
        try {
            Response::success($data);
        } catch (\Exception $e) {
            // exit() is called
        }
        
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('success', $decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertTrue($decoded['success']);
        $this->assertEquals($data, $decoded['data']);
    }

    public function testSuccessResponseWithMessage(): void
    {
        $data = ['id' => 1];
        $message = 'Operation successful';
        
        try {
            Response::success($data, $message);
        } catch (\Exception $e) {
            // exit() is called
        }
        
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        
        $this->assertArrayHasKey('message', $decoded);
        $this->assertEquals($message, $decoded['message']);
    }

    public function testErrorResponseStructure(): void
    {
        $errorMessage = 'An error occurred';
        
        try {
            Response::error($errorMessage, 400);
        } catch (\Exception $e) {
            // exit() is called
        }
        
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('success', $decoded);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertFalse($decoded['success']);
        $this->assertEquals($errorMessage, $decoded['error']);
    }

    public function testErrorResponseWithErrors(): void
    {
        $errorMessage = 'Validation failed';
        $errors = ['field1' => 'Required', 'field2' => 'Invalid'];
        
        try {
            Response::error($errorMessage, 422, $errors);
        } catch (\Exception $e) {
            // exit() is called
        }
        
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        
        $this->assertArrayHasKey('errors', $decoded);
        $this->assertEquals($errors, $decoded['errors']);
    }

    public function testNotFoundResponseStructure(): void
    {
        try {
            Response::notFound();
        } catch (\Exception $e) {
            // exit() is called
        }
        
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('success', $decoded);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertFalse($decoded['success']);
        $this->assertEquals('Resource not found', $decoded['error']);
    }

    public function testPaginatedResponseStructure(): void
    {
        $items = [['id' => 1], ['id' => 2]];
        $total = 50;
        $page = 2;
        $perPage = 10;
        
        try {
            Response::paginated($items, $total, $page, $perPage);
        } catch (\Exception $e) {
            // exit() is called
        }
        
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        
        $this->assertArrayHasKey('success', $decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('items', $decoded['data']);
        $this->assertArrayHasKey('pagination', $decoded['data']);
        
        $pagination = $decoded['data']['pagination'];
        $this->assertEquals($total, $pagination['total']);
        $this->assertEquals($page, $pagination['current_page']);
        $this->assertEquals($perPage, $pagination['per_page']);
        $this->assertEquals(5, $pagination['total_pages']);
        $this->assertTrue($pagination['has_more']);
    }

    public function testPaginatedResponseLastPage(): void
    {
        $items = [['id' => 1]];
        $total = 10;
        $page = 1;
        $perPage = 10;
        
        try {
            Response::paginated($items, $total, $page, $perPage);
        } catch (\Exception $e) {
            // exit() is called
        }
        
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        
        $pagination = $decoded['data']['pagination'];
        $this->assertFalse($pagination['has_more']);
    }
}
