<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Api\Controllers\CallsController;
use NwsCad\Api\Response;
use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Controllers\CallsController
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
final class CallsControllerFilterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }
        Response::resetForTesting();
        $this->seed();
    }

    public function testFiltersByCallType(): void
    {
        $_GET = ['call_type' => 'Police'];
        $body = $this->callIndex();
        $this->assertCount(2, $body['data']['items']);
    }

    public function testFiltersByOri(): void
    {
        $_GET = ['ori' => 'IN0480000'];
        $body = $this->callIndex();
        $this->assertCount(1, $body['data']['items']);
    }

    public function testFiltersByFdid(): void
    {
        $_GET = ['fdid' => '48013'];
        $body = $this->callIndex();
        $this->assertCount(1, $body['data']['items']);
    }

    public function testFiltersByStatusOpen(): void
    {
        $_GET = ['status' => 'open'];
        $body = $this->callIndex();
        // Among seeded rows, 2 are open (closed_flag=0 AND canceled_flag=0)
        $this->assertCount(2, $body['data']['items']);
    }

    public function testFiltersByDateRange(): void
    {
        $_GET = ['from' => '2026-05-01', 'to' => '2026-05-08'];
        $body = $this->callIndex();
        $this->assertGreaterThan(0, count($body['data']['items']));
    }

    public function testReturnsAppliedFiltersInResponse(): void
    {
        $_GET = ['call_type' => 'Police'];
        $body = $this->callIndex();
        $this->assertArrayHasKey('filters', $body['data']);
        $this->assertSame(['Police'], $body['data']['filters']['call_type']);
    }

    public function testReturns400OnInvalidStatus(): void
    {
        $_GET = ['status' => 'banana'];
        $body = $this->callIndex();
        $this->assertFalse($body['success']);
    }

    private function callIndex(): array
    {
        ob_start();
        (new CallsController())->index();
        return json_decode(ob_get_clean(), true);
    }

    private function seed(): void
    {
        $db = Database::getConnection();
        $db->exec('DELETE FROM agency_contexts');
        $db->exec('DELETE FROM locations');
        $db->exec('DELETE FROM calls');

        // Two Police calls (one open, one closed), one Fire call (canceled), one EMS call (open)
        $insert = static function (\PDO $db, array $cols): int {
            $names = implode(',', array_keys($cols));
            $ph    = ':' . implode(',:', array_keys($cols));
            $db->prepare("INSERT INTO calls ({$names}) VALUES ({$ph})")->execute($cols);
            return (int) $db->lastInsertId();
        };

        $c1 = $insert($db, ['call_id' => 901, 'call_number' => 'P1', 'create_datetime' => '2026-05-02 10:00:00', 'closed_flag' => 0, 'canceled_flag' => 0]);
        $c2 = $insert($db, ['call_id' => 902, 'call_number' => 'P2', 'create_datetime' => '2026-05-03 10:00:00', 'closed_flag' => 1, 'canceled_flag' => 0]);
        $c3 = $insert($db, ['call_id' => 903, 'call_number' => 'F1', 'create_datetime' => '2026-05-04 10:00:00', 'closed_flag' => 0, 'canceled_flag' => 1]);
        $c4 = $insert($db, ['call_id' => 904, 'call_number' => 'E1', 'create_datetime' => '2026-05-05 10:00:00', 'closed_flag' => 0, 'canceled_flag' => 0]);

        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type, fdid) VALUES (?, ?, ?, ?)')
            ->execute([$c1, 'Pendleton Police', 'Police', null]);
        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type, fdid) VALUES (?, ?, ?, ?)')
            ->execute([$c2, 'Pendleton Police', 'Police', null]);
        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type, fdid) VALUES (?, ?, ?, ?)')
            ->execute([$c3, 'Edgewood Fire', 'Fire', '48013']);
        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type, fdid) VALUES (?, ?, ?, ?)')
            ->execute([$c4, 'Madison EMS', 'EMS', null]);

        $db->prepare('INSERT INTO locations (call_id, full_address, city, police_ori) VALUES (?, ?, ?, ?)')
            ->execute([$c1, '1 Main', 'Pendleton', 'IN0480000']);
        $db->prepare('INSERT INTO locations (call_id, full_address, city, police_ori) VALUES (?, ?, ?, ?)')
            ->execute([$c2, '2 Main', 'Pendleton', 'IN0480200']);
        $db->prepare('INSERT INTO locations (call_id, full_address, city) VALUES (?, ?, ?)')
            ->execute([$c3, '3 Main', 'Edgewood']);
        $db->prepare('INSERT INTO locations (call_id, full_address, city) VALUES (?, ?, ?)')
            ->execute([$c4, '4 Main', 'Madison']);
    }
}
