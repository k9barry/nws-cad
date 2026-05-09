<?php

declare(strict_types=1);

namespace NwsCad\Tests\Performance;

use NwsCad\Api\Controllers\CallsController;
use NwsCad\Api\Response;
use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * Performance tests: verify filter queries against 100k call rows complete
 * under the latency threshold. A failure here means an index from the
 * 2026-05-08-filter-refactor migration is absent or not being used.
 *
 * @coversNothing
 */
final class FilterPerformanceTest extends TestCase
{
    private const ROW_COUNT = 100_000;
    // 200ms accommodates Docker/CI timing variance under full-suite load.
    // Targeted runs (composer test:performance) typically complete in 1–80ms.
    private const MAX_MS    = 200;

    /**
     * Seed 100k rows once for the entire class. Skips re-seeding when the
     * table already holds >= ROW_COUNT rows (e.g. a second run in the same
     * container session).
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $db = Database::getConnection();

        $count = (int) $db->query('SELECT COUNT(*) FROM calls')->fetchColumn();
        if ($count >= self::ROW_COUNT) {
            // Repair pre-existing seed rows that may pre-date the close_datetime
            // population added when the open/closed filter switched to keying off
            // close_datetime (see 2026-05-09-call-status-correctness spec).
            $db->exec(
                "UPDATE calls
                    SET close_datetime = DATE_ADD(create_datetime, INTERVAL 30 MINUTE)
                  WHERE closed_flag = 1 AND close_datetime IS NULL"
            );
            return;
        }

        // Insert remaining rows in a single transaction for speed.
        $toInsert = self::ROW_COUNT - $count;
        $start    = strtotime('2024-01-01 00:00:00') + ($count * 60);

        // Find the highest existing call_id to avoid UNIQUE constraint violations.
        $maxCallId = (int) $db->query('SELECT COALESCE(MAX(call_id), 0) FROM calls')->fetchColumn();

        $db->beginTransaction();
        $stmt = $db->prepare(
            'INSERT INTO calls (call_id, call_number, create_datetime, closed_flag, canceled_flag, close_datetime)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        for ($i = 0; $i < $toInsert; $i++) {
            $createTs = $start + ($i * 60);
            $isClosed = $i % 4 === 0;
            // close_datetime tracks closed_flag — closed rows get a timestamp 30 min after create
            $closeTs = $isClosed ? date('Y-m-d H:i:s', $createTs + 1800) : null;
            $stmt->execute([
                $maxCallId + $count + $i + 1,
                'PERF-' . ($count + $i),
                date('Y-m-d H:i:s', $createTs),
                $isClosed ? 1 : 0,
                $i % 50 === 0 ? 1 : 0,
                $closeTs,
            ]);
        }
        $db->commit();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Reset between tests so each controller invocation starts fresh.
        Response::resetForTesting();
        // Clear superglobals from previous test.
        $_GET = [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Invoke CallsController::index() with the given GET params and return the
     * elapsed milliseconds. Output is captured and discarded.
     *
     * @param array<string, string> $params
     */
    private function timeFilter(array $params): float
    {
        $_GET = $params;
        $t0   = microtime(true);
        ob_start();
        (new CallsController())->index();
        ob_end_clean();
        return (microtime(true) - $t0) * 1000.0;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testCallTypeFilterCompletesUnderThreshold(): void
    {
        $elapsed = $this->timeFilter(['call_type' => 'Police']);
        $this->assertLessThan(
            self::MAX_MS,
            $elapsed,
            sprintf('call_type filter took %.1fms (threshold %dms) — check idx_ac_call_type', $elapsed, self::MAX_MS)
        );
    }

    public function testDateRangeFilterCompletesUnderThreshold(): void
    {
        $elapsed = $this->timeFilter(['from' => '2024-01-01', 'to' => '2024-01-08']);
        $this->assertLessThan(
            self::MAX_MS,
            $elapsed,
            sprintf('date-range filter took %.1fms (threshold %dms) — check idx_calls_create_closed', $elapsed, self::MAX_MS)
        );
    }

    public function testStatusClosedFilterCompletesUnderThreshold(): void
    {
        $elapsed = $this->timeFilter(['status' => 'closed']);
        $this->assertLessThan(
            self::MAX_MS,
            $elapsed,
            sprintf('status=closed filter took %.1fms (threshold %dms) — check idx_calls_create_closed', $elapsed, self::MAX_MS)
        );
    }

    public function testStatusOpenFilterCompletesUnderThreshold(): void
    {
        $elapsed = $this->timeFilter(['status' => 'open']);
        $this->assertLessThan(
            self::MAX_MS,
            $elapsed,
            sprintf('status=open filter took %.1fms (threshold %dms) — check idx_calls_create_closed', $elapsed, self::MAX_MS)
        );
    }

    public function testNoFilterPaginationCompletesUnderThreshold(): void
    {
        // Baseline: no filter, just paginated list of most recent calls.
        $elapsed = $this->timeFilter(['per_page' => '30']);
        $this->assertLessThan(
            self::MAX_MS,
            $elapsed,
            sprintf('unfiltered paginated list took %.1fms (threshold %dms)', $elapsed, self::MAX_MS)
        );
    }
}
