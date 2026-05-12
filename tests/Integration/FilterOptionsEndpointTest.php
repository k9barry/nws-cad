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
        $this->cleanData();
    }

    public function testReturnsAgencyOptionsDerivedFromAgencyContexts(): void
    {
        $db = Database::getConnection();
        $db->exec("INSERT INTO calls (call_id, call_number, create_datetime) VALUES (99910, 'AG-T1', NOW())");
        $callId = (int) $db->lastInsertId();
        $db->exec("INSERT INTO agency_contexts (call_id, agency_type, call_type) VALUES ({$callId}, 'Police', 'Test')");
        $db->exec("INSERT INTO agency_contexts (call_id, agency_type, call_type) VALUES ({$callId}, 'Fire',   'Test')");

        $_GET = ['fields' => 'agency'];
        ob_start();
        (new \NwsCad\Api\Controllers\FilterOptionsController())->index();
        $body = json_decode(ob_get_clean(), true);

        $this->assertTrue($body['success']);
        $this->assertContains('Police', $body['data']['agency']);
        $this->assertContains('Fire',   $body['data']['agency']);
    }

    public function testReturnsOriOptionsUnionedAcrossThreeColumns(): void
    {
        $db = Database::getConnection();
        $db->exec("INSERT INTO calls (call_id, call_number, create_datetime) VALUES (99920, 'ORI-T1', NOW())");
        $callId = (int) $db->lastInsertId();
        $db->exec("INSERT INTO locations (call_id, full_address, police_ori, ems_ori, fire_ori) VALUES ({$callId}, '1 Main', 'IN0480000', '480051', '48002')");

        $_GET = ['fields' => 'ori'];
        ob_start();
        (new \NwsCad\Api\Controllers\FilterOptionsController())->index();
        $body = json_decode(ob_get_clean(), true);

        $this->assertContains('IN0480000', $body['data']['ori']);
        $this->assertContains('480051',    $body['data']['ori']);
        $this->assertContains('48002',     $body['data']['ori']);
    }

    public function testReturnsDerivedCityOptionsFromLocations(): void
    {
        $db = Database::getConnection();
        $db->exec("INSERT INTO calls (call_id, call_number, create_datetime) VALUES (99901, 'CITY-T1', NOW())");
        $callId = (int) $db->lastInsertId();
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

    private function cleanData(): void
    {
        $db = Database::getConnection();
        $db->exec("DELETE FROM agency_contexts");
        $db->exec("DELETE FROM locations");
        $db->exec("DELETE FROM calls WHERE call_number LIKE 'AG-T%' OR call_number LIKE 'ORI-T%' OR call_number LIKE 'CITY-T%'");
    }
}
