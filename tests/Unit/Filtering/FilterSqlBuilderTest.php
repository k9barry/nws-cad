<?php
declare(strict_types=1);

namespace NwsCad\Tests\Unit\Filtering;

use NwsCad\Api\Filtering\FilterContext;
use NwsCad\Api\Filtering\FilterCriteria;
use NwsCad\Api\Filtering\FilterRegistry;
use NwsCad\Api\Filtering\FilterSqlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Filtering\FilterSqlBuilder
 * @uses \NwsCad\Api\Filtering\DateRange
 * @uses \NwsCad\Api\Filtering\FilterContext
 * @uses \NwsCad\Api\Filtering\FilterCriteria
 * @uses \NwsCad\Api\Filtering\FilterRegistry
 * @uses \NwsCad\Api\Filtering\InvalidFilterException
 * @uses \NwsCad\Api\Filtering\SqlFragment
 * @uses \NwsCad\Config
 * @uses \NwsCad\Logging\SecretRegistry
 */
final class FilterSqlBuilderTest extends TestCase
{
    private FilterSqlBuilder $b;
    private array $allowed;

    protected function setUp(): void
    {
        $this->b = new FilterSqlBuilder();
        $this->allowed = FilterRegistry::for('calls');
    }

    private function build(array $query, array $alreadyJoined = ['calls']): array
    {
        $criteria = FilterCriteria::fromQuery($query, $this->allowed);
        $ctx = new FilterContext('calls', $alreadyJoined);
        $f = $this->b->build($criteria, $ctx);
        return [$f->whereClause, $f->params, $f->joins];
    }

    public function testEmptyCriteriaProducesEmptyFragment(): void
    {
        [$where, $params, $joins] = $this->build([]);
        $this->assertSame('', $where);
        $this->assertSame([], $params);
        $this->assertSame([], $joins);
    }

    public function testCallTypeMultiSelectGeneratesInClause(): void
    {
        [$where, $params, $joins] = $this->build(['call_type' => 'Police,Fire']);
        $this->assertStringContainsString('IN (:call_type_0, :call_type_1)', $where);
        $this->assertSame('Police', $params['call_type_0']);
        $this->assertSame('Fire',   $params['call_type_1']);
        $this->assertContains('LEFT JOIN agency_contexts ON agency_contexts.call_id = calls.id', $joins);
    }

    public function testStatusOpenKeysOffCloseDatetimeAndReopened(): void
    {
        [$where] = $this->build(['status' => 'open']);
        // Open is canceled=0 AND (close_datetime IS NULL OR reopened_flag = 1)
        // AND not stale (create_datetime within the 72h guardrail window).
        $this->assertStringContainsString('calls.canceled_flag = FALSE', $where);
        $this->assertStringContainsString('calls.close_datetime IS NULL', $where);
        $this->assertStringContainsString('calls.reopened_flag = TRUE', $where);
        $this->assertStringContainsString('calls.create_datetime >= :stale_cutoff', $where);
        // Authoritative open/closed signal is no longer closed_flag
        $this->assertStringNotContainsString('closed_flag = 0', $where);
    }

    public function testStatusClosedRequiresCloseDatetimeAndNotReopened(): void
    {
        [$where] = $this->build(['status' => 'closed']);
        // closed = legitimately closed OR stale (older than guardrail window).
        $this->assertStringContainsString('calls.close_datetime IS NOT NULL', $where);
        $this->assertStringContainsString('calls.reopened_flag = FALSE', $where);
        $this->assertStringContainsString('calls.canceled_flag = FALSE', $where);
        $this->assertStringContainsString('calls.create_datetime < :stale_cutoff', $where);
        $this->assertStringNotContainsString('closed_flag = 1', $where);
    }

    public function testStatusReopenedFiltersOnReopenedFlag(): void
    {
        [$where] = $this->build(['status' => 'reopened']);
        // reopened is "open with a reopen": must still be within the guardrail window.
        $this->assertStringContainsString('calls.reopened_flag = TRUE', $where);
        $this->assertStringContainsString('calls.canceled_flag = FALSE', $where);
        $this->assertStringContainsString('calls.create_datetime >= :stale_cutoff', $where);
    }

    public function testStatusCanceledUnchanged(): void
    {
        [$where] = $this->build(['status' => 'canceled']);
        $this->assertStringContainsString('calls.canceled_flag = TRUE', $where);
    }

    public function testStatusBindsStaleCutoffParamForOpen(): void
    {
        [, $params] = $this->build(['status' => 'open']);
        $this->assertArrayHasKey('stale_cutoff', $params);
        // Format is ISO Y-m-d H:i:s so MySQL/Postgres both parse it without
        // INTERVAL-syntax differences.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $params['stale_cutoff']
        );
    }

    public function testStatusCanceledOnlyDoesNotBindStaleCutoff(): void
    {
        [, $params] = $this->build(['status' => 'canceled']);
        // canceled has no stale concept — it never references create_datetime.
        $this->assertArrayNotHasKey('stale_cutoff', $params);
    }

    public function testNoStatusFilterDoesNotBindStaleCutoff(): void
    {
        [, $params] = $this->build(['call_type' => 'Police']);
        $this->assertArrayNotHasKey('stale_cutoff', $params);
    }

    public function testStatusInvalidValueRejected(): void
    {
        $this->expectException(\NwsCad\Api\Filtering\InvalidFilterException::class);
        $this->build(['status' => 'whatever']);
    }

    public function testStatusMultipleOrsClauses(): void
    {
        [$where] = $this->build(['status' => 'open,closed']);
        // Each value contributes a parenthesized clause OR'd together
        $this->assertMatchesRegularExpression(
            '/\(.*close_datetime IS NULL.*reopened_flag = TRUE.*\) OR \(.*close_datetime IS NOT NULL.*reopened_flag = FALSE.*\)/s',
            $where
        );
        // And the stale guardrail predicate appears on both sides
        $this->assertStringContainsString('calls.create_datetime >= :stale_cutoff', $where);
        $this->assertStringContainsString('calls.create_datetime < :stale_cutoff', $where);
    }

    public function testDateRangeBindsFromAndTo(): void
    {
        [$where, $params] = $this->build(['from' => '2026-05-01', 'to' => '2026-05-08']);
        $this->assertStringContainsString('calls.create_datetime >= :date_from', $where);
        $this->assertStringContainsString('calls.create_datetime <= :date_to', $where);
        $this->assertSame('2026-05-01 00:00:00', $params['date_from']);
        $this->assertSame('2026-05-08 23:59:59', $params['date_to']);
    }

    public function testDateFieldClosedSwitchesColumn(): void
    {
        [$where] = $this->build(['from' => '2026-05-01', 'date_field' => 'closed']);
        $this->assertStringContainsString('calls.close_datetime >= :date_from', $where);
    }

    public function testOriMatchesAcrossThreeColumns(): void
    {
        [$where, $params, $joins] = $this->build(['ori' => 'IN0480000']);
        // Each column gets its own parameter name to satisfy PDO duplicate-parameter constraint
        $this->assertStringContainsString('locations.police_ori IN (:ori_police_0)', $where);
        $this->assertStringContainsString('OR locations.ems_ori IN (:ori_ems_0)', $where);
        $this->assertStringContainsString('OR locations.fire_ori IN (:ori_fire_0)', $where);
        $this->assertSame('IN0480000', $params['ori_police_0']);
        $this->assertSame('IN0480000', $params['ori_ems_0']);
        $this->assertSame('IN0480000', $params['ori_fire_0']);
        $this->assertContains('LEFT JOIN locations ON locations.call_id = calls.id', $joins);
    }

    public function testFdidJoinsAgencyContexts(): void
    {
        [$where, $params, $joins] = $this->build(['fdid' => '48013']);
        $this->assertStringContainsString('agency_contexts.fdid IN (:fdid_0)', $where);
        $this->assertContains('LEFT JOIN agency_contexts ON agency_contexts.call_id = calls.id', $joins);
    }

    public function testNatureOfCallUsesLikeWithEscapedPattern(): void
    {
        [$where, $params] = $this->build(['nature_of_call' => 'jay_walk%']);
        $this->assertStringContainsString('calls.nature_of_call LIKE :nature_of_call', $where);
        // _ and % are escaped to \_ \%
        $this->assertSame('%jay\\_walk\\%%', $params['nature_of_call']);
    }

    public function testLocationMatchesAddressAndCommonName(): void
    {
        [$where, $params] = $this->build(['location' => 'Main St']);
        // Distinct parameter names per column to satisfy PDO duplicate-parameter constraint
        $this->assertStringContainsString('locations.full_address LIKE :location_addr', $where);
        $this->assertStringContainsString('locations.common_name LIKE :location_cname', $where);
        $this->assertSame('%Main St%', $params['location_addr']);
        $this->assertSame('%Main St%', $params['location_cname']);
    }

    public function testCallIdMultiSelect(): void
    {
        [$where, $params] = $this->build(['call_id' => '2026-001,2026-002']);
        $this->assertStringContainsString('calls.call_number IN (:call_id_0, :call_id_1)', $where);
    }

    public function testUnitJoinsUnitsTable(): void
    {
        [$where, $params, $joins] = $this->build(['unit' => '41,42']);
        $this->assertStringContainsString('units.unit_number IN (:unit_0, :unit_1)', $where);
        $this->assertContains('LEFT JOIN units ON units.call_id = calls.id', $joins);
    }

    public function testIncidentTypeJoinsIncidentsTable(): void
    {
        [$where, $params, $joins] = $this->build(['incident_type' => 'Traffic Stop']);
        $this->assertStringContainsString('incidents.incident_type IN (:incident_type_0)', $where);
        $this->assertContains('LEFT JOIN incidents ON incidents.call_id = calls.id', $joins);
    }

    public function testDoesNotEmitJoinForAlreadyJoinedTable(): void
    {
        [$where, $params, $joins] = $this->build(
            ['call_type' => 'Police'],
            ['calls', 'agency_contexts'] // already joined
        );
        $this->assertNotContains('LEFT JOIN agency_contexts ON agency_contexts.call_id = calls.id', $joins);
    }

    public function testCombinesMultipleFiltersWithAnd(): void
    {
        [$where] = $this->build([
            'call_type' => 'Police',
            'status'    => 'open',
            'city'      => 'Pendleton',
        ]);
        $this->assertStringContainsString(' AND ', $where);
    }

    public function testUnitsBaseFlipsJoinsToReachThroughUnits(): void
    {
        $criteria = FilterCriteria::fromQuery(['agency' => 'Pendleton Police'], FilterRegistry::for('units'));
        $ctx = new FilterContext('units', ['units'], unitsBase: true);
        $sql = (new FilterSqlBuilder())->build($criteria, $ctx);
        $this->assertContains('LEFT JOIN agency_contexts ON agency_contexts.call_id = units.call_id', $sql->joins);
    }
}
