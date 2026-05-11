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
        // Open under the new semantics: canceled_flag=0 AND (close_datetime IS NULL OR reopened_flag=1).
        // Seeded rows include c1 (open), c4 (open), and c5 (reopened) — 3 total.
        // c2 (closed with timestamp) and c3 (canceled) are excluded.
        $this->assertCount(3, $body['data']['items']);
    }

    public function testFiltersByStatusClosed(): void
    {
        $_GET = ['status' => 'closed'];
        $body = $this->callIndex();
        // Closed: canceled_flag=0 AND close_datetime IS NOT NULL AND reopened_flag=0.
        // Only c2 qualifies; c5 has close_datetime but reopened_flag=1.
        $this->assertCount(1, $body['data']['items']);
        $this->assertSame('P2', $body['data']['items'][0]['call_number']);
    }

    public function testFiltersByStatusReopened(): void
    {
        $_GET = ['status' => 'reopened'];
        $body = $this->callIndex();
        $this->assertCount(1, $body['data']['items']);
        $this->assertSame('R1', $body['data']['items'][0]['call_number']);
    }

    public function testFiltersByStatusCanceled(): void
    {
        $_GET = ['status' => 'canceled'];
        $body = $this->callIndex();
        $this->assertCount(1, $body['data']['items']);
        $this->assertSame('F1', $body['data']['items'][0]['call_number']);
    }

    public function testStaleOpenCallSurfacesUnderClosedFilterWithIsStale(): void
    {
        // Plant one extra row that's older than the 72h guardrail and never
        // got a close_datetime. The server-side filter should put it in
        // status=closed and the response should carry is_stale=true so the
        // client-side badge can render correctly.
        $db = Database::getConnection();
        $db->prepare("INSERT INTO calls (call_id, call_number, create_datetime, closed_flag, canceled_flag, reopened_flag, close_datetime)
                     VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([999, 'STALE1', (new \DateTimeImmutable('-100 hours'))->format('Y-m-d H:i:s'),
                       0, 0, 0, null]);

        $_GET = ['status' => 'closed'];
        $body = $this->callIndex();
        $callNumbers = array_column($body['data']['items'], 'call_number');
        $this->assertContains('STALE1', $callNumbers, 'stale-open row must surface under status=closed');

        $stale = current(array_filter(
            $body['data']['items'],
            fn($r) => $r['call_number'] === 'STALE1'
        ));
        $this->assertTrue($stale['is_stale'], 'stale row must carry is_stale=true so the JS badge demotes it');

        $real = current(array_filter(
            $body['data']['items'],
            fn($r) => $r['call_number'] === 'P2'
        ));
        $this->assertFalse($real['is_stale'], 'legitimately-closed row must NOT be marked is_stale');
    }

    public function testIsStaleFalseForFreshOpenCall(): void
    {
        $_GET = ['call_id' => 'P1']; // c1 — fresh open call (2h old)
        $body = $this->callIndex();
        $this->assertCount(1, $body['data']['items']);
        $this->assertFalse(
            $body['data']['items'][0]['is_stale'],
            'fresh open call (within 72h) must have is_stale=false'
        );
    }

    public function testFiltersByDateRange(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $_GET = ['from' => $yesterday, 'to' => $today];
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

        // Two Police calls (one open, one closed), one Fire call (canceled), one EMS call (open).
        // Timestamps are NOW-relative so the 72h stale-open guardrail in
        // FilterSqlBuilder doesn't reclassify "open"-seeded rows as stale-closed
        // when this test suite is run far from any single calendar date.
        $insert = static function (\PDO $db, array $cols): int {
            $names = implode(',', array_keys($cols));
            $ph    = ':' . implode(',:', array_keys($cols));
            $db->prepare("INSERT INTO calls ({$names}) VALUES ({$ph})")->execute($cols);
            return (int) $db->lastInsertId();
        };

        $t = static fn(string $rel): string =>
            (new \DateTimeImmutable($rel))->format('Y-m-d H:i:s');

        // c1: open  (no close_datetime,        2h old — well within 72h window)
        // c2: closed (close_datetime set,      2h old, reopened_flag = 0)
        // c3: canceled                          (2h old)
        // c4: open  (no close_datetime,        3h old)
        // c5: reopened (close_datetime set AND reopened_flag = 1, 3h old) — counts as "open" too
        $c1 = $insert($db, ['call_id' => 901, 'call_number' => 'P1', 'create_datetime' => $t('-2 hours'),  'closed_flag' => 0, 'canceled_flag' => 0, 'reopened_flag' => 0, 'close_datetime' => null]);
        $c2 = $insert($db, ['call_id' => 902, 'call_number' => 'P2', 'create_datetime' => $t('-2 hours'),  'closed_flag' => 1, 'canceled_flag' => 0, 'reopened_flag' => 0, 'close_datetime' => $t('-1 hour')]);
        $c3 = $insert($db, ['call_id' => 903, 'call_number' => 'F1', 'create_datetime' => $t('-2 hours'),  'closed_flag' => 0, 'canceled_flag' => 1, 'reopened_flag' => 0, 'close_datetime' => null]);
        $c4 = $insert($db, ['call_id' => 904, 'call_number' => 'E1', 'create_datetime' => $t('-3 hours'),  'closed_flag' => 0, 'canceled_flag' => 0, 'reopened_flag' => 0, 'close_datetime' => null]);
        $c5 = $insert($db, ['call_id' => 905, 'call_number' => 'R1', 'create_datetime' => $t('-3 hours'),  'closed_flag' => 0, 'canceled_flag' => 0, 'reopened_flag' => 1, 'close_datetime' => $t('-2 hours')]);

        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type, fdid) VALUES (?, ?, ?, ?)')
            ->execute([$c1, 'Pendleton Police', 'Police', null]);
        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type, fdid) VALUES (?, ?, ?, ?)')
            ->execute([$c2, 'Pendleton Police', 'Police', null]);
        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type, fdid) VALUES (?, ?, ?, ?)')
            ->execute([$c3, 'Edgewood Fire', 'Fire', '48013']);
        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type, fdid) VALUES (?, ?, ?, ?)')
            ->execute([$c4, 'Madison EMS', 'EMS', null]);
        $db->prepare('INSERT INTO agency_contexts (call_id, agency_type, call_type, fdid) VALUES (?, ?, ?, ?)')
            ->execute([$c5, 'Madison EMS', 'EMS', null]);

        $db->prepare('INSERT INTO locations (call_id, full_address, city, police_ori) VALUES (?, ?, ?, ?)')
            ->execute([$c1, '1 Main', 'Pendleton', 'IN0480000']);
        $db->prepare('INSERT INTO locations (call_id, full_address, city, police_ori) VALUES (?, ?, ?, ?)')
            ->execute([$c2, '2 Main', 'Pendleton', 'IN0480200']);
        $db->prepare('INSERT INTO locations (call_id, full_address, city) VALUES (?, ?, ?)')
            ->execute([$c3, '3 Main', 'Edgewood']);
        $db->prepare('INSERT INTO locations (call_id, full_address, city) VALUES (?, ?, ?)')
            ->execute([$c4, '4 Main', 'Madison']);
        $db->prepare('INSERT INTO locations (call_id, full_address, city) VALUES (?, ?, ?)')
            ->execute([$c5, '5 Main', 'Madison']);
    }
}
