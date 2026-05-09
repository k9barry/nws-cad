<?php

declare(strict_types=1);

namespace NwsCad\Api\Controllers;

use NwsCad\Database;
use NwsCad\Api\Response;
use NwsCad\Api\DbHelper;
use NwsCad\Api\Filtering\FilterCriteria;
use NwsCad\Api\Filtering\FilterContext;
use NwsCad\Api\Filtering\FilterRegistry;
use NwsCad\Api\Filtering\FilterSqlBuilder;
use NwsCad\Api\Filtering\InvalidFilterException;
use NwsCad\Api\Filtering\SqlFragment;
use PDO;
use Exception;

/**
 * Stats Controller
 * Handles statistics and analytics endpoints
 */
class StatsController
{
    private PDO $db;
    
    private const DAY_NAMES = [
        1 => 'Sunday',
        2 => 'Monday',
        3 => 'Tuesday',
        4 => 'Wednesday',
        5 => 'Thursday',
        6 => 'Friday',
        7 => 'Saturday'
    ];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Get aggregate statistics (combines calls, units, and response times)
     * GET /api/stats
     */
    public function index(): void
    {
        try {
            $criteria = FilterCriteria::fromQuery(
                $_GET,
                FilterRegistry::for('stats')
            );
        } catch (InvalidFilterException $e) {
            Response::error($e->getMessage(), 400);
            return;
        }

        try {
            $builder = new FilterSqlBuilder();
            $sql     = $builder->build(
                $criteria,
                new FilterContext('calls', ['calls'])
            );

            // Get calls stats
            $callsStats = $this->getCallsStats($sql);

            // Get units stats (date range applied via criteria)
            $unitsStats = $this->getUnitsStats($criteria);

            // Get response times stats (date range applied via criteria)
            $responseStats = $this->getResponseStats($criteria);

            // Combine all stats - merge units stats at top level for dashboard compatibility
            $aggregateStats = array_merge(
                $callsStats,
                $unitsStats,
                ['response_times' => $responseStats]
            );

            Response::success($aggregateStats);
        } catch (Exception $e) {
            Response::error('Failed to retrieve aggregate statistics: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get call statistics (internal method)
     */
    private function getCallsStats(SqlFragment $sql): array
    {
        $whereClause = $sql->whereClause;
        $joinsSql    = $sql->joins ? implode(' ', $sql->joins) . ' ' : '';
        $params      = $sql->params;

        // Total calls
        $querySql = "
            SELECT COUNT(DISTINCT calls.id) as total
            FROM calls
            {$joinsSql}
            {$whereClause}
        ";
        $stmt = $this->db->prepare($querySql);
        $stmt->execute($params);
        $totalCalls = (int)$stmt->fetchColumn();

        // Calls by status. Mirrors FilterSqlBuilder's open/closed/reopened/canceled
        // semantics: close_datetime + reopened_flag are authoritative; closed_flag
        // is only the raw record of the latest XML and may disagree with reality
        // when CAD sends post-close updates.
        $querySql = "
            SELECT
                CASE
                    WHEN MAX(calls.canceled_flag) = 1 THEN 'canceled'
                    WHEN MAX(calls.reopened_flag) = 1 THEN 'reopened'
                    WHEN MAX(calls.close_datetime) IS NOT NULL THEN 'closed'
                    ELSE 'open'
                END as status,
                COUNT(DISTINCT calls.id) as count
            FROM calls
            {$joinsSql}
            {$whereClause}
            GROUP BY calls.id
        ";
        $stmt = $this->db->prepare($querySql);
        $stmt->execute($params);

        // Count by status. 'open' for the active stat card includes both
        // never-closed and reopened calls so it agrees with the dashboard's
        // "open" filter value (canceled=0 AND (close_datetime IS NULL OR reopened=1)).
        $statusCounts = ['open' => 0, 'closed' => 0, 'reopened' => 0, 'canceled' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $statusCounts[$row['status']] = ($statusCounts[$row['status']] ?? 0) + 1;
        }
        $statusCounts['open'] += $statusCounts['reopened'];
        $byStatus = $statusCounts;

        // Top call types from agency_contexts.
        // agency_contexts is already LEFT JOINed by FilterSqlBuilder when call_type/agency
        // filters are active; add it here if not already present.
        $callTypeJoinsSql = $joinsSql;
        if (stripos($callTypeJoinsSql, 'agency_contexts') === false) {
            $callTypeJoinsSql .= 'LEFT JOIN agency_contexts ON agency_contexts.call_id = calls.id ';
        }
        $callTypeWhereClause = $whereClause !== ''
            ? $whereClause . ' AND agency_contexts.call_type IS NOT NULL'
            : 'WHERE agency_contexts.call_type IS NOT NULL';

        $querySql = "
            SELECT
                agency_contexts.call_type,
                COUNT(DISTINCT calls.id) as count
            FROM calls
            {$callTypeJoinsSql}
            {$callTypeWhereClause}
            GROUP BY agency_contexts.call_type
            ORDER BY count DESC
            LIMIT 10
        ";
        $stmt = $this->db->prepare($querySql);
        $stmt->execute($params);
        $topCallTypes = array_map(function ($row) {
            return [
                'call_type' => $row['call_type'],
                'count' => (int)$row['count']
            ];
        }, $stmt->fetchAll());

        // Calls by jurisdiction from incidents table.
        // incidents is already LEFT JOINed by FilterSqlBuilder when incidentType filter is active;
        // add it here if not already present.
        $jurisdJoinsSql = $joinsSql;
        if (stripos($jurisdJoinsSql, 'incidents') === false) {
            $jurisdJoinsSql .= 'LEFT JOIN incidents ON incidents.call_id = calls.id ';
        }
        $jurisdWhereClause = $whereClause !== ''
            ? $whereClause . ' AND incidents.jurisdiction IS NOT NULL'
            : 'WHERE incidents.jurisdiction IS NOT NULL';

        $querySql = "
            SELECT
                incidents.jurisdiction,
                COUNT(DISTINCT calls.id) as count
            FROM calls
            {$jurisdJoinsSql}
            {$jurisdWhereClause}
            GROUP BY incidents.jurisdiction
            ORDER BY count DESC
        ";
        $stmt = $this->db->prepare($querySql);
        $stmt->execute($params);
        $callsByJurisdiction = array_map(function ($row) {
            return [
                'jurisdiction' => $row['jurisdiction'],
                'count' => (int)$row['count']
            ];
        }, $stmt->fetchAll());

        return [
            'total_calls' => $totalCalls,
            'calls_by_status' => $byStatus,
            'top_call_types' => $topCallTypes,
            'calls_by_jurisdiction' => $callsByJurisdiction
        ];
    }

    /**
     * Get units statistics (internal method)
     */
    private function getUnitsStats(FilterCriteria $criteria): array
    {
        $where = [];
        $params = [];

        if ($criteria->dateRange !== null) {
            $where[] = "u.assigned_datetime >= :date_from";
            $where[] = "u.assigned_datetime <= :date_to";
            $params[':date_from'] = $criteria->dateRange->from->format('Y-m-d H:i:s');
            $params[':date_to']   = $criteria->dateRange->to->format('Y-m-d H:i:s');
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total units
        $sql = "SELECT COUNT(DISTINCT u.unit_number) FROM units u {$whereClause}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $totalUnits = (int)$stmt->fetchColumn();

        return [
            'total_units' => $totalUnits
        ];
    }

    /**
     * Get response time statistics (internal method)
     */
    private function getResponseStats(FilterCriteria $criteria): array
    {
        $where = [];
        $params = [];

        if ($criteria->dateRange !== null) {
            $where[] = "u.assigned_datetime >= :date_from";
            $where[] = "u.assigned_datetime <= :date_to";
            $params[':date_from'] = $criteria->dateRange->from->format('Y-m-d H:i:s');
            $params[':date_to']   = $criteria->dateRange->to->format('Y-m-d H:i:s');
        }

        // Average response time
        $responseWhere = $where;
        $responseWhere[] = 'u.dispatch_datetime IS NOT NULL';
        $responseWhere[] = 'u.arrive_datetime IS NOT NULL';
        $responseWhereClause = 'WHERE ' . implode(' AND ', $responseWhere);

        $responseMinutes = DbHelper::timestampDiff('MINUTE', 'u.dispatch_datetime', 'u.arrive_datetime');
        $sql = "
            SELECT
                AVG({$responseMinutes}) as avg_minutes,
                MIN({$responseMinutes}) as min_minutes,
                MAX({$responseMinutes}) as max_minutes
            FROM units u
            {$responseWhereClause}
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $times = $stmt->fetch();

        // Handle case where no results are returned
        if (!$times) {
            return [
                'average_minutes' => 0.0,
                'min_minutes' => 0.0,
                'max_minutes' => 0.0
            ];
        }

        return [
            'average_minutes' => round((float)($times['avg_minutes'] ?? 0), 2),
            'min_minutes' => round((float)($times['min_minutes'] ?? 0), 2),
            'max_minutes' => round((float)($times['max_minutes'] ?? 0), 2)
        ];
    }

    /**
     * Get call statistics
     * GET /api/stats/calls
     */
    public function calls(): void
    {
        try {
            $criteria = FilterCriteria::fromQuery(
                $_GET,
                FilterRegistry::for('stats')
            );
        } catch (InvalidFilterException $e) {
            Response::error($e->getMessage(), 400);
            return;
        }

        try {
            $builder = new FilterSqlBuilder();
            $sql     = $builder->build(
                $criteria,
                new FilterContext('calls', ['calls'])
            );

            $whereClause = $sql->whereClause;
            $joinsSql    = $sql->joins ? implode(' ', $sql->joins) . ' ' : '';
            $params      = $sql->params;

            // Total calls
            $querySql = "
                SELECT COUNT(DISTINCT calls.id) as total
                FROM calls
                {$joinsSql}
                {$whereClause}
            ";
            $stmt = $this->db->prepare($querySql);
            $stmt->execute($params);
            $totalCalls = (int)$stmt->fetchColumn();

            // Calls by status. Same semantics as the primary index() endpoint.
            $querySql = "
                SELECT
                    CASE
                        WHEN MAX(calls.canceled_flag) = 1 THEN 'Canceled'
                        WHEN MAX(calls.reopened_flag) = 1 THEN 'Reopened'
                        WHEN MAX(calls.close_datetime) IS NOT NULL THEN 'Closed'
                        ELSE 'Open'
                    END as status,
                    COUNT(DISTINCT calls.id) as count
                FROM calls
                {$joinsSql}
                {$whereClause}
                GROUP BY calls.id
            ";
            $stmt = $this->db->prepare($querySql);
            $stmt->execute($params);

            $statusCounts = ['Open' => 0, 'Closed' => 0, 'Canceled' => 0];
            foreach ($stmt->fetchAll() as $row) {
                $statusCounts[$row['status']] = ($statusCounts[$row['status']] ?? 0) + 1;
            }
            $byStatus = $statusCounts;

            // Calls by agency type — ensure agency_contexts is joined
            $agencyJoinsSql = $joinsSql;
            if (stripos($agencyJoinsSql, 'agency_contexts') === false) {
                $agencyJoinsSql .= 'LEFT JOIN agency_contexts ON agency_contexts.call_id = calls.id ';
            }
            $querySql = "
                SELECT
                    agency_contexts.agency_type,
                    COUNT(DISTINCT calls.id) as count
                FROM calls
                {$agencyJoinsSql}
                {$whereClause}
                GROUP BY agency_contexts.agency_type
                ORDER BY count DESC
            ";
            $stmt = $this->db->prepare($querySql);
            $stmt->execute($params);
            $byAgencyType = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Calls by call type (top 10) — reuse same agency_contexts join
            $querySql = "
                SELECT
                    agency_contexts.call_type,
                    COUNT(DISTINCT calls.id) as count
                FROM calls
                {$agencyJoinsSql}
                {$whereClause}
                GROUP BY agency_contexts.call_type
                ORDER BY count DESC
                LIMIT 10
            ";
            $stmt = $this->db->prepare($querySql);
            $stmt->execute($params);
            $byCallType = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Calls by hour of day
            $hourFunc = DbHelper::hour('calls.create_datetime');
            $querySql = "
                SELECT
                    {$hourFunc} as hour,
                    COUNT(DISTINCT calls.id) as count
                FROM calls
                {$joinsSql}
                {$whereClause}
                GROUP BY {$hourFunc}
                ORDER BY hour
            ";
            $stmt = $this->db->prepare($querySql);
            $stmt->execute($params);
            $byHour = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Calls by day of week
            $dayFunc = DbHelper::dayOfWeek('calls.create_datetime');
            $querySql = "
                SELECT
                    {$dayFunc} as day_of_week,
                    COUNT(DISTINCT calls.id) as count
                FROM calls
                {$joinsSql}
                {$whereClause}
                GROUP BY {$dayFunc}
                ORDER BY day_of_week
            ";
            $stmt = $this->db->prepare($querySql);
            $stmt->execute($params);
            $byDayOfWeek = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $byDayOfWeekNamed = [];
            foreach ($byDayOfWeek as $day => $count) {
                $byDayOfWeekNamed[self::DAY_NAMES[$day] ?? 'Unknown'] = $count;
            }

            // Calls by date — when no date filter, limit to last 30 days
            $byDateParams = $params;
            if ($criteria->dateRange !== null) {
                // Date range already encoded in $whereClause / $params
                $byDateWhereClause = $whereClause;
            } else {
                $thirtyDaysAgo = (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');
                $byDateParams['date_30d'] = $thirtyDaysAgo;
                $byDateWhereClause = 'WHERE calls.create_datetime >= :date_30d';
            }

            $dateFunc = DbHelper::date('calls.create_datetime');
            $querySql = "
                SELECT
                    {$dateFunc} as date,
                    COUNT(DISTINCT calls.id) as count
                FROM calls
                {$joinsSql}
                {$byDateWhereClause}
                GROUP BY date
                ORDER BY date
            ";
            $stmt = $this->db->prepare($querySql);
            $stmt->execute($byDateParams);
            $byDate = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Average call duration (for closed calls only). Use close_datetime
            // and reopened_flag to identify closed calls; closed_flag is no
            // longer authoritative since the parser writes whatever the latest
            // XML carries (see FilterSqlBuilder).
            $durationClauses = $whereClause !== ''
                ? $whereClause . ' AND calls.close_datetime IS NOT NULL AND calls.reopened_flag = 0'
                : 'WHERE calls.close_datetime IS NOT NULL AND calls.reopened_flag = 0';

            $timestampDiff = DbHelper::timestampDiff('MINUTE', 'calls.create_datetime', 'calls.close_datetime');
            $querySql = "
                SELECT
                    AVG({$timestampDiff}) as avg_duration_minutes
                FROM calls
                {$joinsSql}
                {$durationClauses}
            ";
            $stmt = $this->db->prepare($querySql);
            $stmt->execute($params);
            $avgDuration = (float)($stmt->fetchColumn() ?? 0);

            Response::success([
                'total_calls' => $totalCalls,
                'by_status' => $byStatus,
                'by_agency_type' => $byAgencyType,
                'by_call_type' => $byCallType,
                'by_hour' => $byHour,
                'by_day_of_week' => $byDayOfWeekNamed,
                'by_date' => $byDate,
                'average_duration_minutes' => round($avgDuration, 2)
            ]);
        } catch (Exception $e) {
            Response::error('Failed to retrieve call statistics: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get unit statistics
     * GET /api/stats/units
     */
    public function units(): void
    {
        try {
            $criteria = FilterCriteria::fromQuery(
                $_GET,
                FilterRegistry::for('stats')
            );
        } catch (InvalidFilterException $e) {
            Response::error($e->getMessage(), 400);
            return;
        }

        try {
            // Build WHERE clause on the units table.
            // date range applies to u.assigned_datetime (unit assignment time, not call create time).
            // unit_type and jurisdiction are units-table columns not covered by FilterCriteria/FilterSqlBuilder.
            $where  = [];
            $params = [];

            if ($criteria->dateRange !== null) {
                $where[]                = 'u.assigned_datetime >= :date_from';
                $where[]                = 'u.assigned_datetime <= :date_to';
                $params[':date_from']   = $criteria->dateRange->from->format('Y-m-d H:i:s');
                $params[':date_to']     = $criteria->dateRange->to->format('Y-m-d H:i:s');
            }

            // unit_type filter — units table column, read directly from GET after criteria parsed it safely
            $unitType = isset($_GET['unit_type']) && is_string($_GET['unit_type']) && $_GET['unit_type'] !== ''
                ? $_GET['unit_type'] : null;
            if ($unitType !== null) {
                $where[]              = 'u.unit_type = :unit_type';
                $params[':unit_type'] = $unitType;
            }

            // jurisdiction filter — units table column
            $jurisdiction = isset($_GET['jurisdiction']) && is_string($_GET['jurisdiction']) && $_GET['jurisdiction'] !== ''
                ? $_GET['jurisdiction'] : null;
            if ($jurisdiction !== null) {
                $where[]                = 'u.jurisdiction = :jurisdiction';
                $params[':jurisdiction'] = $jurisdiction;
            }

            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            // Total unit dispatches
            $sql = "SELECT COUNT(*) FROM units u {$whereClause}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $totalDispatches = (int)$stmt->fetchColumn();

            // Dispatches by unit type
            $sql = "
                SELECT
                    u.unit_type,
                    COUNT(*) as count
                FROM units u
                {$whereClause}
                GROUP BY u.unit_type
                ORDER BY count DESC
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $byUnitType = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Dispatches by jurisdiction
            $sql = "
                SELECT
                    u.jurisdiction,
                    COUNT(*) as count
                FROM units u
                {$whereClause}
                GROUP BY u.jurisdiction
                ORDER BY count DESC
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $byJurisdiction = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Most active units (top 20)
            $sql = "
                SELECT
                    u.unit_number,
                    COUNT(*) as dispatch_count
                FROM units u
                {$whereClause}
                GROUP BY u.unit_number
                ORDER BY dispatch_count DESC
                LIMIT 20
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $mostActiveUnits = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Average response times (dispatch to arrive)
            $responseDiff    = DbHelper::timestampDiff('MINUTE', 'u.dispatch_datetime', 'u.arrive_datetime');
            $responseWhere   = $whereClause !== ''
                ? $whereClause . ' AND u.dispatch_datetime IS NOT NULL AND u.arrive_datetime IS NOT NULL'
                : 'WHERE u.dispatch_datetime IS NOT NULL AND u.arrive_datetime IS NOT NULL';
            $sql = "SELECT AVG({$responseDiff}) as avg_response_minutes FROM units u {$responseWhere}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $avgResponseTime = (float)$stmt->fetchColumn();

            // Average enroute time (dispatch to enroute)
            $enrouteDiff   = DbHelper::timestampDiff('SECOND', 'u.dispatch_datetime', 'u.enroute_datetime');
            $enrouteWhere  = $whereClause !== ''
                ? $whereClause . ' AND u.dispatch_datetime IS NOT NULL AND u.enroute_datetime IS NOT NULL'
                : 'WHERE u.dispatch_datetime IS NOT NULL AND u.enroute_datetime IS NOT NULL';
            $sql = "SELECT AVG({$enrouteDiff}) as avg_enroute_seconds FROM units u {$enrouteWhere}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $avgEnrouteTime = (float)$stmt->fetchColumn();

            // Average on-scene time (arrive to clear)
            $onSceneDiff  = DbHelper::timestampDiff('MINUTE', 'u.arrive_datetime', 'u.clear_datetime');
            $onSceneWhere = $whereClause !== ''
                ? $whereClause . ' AND u.arrive_datetime IS NOT NULL AND u.clear_datetime IS NOT NULL'
                : 'WHERE u.arrive_datetime IS NOT NULL AND u.clear_datetime IS NOT NULL';
            $sql = "SELECT AVG({$onSceneDiff}) as avg_onscene_minutes FROM units u {$onSceneWhere}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $avgOnSceneTime = (float)$stmt->fetchColumn();

            // Primary unit vs backup
            $sql = "
                SELECT
                    CASE WHEN u.is_primary = 1 THEN 'Primary' ELSE 'Backup' END as unit_role,
                    COUNT(*) as count
                FROM units u
                {$whereClause}
                GROUP BY unit_role
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $byRole = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            Response::success([
                'total_dispatches' => $totalDispatches,
                'by_unit_type' => $byUnitType,
                'by_jurisdiction' => $byJurisdiction,
                'most_active_units' => $mostActiveUnits,
                'by_role' => $byRole,
                'average_times' => [
                    'response_minutes' => round($avgResponseTime, 2),
                    'enroute_seconds' => round($avgEnrouteTime, 2),
                    'on_scene_minutes' => round($avgOnSceneTime, 2)
                ]
            ]);
        } catch (Exception $e) {
            Response::error('Failed to retrieve unit statistics: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get response time analytics
     * GET /api/stats/response-times
     */
    public function responseTimes(): void
    {
        try {
            $criteria = FilterCriteria::fromQuery(
                $_GET,
                FilterRegistry::for('stats')
            );
        } catch (InvalidFilterException $e) {
            Response::error($e->getMessage(), 400);
            return;
        }

        try {
            // Build WHERE clause and joins for unit-based queries.
            // FilterSqlBuilder with unitsBase=true generates joins reaching back through units.call_id.
            // We always need the calls join for the time-breakdown query (c.create_datetime),
            // so we mark it as already-joined and add it explicitly.
            $builder = new FilterSqlBuilder();
            $sql     = $builder->build(
                $criteria,
                new FilterContext('units', ['units', 'calls'], true)
            );

            $filterJoins    = $sql->joins ? implode(' ', $sql->joins) . ' ' : '';
            $filterWhere    = $sql->whereClause; // references calls.* columns when dateRange is set
            $params         = $sql->params;

            // Always join calls (needed for date-range filter on calls.create_datetime and time breakdown)
            $baseJoinClause = 'INNER JOIN calls ON calls.id = units.call_id ' . $filterJoins;

            // unit_type and jurisdiction are units-table columns not in FilterCriteria.
            // Read them from GET after criteria parsing so they are validated as safe non-empty strings.
            $extraWhere = [];

            $unitType = isset($_GET['unit_type']) && is_string($_GET['unit_type']) && $_GET['unit_type'] !== ''
                ? $_GET['unit_type'] : null;
            if ($unitType !== null) {
                $extraWhere[]           = 'units.unit_type = :unit_type';
                $params[':unit_type']   = $unitType;
            }

            $jurisdiction = isset($_GET['jurisdiction']) && is_string($_GET['jurisdiction']) && $_GET['jurisdiction'] !== ''
                ? $_GET['jurisdiction'] : null;
            if ($jurisdiction !== null) {
                $extraWhere[]             = 'units.jurisdiction = :jurisdiction';
                $params[':jurisdiction']  = $jurisdiction;
            }

            // priority is an agency_contexts column — needs that table joined.
            // agency_contexts may already be joined by FilterSqlBuilder (if agency filter active).
            $priority = isset($_GET['priority']) && is_string($_GET['priority']) && $_GET['priority'] !== ''
                ? $_GET['priority'] : null;
            if ($priority !== null) {
                if (stripos($baseJoinClause, 'agency_contexts') === false) {
                    $baseJoinClause .= 'LEFT JOIN agency_contexts ON agency_contexts.call_id = units.call_id ';
                }
                $escapedPriority        = str_replace(['%', '_'], ['\\%', '\\_'], $priority);
                $extraWhere[]           = 'agency_contexts.priority LIKE :priority';
                $params[':priority']    = '%' . $escapedPriority . '%';
            }

            // Combine filter WHERE with extra unit/priority conditions
            if ($extraWhere) {
                $extraClause = implode(' AND ', $extraWhere);
                $whereClause = $filterWhere !== ''
                    ? $filterWhere . ' AND ' . $extraClause
                    : 'WHERE ' . $extraClause;
            } else {
                $whereClause = $filterWhere;
            }

            // Helper: append a required-column condition to the base WHERE
            $withCols = static function (string $baseWhere, string ...$cols): string {
                $extra = implode(' AND ', $cols);
                return $baseWhere !== '' ? $baseWhere . ' AND ' . $extra : 'WHERE ' . $extra;
            };

            $responseMinutes = DbHelper::timestampDiff('MINUTE', 'units.dispatch_datetime', 'units.arrive_datetime');

            // Overall response time statistics (dispatch to arrive)
            $overallWhere = $withCols($whereClause, 'units.dispatch_datetime IS NOT NULL', 'units.arrive_datetime IS NOT NULL');
            $querySql = "
                SELECT
                    COUNT(*) as total_responses,
                    AVG({$responseMinutes}) as avg_minutes,
                    MIN({$responseMinutes}) as min_minutes,
                    MAX({$responseMinutes}) as max_minutes,
                    STDDEV({$responseMinutes}) as stddev_minutes
                FROM units
                {$baseJoinClause}
                {$overallWhere}
            ";
            $stmt = $this->db->prepare($querySql);
            $stmt->execute($params);
            $overall = $stmt->fetch();

            // Response times by unit type
            $querySql = "
                SELECT
                    units.unit_type,
                    COUNT(*) as count,
                    AVG({$responseMinutes}) as avg_minutes
                FROM units
                {$baseJoinClause}
                {$overallWhere}
                GROUP BY units.unit_type
                ORDER BY avg_minutes ASC
            ";
            $stmt = $this->db->prepare($querySql);
            $stmt->execute($params);
            $byUnitType = $stmt->fetchAll();

            // Response times by jurisdiction
            $querySql = "
                SELECT
                    units.jurisdiction,
                    COUNT(*) as count,
                    AVG({$responseMinutes}) as avg_minutes
                FROM units
                {$baseJoinClause}
                {$overallWhere}
                GROUP BY units.jurisdiction
                ORDER BY avg_minutes ASC
            ";
            $stmt = $this->db->prepare($querySql);
            $stmt->execute($params);
            $byJurisdiction = $stmt->fetchAll();

            // Response time percentiles — fetch all values for PHP-side calculation
            $querySql = "
                SELECT
                    {$responseMinutes} as response_minutes
                FROM units
                {$baseJoinClause}
                {$overallWhere}
                ORDER BY response_minutes
            ";
            $stmt = $this->db->prepare($querySql);
            $stmt->execute($params);
            $responseTimeValues = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $percentiles = [];
            if (count($responseTimeValues) > 0) {
                $percentiles = [
                    '50th' => $this->calculatePercentile($responseTimeValues, 50),
                    '75th' => $this->calculatePercentile($responseTimeValues, 75),
                    '90th' => $this->calculatePercentile($responseTimeValues, 90),
                    '95th' => $this->calculatePercentile($responseTimeValues, 95)
                ];
            }

            // Breakdown of response time components (requires assigned_datetime)
            $callToAssigned     = DbHelper::timestampDiff('SECOND', 'calls.create_datetime', 'units.assigned_datetime');
            $assignedToDispatch = DbHelper::timestampDiff('SECOND', 'units.assigned_datetime', 'units.dispatch_datetime');
            $dispatchToEnroute  = DbHelper::timestampDiff('SECOND', 'units.dispatch_datetime', 'units.enroute_datetime');
            $enrouteToArrive    = DbHelper::timestampDiff('MINUTE', 'units.enroute_datetime', 'units.arrive_datetime');

            $breakdownWhere = $withCols($whereClause, 'units.assigned_datetime IS NOT NULL');
            $querySql = "
                SELECT
                    AVG({$callToAssigned}) as avg_call_to_assigned_seconds,
                    AVG({$assignedToDispatch}) as avg_assigned_to_dispatch_seconds,
                    AVG({$dispatchToEnroute}) as avg_dispatch_to_enroute_seconds,
                    AVG({$enrouteToArrive}) as avg_enroute_to_arrive_minutes
                FROM units
                {$baseJoinClause}
                {$breakdownWhere}
            ";
            $stmt = $this->db->prepare($querySql);
            $stmt->execute($params);
            $breakdown = $stmt->fetch();

            Response::success([
                'overall' => [
                    'total_responses' => (int)($overall['total_responses'] ?? 0),
                    'average_minutes' => round((float)($overall['avg_minutes'] ?? 0), 2),
                    'min_minutes' => round((float)($overall['min_minutes'] ?? 0), 2),
                    'max_minutes' => round((float)($overall['max_minutes'] ?? 0), 2),
                    'stddev_minutes' => round((float)($overall['stddev_minutes'] ?? 0), 2)
                ],
                'percentiles' => $percentiles,
                'by_unit_type' => array_map(function ($row) {
                    return [
                        'unit_type' => $row['unit_type'],
                        'count' => (int)$row['count'],
                        'avg_minutes' => round((float)$row['avg_minutes'], 2)
                    ];
                }, $byUnitType),
                'by_jurisdiction' => array_map(function ($row) {
                    return [
                        'jurisdiction' => $row['jurisdiction'],
                        'count' => (int)$row['count'],
                        'avg_minutes' => round((float)$row['avg_minutes'], 2)
                    ];
                }, $byJurisdiction),
                'time_breakdown' => [
                    'call_to_assigned_seconds' => round((float)($breakdown['avg_call_to_assigned_seconds'] ?? 0), 2),
                    'assigned_to_dispatch_seconds' => round((float)($breakdown['avg_assigned_to_dispatch_seconds'] ?? 0), 2),
                    'dispatch_to_enroute_seconds' => round((float)($breakdown['avg_dispatch_to_enroute_seconds'] ?? 0), 2),
                    'enroute_to_arrive_minutes' => round((float)($breakdown['avg_enroute_to_arrive_minutes'] ?? 0), 2)
                ]
            ]);
        } catch (Exception $e) {
            Response::error('Failed to retrieve response time analytics: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Calculate percentile from array of values
     */
    private function calculatePercentile(array $values, int $percentile): float
    {
        if (empty($values)) {
            return 0;
        }

        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);
        $lower = floor($index);
        $upper = ceil($index);

        if ($lower === $upper) {
            return round((float)$values[$lower], 2);
        }

        $fraction = $index - $lower;
        return round((float)($values[$lower] + ($values[$upper] - $values[$lower]) * $fraction), 2);
    }
}
