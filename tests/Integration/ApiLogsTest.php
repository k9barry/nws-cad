<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Config;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Logs API endpoints
 * @covers \NwsCad\Api\Controllers\LogsController
 */
class ApiLogsTest extends TestCase
{
    private string $testLogPath;
    private string $testLogFile;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Enable logs for testing
        putenv('APP_LOGS_ENABLED=true');
        putenv('APP_ENV=testing');
        
        $config = Config::getInstance();
        $this->testLogPath = $config->get('paths.logs');
        
        // Ensure logs directory exists
        if (!is_dir($this->testLogPath)) {
            mkdir($this->testLogPath, 0755, true);
        }
        
        // Create a test log file
        $this->testLogFile = 'test-' . time() . '.log';
        $this->createTestLogFile();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up test log file
        $filePath = $this->testLogPath . '/' . $this->testLogFile;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    private function createTestLogFile(): void
    {
        $filePath = $this->testLogPath . '/' . $this->testLogFile;
        $content = <<<EOL
[2024-01-26 10:00:00] nws-cad.DEBUG: Test debug message {}
[2024-01-26 10:01:00] nws-cad.INFO: Test info message {}
[2024-01-26 10:02:00] nws-cad.WARNING: Test warning message {}
[2024-01-26 10:03:00] nws-cad.ERROR: Test error message {}
[2024-01-26 10:04:00] nws-cad.CRITICAL: Test critical message {}
EOL;
        file_put_contents($filePath, $content);
    }

    public function testCanListLogFiles(): void
    {
        $controller = new \NwsCad\Api\Controllers\LogsController();
        
        ob_start();
        $controller->index();
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('files', $data['data']);
        $this->assertIsArray($data['data']['files']);
    }

    public function testCanViewLogFile(): void
    {
        $_GET['page'] = '1';
        $_GET['per_page'] = '10';
        
        $controller = new \NwsCad\Api\Controllers\LogsController();
        
        ob_start();
        $controller->show($this->testLogFile);
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);
        $this->assertIsArray($data['data']['items']);
        $this->assertGreaterThan(0, count($data['data']['items']));
    }

    public function testCanFilterLogsByLevel(): void
    {
        $_GET['page'] = '1';
        $_GET['per_page'] = '10';
        $_GET['level'] = 'ERROR';
        
        $controller = new \NwsCad\Api\Controllers\LogsController();
        
        ob_start();
        $controller->show($this->testLogFile);
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);
        
        // Check that filtered results contain ERROR level
        foreach ($data['data']['items'] as $item) {
            $this->assertStringContainsStringIgnoringCase('ERROR', $item['raw']);
        }
    }

    public function testRejectsDirectoryTraversal(): void
    {
        $controller = new \NwsCad\Api\Controllers\LogsController();
        
        ob_start();
        $controller->show('../etc/passwd');
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertFalse($data['success']);
        $this->assertEquals(400, http_response_code());
    }

    public function testCanGetRecentLogs(): void
    {
        // Create app.log for recent logs test
        $appLogPath = $this->testLogPath . '/app.log';
        file_put_contents($appLogPath, "[2024-01-26 12:00:00] nws-cad.INFO: Recent log entry {}\n");
        
        $_GET['lines'] = '10';
        
        $controller = new \NwsCad\Api\Controllers\LogsController();
        
        ob_start();
        $controller->recent();
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('entries', $data['data']);
        $this->assertIsArray($data['data']['entries']);
        
        // Clean up
        if (file_exists($appLogPath)) {
            unlink($appLogPath);
        }
    }

    public function testReturnsNotFoundForNonexistentFile(): void
    {
        $_GET['page'] = '1';
        $_GET['per_page'] = '10';
        
        $controller = new \NwsCad\Api\Controllers\LogsController();
        
        ob_start();
        $controller->show('nonexistent-file.log');
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertFalse($data['success']);
    }

    public function testDeniesAccessWhenDisabled(): void
    {
        // Disable logs
        putenv('APP_LOGS_ENABLED=false');
        putenv('APP_ENV=production');
        
        // Force config reload
        $reflection = new \ReflectionClass(Config::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
        
        $controller = new \NwsCad\Api\Controllers\LogsController();
        
        ob_start();
        $controller->index();
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertFalse($data['success']);
        
        // Reset for other tests
        putenv('APP_LOGS_ENABLED=true');
        putenv('APP_ENV=testing');
        $instanceProperty->setValue(null, null);
    }
}
