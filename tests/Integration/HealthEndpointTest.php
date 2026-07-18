<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Api\Controllers\HealthController;
use NwsCad\Api\Response;
use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Controllers\HealthController
 * @uses \NwsCad\Api\Response
 * @uses \NwsCad\Database
 * @uses \NwsCad\Config
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 */
class HealthEndpointTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        try {
            Database::getConnection();
        } catch (\Exception $e) {
            self::markTestSkipped('Database not available: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Response::resetForTesting();
    }

    public function testReturnsOkWhenDatabaseIsReachable(): void
    {
        $controller = new HealthController();
        ob_start();
        $controller->index();
        $body = (string) ob_get_clean();
        $payload = json_decode($body, true);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['success']);
        $this->assertSame('ok', $payload['data']['status']);
        $this->assertSame('ok', $payload['data']['db']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
            $payload['data']['timestamp']
        );
    }

    public function testSystemReturnsExtendedHealthPayload(): void
    {
        $controller = new HealthController();
        ob_start();
        $controller->system();
        $body = (string) ob_get_clean();
        $payload = json_decode($body, true);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['success']);

        $data = $payload['data'];
        $this->assertContains($data['status'], ['ok', 'warn', 'critical']);

        // App / version block
        $this->assertArrayHasKey('app', $data);
        $this->assertArrayHasKey('version', $data['app']);
        $this->assertSame(PHP_VERSION, $data['app']['php_version']);

        // Database latency
        $this->assertSame('ok', $data['db']['status']);
        $this->assertIsNumeric($data['db']['latency_ms']);

        // Disks is a list; memory is always present
        $this->assertIsArray($data['disks']);
        $this->assertArrayHasKey('php_usage_bytes', $data['memory']);
        $this->assertArrayHasKey('status', $data['memory']);

        // Watcher heartbeat section present with a known status keyword
        $this->assertArrayHasKey('watcher', $data);
        $this->assertContains($data['watcher']['status'], ['ok', 'warn', 'critical', 'unknown']);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
            $data['timestamp']
        );
    }
}
