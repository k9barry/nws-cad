<?php
declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Api\Filtering\FilterOptionsCache;
use NwsCad\Api\Response;
use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Controllers\FilterOptionsController
 * @uses \NwsCad\Api\Filtering\FilterOptionsCache
 * @uses \NwsCad\Api\Response
 * @uses \NwsCad\Api\Request
 * @uses \NwsCad\Database
 * @uses \NwsCad\Config
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 */
final class FilterOptionsEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        Response::resetForTesting();
        FilterOptionsCache::clear();
        $this->seedReferenceTables();
    }

    public function testReturnsAgencyOptionsFromRefTable(): void
    {
        $_GET = ['fields' => 'agency'];
        ob_start();
        (new \NwsCad\Api\Controllers\FilterOptionsController())->index();
        $body = json_decode(ob_get_clean(), true);

        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('agency', $body['data']);
        $this->assertNotEmpty($body['data']['agency']);
        $this->assertSame('PEN_PD', $body['data']['agency'][0]['value']);
    }

    public function testReturnsDerivedCityOptionsFromLocations(): void
    {
        $db = Database::getConnection();
        $db->exec("INSERT INTO calls (call_id, call_number, create_datetime) VALUES (99901, 'TEST-1', NOW())");
        $callId = (int)$db->lastInsertId();
        $db->exec("INSERT INTO locations (call_id, full_address, city) VALUES ({$callId}, '1 Main St', 'Pendleton')");

        $_GET = ['fields' => 'city'];
        ob_start();
        (new \NwsCad\Api\Controllers\FilterOptionsController())->index();
        $body = json_decode(ob_get_clean(), true);

        $this->assertContains('Pendleton', $body['data']['city']);
    }

    public function testRejectsUnknownField(): void
    {
        $_GET = ['fields' => 'narnia'];
        ob_start();
        (new \NwsCad\Api\Controllers\FilterOptionsController())->index();
        $body = json_decode(ob_get_clean(), true);
        $this->assertFalse($body['success']);
        $this->assertSame(400, http_response_code());
    }

    private function seedReferenceTables(): void
    {
        $db = Database::getConnection();
        $db->exec("DELETE FROM ref_agencies");
        $db->exec("INSERT INTO ref_agencies (code,label,kind,active,sort_order) VALUES ('PEN_PD','Pendleton Police','police',1,10)");
    }
}
