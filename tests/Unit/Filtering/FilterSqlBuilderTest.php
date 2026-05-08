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
        $this->assertContains('LEFT JOIN agency_contexts ac ON ac.call_id = calls.id', $joins);
    }

    public function testStatusOpenDecodesToFlagsClause(): void
    {
        [$where, $params] = $this->build(['status' => 'open']);
        $this->assertStringContainsString('calls.closed_flag = 0', $where);
        $this->assertStringContainsString('calls.canceled_flag = 0', $where);
    }

    public function testStatusMultipleOrsClauses(): void
    {
        [$where] = $this->build(['status' => 'open,closed']);
        // Each value contributes a parenthesized clause OR'd together
        $this->assertMatchesRegularExpression(
            '/\(.*closed_flag = 0.*canceled_flag = 0.*\) OR \(.*closed_flag = 1.*canceled_flag = 0.*\)/s',
            $where
        );
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
        $this->assertStringContainsString('locations.police_ori IN (:ori_0)', $where);
        $this->assertStringContainsString('OR locations.ems_ori IN (:ori_0)', $where);
        $this->assertStringContainsString('OR locations.fire_ori IN (:ori_0)', $where);
        $this->assertContains('LEFT JOIN locations ON locations.call_id = calls.id', $joins);
    }

    public function testFdidJoinsAgencyContexts(): void
    {
        [$where, $params, $joins] = $this->build(['fdid' => '48013']);
        $this->assertStringContainsString('agency_contexts.fdid IN (:fdid_0)', $where);
        $this->assertContains('LEFT JOIN agency_contexts ac ON ac.call_id = calls.id', $joins);
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
        $this->assertStringContainsString('locations.full_address LIKE :location', $where);
        $this->assertStringContainsString('locations.common_name LIKE :location', $where);
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
        $this->assertNotContains('LEFT JOIN agency_contexts ac ON ac.call_id = calls.id', $joins);
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
}
