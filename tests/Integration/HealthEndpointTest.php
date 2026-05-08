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
}
