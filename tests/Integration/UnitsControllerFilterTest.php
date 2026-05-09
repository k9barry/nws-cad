<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Api\Controllers\UnitsController;
use NwsCad\Api\Response;
use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Controllers\UnitsController
 * @uses \NwsCad\Api\Filtering\DateRange
 * @uses \NwsCad\Api\Filtering\FilterContext
 * @uses \NwsCad\Api\Filtering\FilterCriteria
 * @uses \NwsCad\Api\Filtering\FilterRegistry
 * @uses \NwsCad\Api\Filtering\FilterSqlBuilder
 * @uses \NwsCad\Api\Filtering\InvalidFilterException
 * @uses \NwsCad\Api\Filtering\SqlFragment
 * @uses \NwsCad\Api\Response
 * @uses \NwsCad\Api\Request
 * @uses \NwsCad\Api\DbHelper
 * @uses \NwsCad\Database
 * @uses \NwsCad\Config
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 */
final class UnitsControllerFilterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }
        Response::resetForTesting();
        $this->seed();
    }

    public function testFiltersByUnitNumber(): void
    {
        $_GET = ['unit' => '41'];
        $body = $this->callIndex();
        $this->assertCount(1, $body['data']['items']);
        $this->assertSame('41', $body['data']['items'][0]['unit_number']);
    }

    public function testFiltersByAgencyReachingBackThroughUnitsCallId(): void
    {
        $_GET = ['agency' => 'Pendleton Police'];
        $body = $this->callIndex();
        // Two units (41 and 42) belong to the Pendleton Police call
        $this->assertCount(2, $body['data']['items']);
    }

    public function testFiltersByStatusOpenUsingCallsFlags(): void
    {
        $_GET = ['status' => 'open'];
        $body = $this->callIndex();
        // Units 41+42 belong to an open call; unit 43 belongs to a closed call
        $this->assertCount(2, $body['data']['items']);
    }

    public function testFiltersByCallId(): void
    {
        $_GET = ['call_id' => 'P1'];
        $body = $this->callIndex();
        // Two units were assigned to call P1
        $this->assertCount(2, $body['data']['items']);
    }

    public function testReturnsAppliedFiltersInResponse(): void
    {
        $_GET = ['unit' => '41'];
        $body = $this->callIndex();
        $this->assertArrayHasKey('filters', $body['data']);
        $this->assertSame(['41'], $body['data']['filters']['unit']);
    }

    private function callIndex(): array
    {
        ob_start();
        (new UnitsController())->index();
        return json_decode(ob_get_clean(), true);
    }

    private function seed(): void
    {
        $db = Database::getConnection();
        $db->exec('DELETE FROM units');
        $db->exec('DELETE FROM agency_contexts');
        $db->exec('DELETE FROM locations');
        $db->exec('DELETE FROM calls');

        $insert = static function (\PDO $db, string $table, array $cols): int {
            $names = implode(',', array_keys($cols));
            $ph    = ':' . implode(',:', array_keys($cols));
            $db->prepare("INSERT INTO {$table} ({$names}) VALUES ({$ph})")->execute($cols);
            return (int) $db->lastInsertId();
        };

        // Open Pendleton Police call with two units
        $c1 = $insert($db, 'calls', [
            'call_id'       => 801,
            'call_number'   => 'P1',
            'create_datetime' => '2026-05-02 10:00:00',
            'closed_flag'   => 0,
            'canceled_flag' => 0,
        ]);
        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type) VALUES (?, ?, ?)')
            ->execute([$c1, 'Pendleton Police', 'Police']);
        $db->prepare('INSERT INTO locations (call_id, full_address, city) VALUES (?, ?, ?)')
            ->execute([$c1, '1 Main St', 'Pendleton']);
        $insert($db, 'units', ['call_id' => $c1, 'unit_number' => '41', 'unit_type' => 'Patrol', 'assigned_datetime' => '2026-05-02 10:01:00']);
        $insert($db, 'units', ['call_id' => $c1, 'unit_number' => '42', 'unit_type' => 'Patrol', 'assigned_datetime' => '2026-05-02 10:02:00']);

        // Closed EMS call with one unit. status=open filter keys off close_datetime
        // (not closed_flag) since closed_flag is only the raw record of the latest XML.
        $c2 = $insert($db, 'calls', [
            'call_id'       => 802,
            'call_number'   => 'E1',
            'create_datetime' => '2026-05-03 10:00:00',
            'close_datetime' => '2026-05-03 12:00:00',
            'closed_flag'   => 1,
            'canceled_flag' => 0,
        ]);
        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type) VALUES (?, ?, ?)')
            ->execute([$c2, 'Madison EMS', 'EMS']);
        $db->prepare('INSERT INTO locations (call_id, full_address, city) VALUES (?, ?, ?)')
            ->execute([$c2, '5 Oak Ave', 'Madison']);
        $insert($db, 'units', ['call_id' => $c2, 'unit_number' => '43', 'unit_type' => 'Ambulance', 'assigned_datetime' => '2026-05-03 10:01:00']);
    }
}
