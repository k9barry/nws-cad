<?php

declare(strict_types=1);

namespace NwsCad\Api\Controllers;

use NwsCad\Database;
use NwsCad\Api\Request;
use NwsCad\Api\Response;
use NwsCad\Api\DbHelper;
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
            $filters = Request::filters([
                'date_from',
                'date_to',
                'agency_type',
                'jurisdiction'
            ]);

            // Get calls stats
            $callsStats = $this->getCallsStats($filters);
            
            // Get units stats
            $unitsStats = $this->getUnitsStats($filters);
            
            // Get response times stats
            $responseStats = $this->getResponseStats($filters);

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
    private function getCallsStats(array $filters): array
    {
        $params = [];
        
        // Build date filters (used in all queries)
        $dateWhere = [];
        if (isset($filters['date_from'])) {
            $dateWhere[] = "c.create_datetime >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        if (isset($filters['date_to'])) {
            $dateWhere[] = "c.create_datetime <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        // Build agency type filter
        $agencyJoin = '';
        $agencyWhere = '';
        if (isset($filters['agency_type'])) {
            $agencyJoin = "INNER JOIN agency_contexts ac ON c.id = ac.call_id";
            $agencyWhere = "ac.agency_type = :agency_type";
            $params[':agency_type'] = $filters['agency_type'];
        }

        // Build jurisdiction filter
        $jurisdictionJoin = '';
        $jurisdictionWhere = '';
        if (isset($filters['jurisdiction'])) {
            $jurisdictionJoin = "INNER JOIN incidents i ON c.id = i.call_id";
            $jurisdictionWhere = "i.jurisdiction = :jurisdiction";
            $params[':jurisdiction'] = $filters['jurisdiction'];
        }

        // Full join clause for queries needing both
        $fullJoin = $agencyJoin . ' ' . $jurisdictionJoin;
        
        // Build full WHERE clause (date + agency + jurisdiction)
        $allWhere = array_merge($dateWhere, array_filter([$agencyWhere, $jurisdictionWhere]));
        $fullWhereClause = !empty($allWhere) ? 'WHERE ' . implode(' AND ', $allWhere) : '';

        // Total calls
        $sql = "
            SELECT COUNT(DISTINCT c.id) as total
            FROM calls c
            {$fullJoin}
            {$fullWhereClause}
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $totalCalls = (int)$stmt->fetchColumn();

        // Calls by status
        $sql = "
            SELECT 
                CASE 
                    WHEN MAX(c.canceled_flag) = 1 THEN 'canceled'
                    WHEN MAX(c.closed_flag) = 1 THEN 'closed'
                    ELSE 'open'
                END as status,
                COUNT(DISTINCT c.id) as count
            FROM calls c
            {$fullJoin}
            {$fullWhereClause}
            GROUP BY c.id
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        // Count by status
        $statusCounts = ['open' => 0, 'closed' => 0, 'canceled' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $statusCounts[$row['status']] = ($statusCounts[$row['status']] ?? 0) + 1;
        }
        $byStatus = $statusCounts;

        // Top call types from agency_contexts
        // WHERE clause: date filters + agency filter + jurisdiction filter
        $callTypeWhere = array_merge($dateWhere, array_filter([$agencyWhere, $jurisdictionWhere]));
        $callTypeWhereClause = !empty($callTypeWhere) ? 'WHERE ' . implode(' AND ', $callTypeWhere) : '';
        
        $sql = "
            SELECT 
                ac.call_type,
                COUNT(*) as count
            FROM calls c
            INNER JOIN agency_contexts ac ON c.id = ac.call_id
            {$jurisdictionJoin}
            {$callTypeWhereClause}
            AND ac.call_type IS NOT NULL
            GROUP BY ac.call_type
            ORDER BY count DESC
            LIMIT 10
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $topCallTypes = array_map(function($row) {
            return [
                'call_type' => $row['call_type'],
                'count' => (int)$row['count']
            ];
        }, $stmt->fetchAll());

        // Calls by jurisdiction from incidents table
        // WHERE clause: date filters + agency filter (jurisdiction filter applies to the grouping result, not the WHERE)
        $jurisdictionQueryWhere = array_merge($dateWhere, array_filter([$agencyWhere, $jurisdictionWhere]));
        $jurisdictionQueryWhereClause = !empty($jurisdictionQueryWhere) ? 'WHERE ' . implode(' AND ', $jurisdictionQueryWhere) : '';
        
        $sql = "
            SELECT 
                i.jurisdiction,
                COUNT(DISTINCT c.id) as count
            FROM calls c
            {$agencyJoin}
            INNER JOIN incidents i ON c.id = i.call_id
            {$jurisdictionQueryWhereClause}
            AND i.jurisdiction IS NOT NULL
            GROUP BY i.jurisdiction
            ORDER BY count DESC
            LIMIT 10
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $callsByJurisdiction = array_map(function($row) {
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
    private function getUnitsStats(array $filters): array
    {
        $where = [];
        $params = [];

        if (isset($filters['date_from'])) {
            $where[] = "u.assigned_datetime >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (isset($filters['date_to'])) {
            $where[] = "u.assigned_datetime <= :date_to";
            $params[':date_to'] = $filters['date_to'];
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
    private function getResponseStats(array $filters): array
    {
        $where = [];
        $params = [];

        if (isset($filters['date_from'])) {
            $where[] = "u.assigned_datetime >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (isset($filters['date_to'])) {
            $where[] = "u.assigned_datetime <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

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

        return [
            'average_minutes' => round((float)$times['avg_minutes'], 2),
            'min_minutes' => round((float)$times['min_minutes'], 2),
            'max_minutes' => round((float)$times['max_minutes'], 2)
        ];
    }

    /**
     * Get call statistics
     * GET /api/stats/calls
     */
    public function calls(): void
    {
        try {
            $filters = Request::filters([
                'date_from',
                'date_to',
                'agency_type',
                'jurisdiction'
            ]);

            $params = [];
            
            // Helper function to build WHERE clause and params for different queries
            $buildWhereClause = function($includeIncidents = false) use ($filters, &$params) {
                $where = [];
                
                // Date range filter
                if (isset($filters['date_from'])) {
                    $where[] = "c.create_datetime >= :date_from";
                    $params[':date_from'] = $filters['date_from'];
                }
                if (isset($filters['date_to'])) {
                    $where[] = "c.create_datetime <= :date_to";
                    $params[':date_to'] = $filters['date_to'];
                }
                
                // Agency type filter
                if (isset($filters['agency_type'])) {
                    $where[] = "ac.agency_type = :agency_type";
                    $params[':agency_type'] = $filters['agency_type'];
                }
                
                // Jurisdiction filter
                if ($includeIncidents && isset($filters['jurisdiction'])) {
                    $where[] = "i.jurisdiction = :jurisdiction";
                    $params[':jurisdiction'] = $filters['jurisdiction'];
                }
                
                return !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            };
            
            // Helper function to build JOIN clauses
            $buildJoinClause = function($includeIncidents = false) {
                $joins = '';
                // agency_contexts is needed for agency_type, call_type queries
                if (isset($GLOBALS['filters']['agency_type']) || $includeIncidents === 'agency_only') {
                    $joins .= "INNER JOIN agency_contexts ac ON c.id = ac.call_id ";
                }
                // incidents is needed for jurisdiction filter
                if ($includeIncidents && isset($GLOBALS['filters']['jurisdiction'])) {
                    $joins .= "INNER JOIN incidents i ON c.id = i.call_id ";
                }
                return $joins;
            };

            // Total calls
            $whereClause = $buildWhereClause(true);
            $joinClause = '';
            if (isset($filters['agency_type'])) {
                $joinClause .= "INNER JOIN agency_contexts ac ON c.id = ac.call_id ";
            }
            if (isset($filters['jurisdiction'])) {
                $joinClause .= "INNER JOIN incidents i ON c.id = i.call_id ";
            }
            
            $sql = "
                SELECT COUNT(DISTINCT c.id) as total
                FROM calls c
                {$joinClause}
                {$whereClause}
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $totalCalls = (int)$stmt->fetchColumn();

            // Calls by status
            $sql = "
                SELECT 
                    CASE 
                        WHEN MAX(c.canceled_flag) = 1 THEN 'Canceled'
                        WHEN MAX(c.closed_flag) = 1 THEN 'Closed'
                        ELSE 'Open'
                    END as status,
                    COUNT(DISTINCT c.id) as count
                FROM calls c
                {$joinClause}
                {$whereClause}
                GROUP BY c.id
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            // Count by status
            $statusCounts = ['Open' => 0, 'Closed' => 0, 'Canceled' => 0];
            foreach ($stmt->fetchAll() as $row) {
                $statusCounts[$row['status']] = ($statusCounts[$row['status']] ?? 0) + 1;
            }
            $byStatus = $statusCounts;

            // Calls by agency type - needs agency_contexts join
            $agencyTypeWhere = [];
            if (isset($filters['date_from'])) {
                $agencyTypeWhere[] = "c.create_datetime >= :date_from";
            }
            if (isset($filters['date_to'])) {
                $agencyTypeWhere[] = "c.create_datetime <= :date_to";
            }
            if (isset($filters['agency_type'])) {
                $agencyTypeWhere[] = "ac.agency_type = :agency_type";
            }
            if (isset($filters['jurisdiction'])) {
                $agencyTypeWhere[] = "i.jurisdiction = :jurisdiction";
            }
            $agencyTypeWhereClause = !empty($agencyTypeWhere) ? 'WHERE ' . implode(' AND ', $agencyTypeWhere) : '';
            
            $agencyTypeJoin = "INNER JOIN agency_contexts ac ON c.id = ac.call_id ";
            if (isset($filters['jurisdiction'])) {
                $agencyTypeJoin .= "INNER JOIN incidents i ON c.id = i.call_id ";
            }
            
            $sql = "
                SELECT 
                    ac.agency_type,
                    COUNT(DISTINCT c.id) as count
                FROM calls c
                {$agencyTypeJoin}
                {$agencyTypeWhereClause}
                GROUP BY ac.agency_type
                ORDER BY count DESC
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $byAgencyType = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Calls by call type (top 10) - same as agency type join
            $sql = "
                SELECT 
                    ac.call_type,
                    COUNT(DISTINCT c.id) as count
                FROM calls c
                {$agencyTypeJoin}
                {$agencyTypeWhereClause}
                GROUP BY ac.call_type
                ORDER BY count DESC
                LIMIT 10
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $byCallType = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Calls by hour of day
            $hourFunc = DbHelper::hour('c.create_datetime');
            $sql = "
                SELECT 
                    {$hourFunc} as hour,
                    COUNT(DISTINCT c.id) as count
                FROM calls c
                {$joinClause}
                {$whereClause}
                GROUP BY {$hourFunc}
                ORDER BY hour
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $byHour = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Calls by day of week
            $dayFunc = DbHelper::dayOfWeek('c.create_datetime');
            $sql = "
                SELECT 
                    {$dayFunc} as day_of_week,
                    COUNT(DISTINCT c.id) as count
                FROM calls c
                {$joinClause}
                {$whereClause}
                GROUP BY {$dayFunc}
                ORDER BY day_of_week
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $byDayOfWeek = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Map day numbers to names
            $byDayOfWeekNamed = [];
            foreach ($byDayOfWeek as $day => $count) {
                $byDayOfWeekNamed[self::DAY_NAMES[$day] ?? 'Unknown'] = $count;
            }

            // Calls by date (last 30 days if no date range specified)
            $byDateWhere = [];
            if (isset($filters['date_from'])) {
                $byDateWhere[] = "c.create_datetime >= :date_from";
            }
            if (isset($filters['date_to'])) {
                $byDateWhere[] = "c.create_datetime <= :date_to";
            } elseif (!isset($filters['date_from'])) {
                // Add 30 day limit if no dates specified
                $byDateWhere[] = "c.create_datetime >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            }
            if (isset($filters['agency_type'])) {
                $byDateWhere[] = "ac.agency_type = :agency_type";
            }
            if (isset($filters['jurisdiction'])) {
                $byDateWhere[] = "i.jurisdiction = :jurisdiction";
            }
            $byDateWhereClause = !empty($byDateWhere) ? 'WHERE ' . implode(' AND ', $byDateWhere) : '';

            $dateFunc = DbHelper::date('c.create_datetime');
            $sql = "
                SELECT 
                    {$dateFunc} as date,
                    COUNT(DISTINCT c.id) as count
                FROM calls c
                {$joinClause}
                {$byDateWhereClause}
                GROUP BY date
                ORDER BY date
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $byDate = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Average call duration (for closed calls)
            $durationWhere = [];
            if (isset($filters['date_from'])) {
                $durationWhere[] = "c.create_datetime >= :date_from";
            }
            if (isset($filters['date_to'])) {
                $durationWhere[] = "c.create_datetime <= :date_to";
            }
            if (isset($filters['agency_type'])) {
                $durationWhere[] = "ac.agency_type = :agency_type";
            }
            if (isset($filters['jurisdiction'])) {
                $durationWhere[] = "i.jurisdiction = :jurisdiction";
            }
            $durationWhere[] = "c.closed_flag = 1";
            $durationWhere[] = "c.close_datetime IS NOT NULL";
            $durationWhereClause = !empty($durationWhere) ? 'WHERE ' . implode(' AND ', $durationWhere) : '';
            
            $timestampDiff = DbHelper::timestampDiff('MINUTE', 'c.create_datetime', 'c.close_datetime');
            $sql = "
                SELECT 
                    AVG({$timestampDiff}) as avg_duration_minutes
                FROM calls c
                {$joinClause}
                {$durationWhereClause}
            ";
            $stmt = $this->db->prepare($sql);
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
            $filters = Request::filters([
                'date_from',
                'date_to',
                'unit_type',
                'jurisdiction'
            ]);

            $where = [];
            $params = [];

            // Date range filter
            if (isset($filters['date_from'])) {
                $where[] = "u.assigned_datetime >= :date_from";
                $params[':date_from'] = $filters['date_from'];
            }

            if (isset($filters['date_to'])) {
                $where[] = "u.assigned_datetime <= :date_to";
                $params[':date_to'] = $filters['date_to'];
            }

            // Unit type filter
            if (isset($filters['unit_type'])) {
                $where[] = "u.unit_type = :unit_type";
                $params[':unit_type'] = $filters['unit_type'];
            }

            // Jurisdiction filter
            if (isset($filters['jurisdiction'])) {
                $where[] = "u.jurisdiction = :jurisdiction";
                $params[':jurisdiction'] = $filters['jurisdiction'];
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

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
            $responseDiff = DbHelper::timestampDiff('MINUTE', 'u.dispatch_datetime', 'u.arrive_datetime');
            $sql = "
                SELECT 
                    AVG({$responseDiff}) as avg_response_minutes
                FROM units u
                {$whereClause}
                AND u.dispatch_datetime IS NOT NULL 
                AND u.arrive_datetime IS NOT NULL
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $avgResponseTime = (float)$stmt->fetchColumn();

            // Average enroute time (dispatch to enroute)
            $enrouteDiff = DbHelper::timestampDiff('SECOND', 'u.dispatch_datetime', 'u.enroute_datetime');
            $sql = "
                SELECT 
                    AVG({$enrouteDiff}) as avg_enroute_seconds
                FROM units u
                {$whereClause}
                AND u.dispatch_datetime IS NOT NULL 
                AND u.enroute_datetime IS NOT NULL
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $avgEnrouteTime = (float)$stmt->fetchColumn();

            // Average on-scene time (arrive to clear)
            $onSceneDiff = DbHelper::timestampDiff('MINUTE', 'u.arrive_datetime', 'u.clear_datetime');
            $sql = "
                SELECT 
                    AVG({$onSceneDiff}) as avg_onscene_minutes
                FROM units u
                {$whereClause}
                AND u.arrive_datetime IS NOT NULL 
                AND u.clear_datetime IS NOT NULL
            ";
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
            $filters = Request::filters([
                'date_from',
                'date_to',
                'agency_type',
                'unit_type',
                'jurisdiction',
                'priority'
            ]);

            $where = [];
            $params = [];
            $joins = [];

            // Date range filter
            if (isset($filters['date_from'])) {
                $where[] = "u.assigned_datetime >= :date_from";
                $params[':date_from'] = $filters['date_from'];
            }

            if (isset($filters['date_to'])) {
                $where[] = "u.assigned_datetime <= :date_to";
                $params[':date_to'] = $filters['date_to'];
            }

            // Agency type filter
            if (isset($filters['agency_type'])) {
                $joins['agency_contexts'] = true;
                $where[] = "ac.agency_type = :agency_type";
                $params[':agency_type'] = $filters['agency_type'];
            }

            // Priority filter
            if (isset($filters['priority'])) {
                $joins['agency_contexts'] = true;
                $where[] = "ac.priority LIKE :priority";
                $params[':priority'] = '%' . $filters['priority'] . '%';
            }

            // Unit type filter
            if (isset($filters['unit_type'])) {
                $where[] = "u.unit_type = :unit_type";
                $params[':unit_type'] = $filters['unit_type'];
            }

            // Jurisdiction filter
            if (isset($filters['jurisdiction'])) {
                $where[] = "u.jurisdiction = :jurisdiction";
                $params[':jurisdiction'] = $filters['jurisdiction'];
            }

            // Build JOIN clause
            $joinClause = "INNER JOIN calls c ON u.call_id = c.id";
            if (isset($joins['agency_contexts'])) {
                $joinClause .= " INNER JOIN agency_contexts ac ON c.id = ac.call_id";
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // Overall response time statistics (dispatch to arrive)
            $responseMinutes = DbHelper::timestampDiff('MINUTE', 'u.dispatch_datetime', 'u.arrive_datetime');
            $sql = "
                SELECT 
                    COUNT(*) as total_responses,
                    AVG({$responseMinutes}) as avg_minutes,
                    MIN({$responseMinutes}) as min_minutes,
                    MAX({$responseMinutes}) as max_minutes,
                    STDDEV({$responseMinutes}) as stddev_minutes
                FROM units u
                {$joinClause}
                {$whereClause}
                AND u.dispatch_datetime IS NOT NULL 
                AND u.arrive_datetime IS NOT NULL
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $overall = $stmt->fetch();

            // Response times by unit type
            $sql = "
                SELECT 
                    u.unit_type,
                    COUNT(*) as count,
                    AVG({$responseMinutes}) as avg_minutes
                FROM units u
                {$joinClause}
                {$whereClause}
                AND u.dispatch_datetime IS NOT NULL 
                AND u.arrive_datetime IS NOT NULL
                GROUP BY u.unit_type
                ORDER BY avg_minutes ASC
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $byUnitType = $stmt->fetchAll();

            // Response times by jurisdiction
            $sql = "
                SELECT 
                    u.jurisdiction,
                    COUNT(*) as count,
                    AVG({$responseMinutes}) as avg_minutes
                FROM units u
                {$joinClause}
                {$whereClause}
                AND u.dispatch_datetime IS NOT NULL 
                AND u.arrive_datetime IS NOT NULL
                GROUP BY u.jurisdiction
                ORDER BY avg_minutes ASC
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $byJurisdiction = $stmt->fetchAll();

            // Response time percentiles (90th percentile)
            $sql = "
                SELECT 
                    {$responseMinutes} as response_minutes
                FROM units u
                {$joinClause}
                {$whereClause}
                AND u.dispatch_datetime IS NOT NULL 
                AND u.arrive_datetime IS NOT NULL
                ORDER BY response_minutes
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $responseTimes = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $percentiles = [];
            if (count($responseTimes) > 0) {
                $percentiles = [
                    '50th' => $this->calculatePercentile($responseTimes, 50),
                    '75th' => $this->calculatePercentile($responseTimes, 75),
                    '90th' => $this->calculatePercentile($responseTimes, 90),
                    '95th' => $this->calculatePercentile($responseTimes, 95)
                ];
            }

            // Breakdown of response time components
            $callToAssigned = DbHelper::timestampDiff('SECOND', 'c.create_datetime', 'u.assigned_datetime');
            $assignedToDispatch = DbHelper::timestampDiff('SECOND', 'u.assigned_datetime', 'u.dispatch_datetime');
            $dispatchToEnroute = DbHelper::timestampDiff('SECOND', 'u.dispatch_datetime', 'u.enroute_datetime');
            $enrouteToArrive = DbHelper::timestampDiff('MINUTE', 'u.enroute_datetime', 'u.arrive_datetime');
            
            $sql = "
                SELECT 
                    AVG({$callToAssigned}) as avg_call_to_assigned_seconds,
                    AVG({$assignedToDispatch}) as avg_assigned_to_dispatch_seconds,
                    AVG({$dispatchToEnroute}) as avg_dispatch_to_enroute_seconds,
                    AVG({$enrouteToArrive}) as avg_enroute_to_arrive_minutes
                FROM units u
                {$joinClause}
                {$whereClause}
                AND u.assigned_datetime IS NOT NULL
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $breakdown = $stmt->fetch();

            Response::success([
                'overall' => [
                    'total_responses' => (int)$overall['total_responses'],
                    'average_minutes' => round((float)$overall['avg_minutes'], 2),
                    'min_minutes' => round((float)$overall['min_minutes'], 2),
                    'max_minutes' => round((float)$overall['max_minutes'], 2),
                    'stddev_minutes' => round((float)$overall['stddev_minutes'], 2)
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
                    'call_to_assigned_seconds' => round((float)$breakdown['avg_call_to_assigned_seconds'], 2),
                    'assigned_to_dispatch_seconds' => round((float)$breakdown['avg_assigned_to_dispatch_seconds'], 2),
                    'dispatch_to_enroute_seconds' => round((float)$breakdown['avg_dispatch_to_enroute_seconds'], 2),
                    'enroute_to_arrive_minutes' => round((float)$breakdown['avg_enroute_to_arrive_minutes'], 2)
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
