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
 * Calls Controller
 * Handles all call-related API endpoints
 */
class CallsController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * List calls with pagination, filtering, and sorting
     * GET /api/calls
     */
    public function index(): void
    {
        try {
            $criteria = \NwsCad\Api\Filtering\FilterCriteria::fromQuery(
                $_GET,
                \NwsCad\Api\Filtering\FilterRegistry::for('calls')
            );
        } catch (\NwsCad\Api\Filtering\InvalidFilterException $e) {
            Response::error($e->getMessage(), 400);
            return;
        }

        try {
            $pagination = Request::pagination();
            $sorting    = Request::sorting('create_datetime', 'desc');

            $allowedSort = ['create_datetime', 'close_datetime', 'call_number'];
            $sortField   = in_array($sorting['sort'], $allowedSort, true) ? $sorting['sort'] : 'create_datetime';

            // We always pull location columns for the response DTO (map markers, table
            // address column, "zoom to map" button), so declare locations as already-joined
            // to keep FilterSqlBuilder from emitting a duplicate JOIN when a location-
            // targeting filter (city/beat/area/ori/location) is active.
            $builder = new \NwsCad\Api\Filtering\FilterSqlBuilder();
            $sql     = $builder->build(
                $criteria,
                new \NwsCad\Api\Filtering\FilterContext('calls', ['calls', 'locations'])
            );

            $locationsJoin = 'LEFT JOIN locations ON locations.call_id = calls.id';

            // Count
            $countSql  = 'SELECT COUNT(DISTINCT calls.id) FROM calls '
                . $locationsJoin . ' '
                . implode(' ', $sql->joins) . ' '
                . $sql->whereClause;
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($sql->params);
            $total = (int) $countStmt->fetchColumn();

            // Page
            $offset  = ($pagination['page'] - 1) * $pagination['per_page'];
            $listSql = 'SELECT DISTINCT calls.*, '
                . 'locations.full_address, locations.city, locations.state, '
                . 'locations.latitude_y, locations.longitude_x '
                . 'FROM calls '
                . $locationsJoin . ' '
                . implode(' ', $sql->joins) . ' '
                . $sql->whereClause
                . " ORDER BY calls.{$sortField} {$sorting['order']}"
                . ' LIMIT :limit OFFSET :offset';
            $stmt = $this->db->prepare($listSql);
            foreach ($sql->params as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            $related = !empty($rows)
                ? $this->getRelatedDataBatch(array_column($rows, 'id'))
                : [];

            $items = array_map(function (array $row) use ($related) {
                $id  = (int) $row['id'];
                $lat = $row['latitude_y']  ?? null;
                $lng = $row['longitude_x'] ?? null;
                return [
                    'id'              => $id,
                    'call_id'         => (int) $row['call_id'],
                    'call_number'     => $row['call_number'],
                    'call_source'     => $row['call_source'],
                    'caller'          => [
                        'name'  => $row['caller_name'],
                        'phone' => $row['caller_phone'],
                    ],
                    'nature_of_call'  => $row['nature_of_call'],
                    'create_datetime' => $row['create_datetime'],
                    'close_datetime'  => $row['close_datetime'],
                    'created_by'      => $row['created_by'],
                    'closed_flag'     => (bool) $row['closed_flag'],
                    'canceled_flag'   => (bool) $row['canceled_flag'],
                    'alarm_level'     => $row['alarm_level'] !== null ? (int) $row['alarm_level'] : null,
                    'emd_code'        => $row['emd_code'],
                    'location'        => [
                        'address'     => $row['full_address'] ?? null,
                        'city'        => $row['city']         ?? null,
                        'state'       => $row['state']        ?? null,
                        'coordinates' => ($lat !== null && $lng !== null && $lat !== '' && $lng !== '')
                            ? ['lat' => (float) $lat, 'lng' => (float) $lng]
                            : null,
                    ],
                    'agency_types'     => $related['agency_types'][$id]     ?? [],
                    'call_types'       => $related['call_types'][$id]       ?? [],
                    'jurisdictions'    => $related['jurisdictions'][$id]    ?? [],
                    'priorities'       => $related['priorities'][$id]       ?? [],
                    'statuses'         => $related['statuses'][$id]         ?? [],
                    'incident_numbers' => $related['incident_numbers'][$id] ?? [],
                    'unit_count'       => $related['unit_counts'][$id]      ?? 0,
                ];
            }, $rows);

            Response::success([
                'items'      => $items,
                'pagination' => [
                    'total'        => $total,
                    'per_page'     => $pagination['per_page'],
                    'current_page' => $pagination['page'],
                    'total_pages'  => $pagination['per_page'] > 0 ? (int) ceil($total / $pagination['per_page']) : 0,
                ],
                'filters'    => $criteria->toArray(),
            ]);
        } catch (Exception $e) {
            Response::error('Failed to retrieve calls: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get single call details with all related data
     * GET /api/calls/:id
     */
    public function show(int $id): void
    {
        try {
            // Get call details
            $sql = "
                SELECT 
                    c.*,
                    l.full_address,
                    l.house_number,
                    l.street_name,
                    l.street_type,
                    l.city,
                    l.state,
                    l.zip,
                    l.prefix_directional,
                    l.common_name,
                    l.latitude_y,
                    l.longitude_x,
                    l.nearest_cross_streets,
                    l.police_beat,
                    l.ems_district,
                    l.fire_quadrant
                FROM calls c
                LEFT JOIN locations l ON c.id = l.call_id
                WHERE c.id = :id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            $call = $stmt->fetch();

            if (!$call) {
                Response::notFound(['message' => 'Call not found']);
                return;
            }

            // Get agency contexts
            $sql = "SELECT * FROM agency_contexts WHERE call_id = :call_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':call_id' => $call['id']]);
            $agencyContexts = $stmt->fetchAll();

            // Get incidents
            $sql = "SELECT * FROM incidents WHERE call_id = :call_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':call_id' => $call['id']]);
            $incidents = $stmt->fetchAll();

            // Get unit count
            $sql = "SELECT COUNT(*) FROM units WHERE call_id = :call_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':call_id' => $call['id']]);
            $unitCount = (int)$stmt->fetchColumn();

            // Get narrative count
            $sql = "SELECT COUNT(*) FROM narratives WHERE call_id = :call_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':call_id' => $call['id']]);
            $narrativeCount = (int)$stmt->fetchColumn();

            // Get person count
            $sql = "SELECT COUNT(*) FROM persons WHERE call_id = :call_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':call_id' => $call['id']]);
            $personCount = (int)$stmt->fetchColumn();

            // Format response
            $response = [
                'id' => (int)$call['id'],
                'call_id' => (int)$call['call_id'],
                'call_number' => $call['call_number'],
                'call_source' => $call['call_source'],
                'caller' => [
                    'name' => $call['caller_name'],
                    'phone' => $call['caller_phone']
                ],
                'nature_of_call' => $call['nature_of_call'],
                'create_datetime' => $call['create_datetime'],
                'close_datetime' => $call['close_datetime'],
                'created_by' => $call['created_by'],
                'closed_flag' => (bool)$call['closed_flag'],
                'canceled_flag' => (bool)$call['canceled_flag'],
                'alarm_level' => $call['alarm_level'] ? (int)$call['alarm_level'] : null,
                'emd_code' => $call['emd_code'],
                'location' => [
                    'full_address' => $call['full_address'],
                    'house_number' => $call['house_number'],
                    'street_name' => $call['street_name'],
                    'street_type' => $call['street_type'],
                    'city' => $call['city'],
                    'state' => $call['state'],
                    'zip' => $call['zip'],
                    'prefix_directional' => $call['prefix_directional'],
                    'common_name' => $call['common_name'],
                    'coordinates' => $call['latitude_y'] && $call['longitude_x'] ? [
                        'lat' => (float)$call['latitude_y'],
                        'lng' => (float)$call['longitude_x']
                    ] : null,
                    'nearest_cross_streets' => $call['nearest_cross_streets'],
                    'police_beat' => $call['police_beat'],
                    'ems_district' => $call['ems_district'],
                    'fire_quadrant' => $call['fire_quadrant']
                ],
                'agency_contexts' => $agencyContexts,
                'incidents' => $incidents,
                'counts' => [
                    'units' => $unitCount,
                    'narratives' => $narrativeCount,
                    'persons' => $personCount
                ]
            ];

            Response::success($response);
        } catch (Exception $e) {
            Response::error('Failed to retrieve call: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get units for a call
     * GET /api/calls/:id/units
     */
    public function units(int $id): void
    {
        try {
            // Verify call exists
            $stmt = $this->db->prepare("SELECT id FROM calls WHERE id = :id");
            $stmt->execute([':id' => $id]);
            if (!$stmt->fetch()) {
                Response::notFound(['message' => 'Call not found']);
                return;
            }

            // Get units with personnel count
            $sql = "
                SELECT 
                    u.*,
                    COUNT(DISTINCT up.id) as personnel_count,
                    COUNT(DISTINCT ul.id) as log_count
                FROM units u
                LEFT JOIN unit_personnel up ON u.id = up.unit_id
                LEFT JOIN unit_logs ul ON u.id = ul.unit_id
                WHERE u.call_id = :call_id
                GROUP BY u.id
                ORDER BY u.is_primary DESC, u.assigned_datetime ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':call_id' => $id]);
            $units = $stmt->fetchAll();

            $formattedUnits = array_map(function ($unit) {
                return [
                    'id' => (int)$unit['id'],
                    'unit_number' => $unit['unit_number'],
                    'unit_type' => $unit['unit_type'],
                    'is_primary' => (bool)$unit['is_primary'],
                    'jurisdiction' => $unit['jurisdiction'],
                    'timestamps' => [
                        'assigned' => $unit['assigned_datetime'],
                        'dispatch' => $unit['dispatch_datetime'],
                        'enroute' => $unit['enroute_datetime'],
                        'arrive' => $unit['arrive_datetime'],
                        'staged' => $unit['staged_datetime'],
                        'at_patient' => $unit['at_patient_datetime'],
                        'transport' => $unit['transport_datetime'],
                        'at_hospital' => $unit['at_hospital_datetime'],
                        'depart_hospital' => $unit['depart_hospital_datetime'],
                        'clear' => $unit['clear_datetime']
                    ],
                    'personnel_count' => (int)$unit['personnel_count'],
                    'log_count' => (int)$unit['log_count']
                ];
            }, $units);

            Response::success($formattedUnits);
        } catch (Exception $e) {
            Response::error('Failed to retrieve units: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get narratives for a call
     * GET /api/calls/:id/narratives
     */
    public function narratives(int $id): void
    {
        try {
            // Verify call exists
            $stmt = $this->db->prepare("SELECT id FROM calls WHERE id = :id");
            $stmt->execute([':id' => $id]);
            if (!$stmt->fetch()) {
                Response::notFound(['message' => 'Call not found']);
                return;
            }

            $pagination = Request::pagination();
            $offset = ($pagination['page'] - 1) * $pagination['per_page'];

            // Get total count
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM narratives WHERE call_id = :call_id");
            $countStmt->execute([':call_id' => $id]);
            $total = (int)$countStmt->fetchColumn();

            // Get narratives
            $sql = "
                SELECT *
                FROM narratives
                WHERE call_id = :call_id
                ORDER BY create_datetime ASC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':call_id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $narratives = $stmt->fetchAll();

            Response::paginated($narratives, $total, $pagination['page'], $pagination['per_page']);
        } catch (Exception $e) {
            Response::error('Failed to retrieve narratives: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get persons for a call
     * GET /api/calls/:id/persons
     */
    public function persons(int $id): void
    {
        try {
            // Verify call exists
            $stmt = $this->db->prepare("SELECT id FROM calls WHERE id = :id");
            $stmt->execute([':id' => $id]);
            if (!$stmt->fetch()) {
                Response::notFound(['message' => 'Call not found']);
                return;
            }

            // Get persons
            $sql = "
                SELECT *
                FROM persons
                WHERE call_id = :call_id
                ORDER BY id ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':call_id' => $id]);
            $persons = $stmt->fetchAll();

            Response::success($persons);
        } catch (Exception $e) {
            Response::error('Failed to retrieve persons: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get location for a call
     * GET /api/calls/:id/location
     */
    public function location(int $id): void
    {
        try {
            $sql = "
                SELECT l.*
                FROM locations l
                WHERE l.call_id = :call_id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':call_id' => $id]);
            $location = $stmt->fetch();

            if (!$location) {
                Response::notFound(['message' => 'Location not found for this call']);
                return;
            }

            Response::success($location);
        } catch (Exception $e) {
            Response::error('Failed to retrieve location: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get incidents for a call
     * GET /api/calls/:id/incidents
     */
    public function incidents(int $id): void
    {
        try {
            // Verify call exists
            $stmt = $this->db->prepare("SELECT id FROM calls WHERE id = :id");
            $stmt->execute([':id' => $id]);
            if (!$stmt->fetch()) {
                Response::notFound(['message' => 'Call not found']);
                return;
            }

            // Get incidents
            $sql = "
                SELECT *
                FROM incidents
                WHERE call_id = :call_id
                ORDER BY create_datetime ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':call_id' => $id]);
            $incidents = $stmt->fetchAll();

            Response::success($incidents);
        } catch (Exception $e) {
            Response::error('Failed to retrieve incidents: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get dispositions for a call
     * GET /api/calls/:id/dispositions
     */
    public function dispositions(int $id): void
    {
        try {
            // Verify call exists
            $stmt = $this->db->prepare("SELECT id FROM calls WHERE id = :id");
            $stmt->execute([':id' => $id]);
            if (!$stmt->fetch()) {
                Response::notFound(['message' => 'Call not found']);
                return;
            }

            // Get call dispositions
            $sql = "
                SELECT *
                FROM call_dispositions
                WHERE call_id = :call_id
                ORDER BY id ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':call_id' => $id]);
            $dispositions = $stmt->fetchAll();

            Response::success($dispositions);
        } catch (Exception $e) {
            Response::error('Failed to retrieve dispositions: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Fetch related data for multiple calls in batch
     * PERFORMANCE OPTIMIZATION: Replaces slow GROUP_CONCAT with efficient batch queries
     * 
     * @param array $callIds Array of call IDs
     * @return array Associative array with related data indexed by call_id
     */
    private function getRelatedDataBatch(array $callIds): array
    {
        if (empty($callIds)) {
            return [
                'agency_types' => [],
                'call_types' => [],
                'jurisdictions' => [],
                'priorities' => [],
                'statuses' => [],
                'incident_numbers' => [],
                'unit_counts' => []
            ];
        }

        $placeholders = implode(',', array_fill(0, count($callIds), '?'));
        
        $result = [
            'agency_types' => [],
            'call_types' => [],
            'jurisdictions' => [],
            'priorities' => [],
            'statuses' => [],
            'incident_numbers' => [],
            'unit_counts' => []
        ];

        // Initialize arrays for each call ID
        foreach ($callIds as $callId) {
            $result['agency_types'][$callId] = [];
            $result['call_types'][$callId] = [];
            $result['jurisdictions'][$callId] = [];
            $result['priorities'][$callId] = [];
            $result['statuses'][$callId] = [];
            $result['incident_numbers'][$callId] = [];
            $result['unit_counts'][$callId] = 0;
        }

        // Get agency contexts data
        $sql = "SELECT call_id, agency_type, call_type, priority, status 
                FROM agency_contexts 
                WHERE call_id IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($callIds);
        
        while ($row = $stmt->fetch()) {
            $callId = $row['call_id'];
            if ($row['agency_type'] && !in_array($row['agency_type'], $result['agency_types'][$callId])) {
                $result['agency_types'][$callId][] = $row['agency_type'];
            }
            if ($row['call_type'] && !in_array($row['call_type'], $result['call_types'][$callId])) {
                $result['call_types'][$callId][] = $row['call_type'];
            }
            if ($row['priority'] && !in_array($row['priority'], $result['priorities'][$callId])) {
                $result['priorities'][$callId][] = $row['priority'];
            }
            if ($row['status'] && !in_array($row['status'], $result['statuses'][$callId])) {
                $result['statuses'][$callId][] = $row['status'];
            }
        }

        // Get incidents data
        $sql = "SELECT call_id, jurisdiction, incident_number 
                FROM incidents 
                WHERE call_id IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($callIds);
        
        while ($row = $stmt->fetch()) {
            $callId = $row['call_id'];
            if ($row['jurisdiction'] && !in_array($row['jurisdiction'], $result['jurisdictions'][$callId])) {
                $result['jurisdictions'][$callId][] = $row['jurisdiction'];
            }
            if ($row['incident_number'] && !in_array($row['incident_number'], $result['incident_numbers'][$callId])) {
                $result['incident_numbers'][$callId][] = $row['incident_number'];
            }
        }

        // Get unit counts
        $sql = "SELECT call_id, COUNT(DISTINCT id) as unit_count 
                FROM units 
                WHERE call_id IN ($placeholders) 
                GROUP BY call_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($callIds);
        
        while ($row = $stmt->fetch()) {
            $result['unit_counts'][$row['call_id']] = (int)$row['unit_count'];
        }

        return $result;
    }
}
