<?php

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
            $pagination = Request::pagination();
            $sorting = Request::sorting('create_datetime', 'desc');
            $filters = Request::filters([
                'status',
                'agency_type',
                'closed_flag',
                'canceled_flag',
                'call_number',
                'created_by',
                'date_from',
                'date_to',
                'nature_of_call'
            ]);

            // Build WHERE clause
            $where = [];
            $params = [];

            if (isset($filters['status'])) {
                $where[] = "ac.status = :status";
                $params[':status'] = $filters['status'];
            }

            if (isset($filters['agency_type'])) {
                $where[] = "ac.agency_type = :agency_type";
                $params[':agency_type'] = $filters['agency_type'];
            }

            if (isset($filters['closed_flag'])) {
                $where[] = "c.closed_flag = :closed_flag";
                $params[':closed_flag'] = $filters['closed_flag'] === 'true' ? 1 : 0;
            }

            if (isset($filters['canceled_flag'])) {
                $where[] = "c.canceled_flag = :canceled_flag";
                $params[':canceled_flag'] = $filters['canceled_flag'] === 'true' ? 1 : 0;
            }

            if (isset($filters['call_number'])) {
                $where[] = "c.call_number = :call_number";
                $params[':call_number'] = $filters['call_number'];
            }

            if (isset($filters['created_by'])) {
                $where[] = "c.created_by = :created_by";
                $params[':created_by'] = $filters['created_by'];
            }

            if (isset($filters['date_from'])) {
                $where[] = "c.create_datetime >= :date_from";
                $params[':date_from'] = $filters['date_from'];
            }

            if (isset($filters['date_to'])) {
                $where[] = "c.create_datetime <= :date_to";
                $params[':date_to'] = $filters['date_to'];
            }

            if (isset($filters['nature_of_call'])) {
                $where[] = "c.nature_of_call LIKE :nature_of_call";
                $params[':nature_of_call'] = '%' . $filters['nature_of_call'] . '%';
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // Validate sort field
            $allowedSortFields = ['call_id', 'call_number', 'create_datetime', 'close_datetime', 'nature_of_call'];
            $sortField = in_array($sorting['sort'], $allowedSortFields) ? $sorting['sort'] : 'create_datetime';

            // Get total count
            $countSql = "
                SELECT COUNT(DISTINCT c.id)
                FROM calls c
                LEFT JOIN agency_contexts ac ON c.id = ac.call_id
                {$whereClause}
            ";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Get paginated results
            $offset = ($pagination['page'] - 1) * $pagination['per_page'];
            $agencyTypesAgg = DbHelper::groupConcat('ac.agency_type', ',', true);
            $callTypesAgg = DbHelper::groupConcat('ac.call_type', ',', true);
            
            $sql = "
                SELECT 
                    c.id,
                    c.call_id,
                    c.call_number,
                    c.call_source,
                    c.caller_name,
                    c.caller_phone,
                    c.nature_of_call,
                    c.create_datetime,
                    c.close_datetime,
                    c.created_by,
                    c.closed_flag,
                    c.canceled_flag,
                    c.alarm_level,
                    c.emd_code,
                    l.full_address,
                    l.city,
                    l.state,
                    l.latitude_y,
                    l.longitude_x,
                    {$agencyTypesAgg} as agency_types,
                    {$callTypesAgg} as call_types,
                    COUNT(DISTINCT u.id) as unit_count
                FROM calls c
                LEFT JOIN locations l ON c.id = l.call_id
                LEFT JOIN agency_contexts ac ON c.id = ac.call_id
                LEFT JOIN units u ON c.id = u.call_id
                {$whereClause}
                GROUP BY c.id
                ORDER BY c.{$sortField} {$sorting['order']}
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $calls = $stmt->fetchAll();

            // Format response
            $formattedCalls = array_map(function ($call) {
                return [
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
                        'address' => $call['full_address'],
                        'city' => $call['city'],
                        'state' => $call['state'],
                        'coordinates' => $call['latitude_y'] && $call['longitude_x'] ? [
                            'lat' => (float)$call['latitude_y'],
                            'lng' => (float)$call['longitude_x']
                        ] : null
                    ],
                    'agency_types' => $call['agency_types'] ? explode(',', $call['agency_types']) : [],
                    'call_types' => $call['call_types'] ? explode(',', $call['call_types']) : [],
                    'unit_count' => (int)$call['unit_count']
                ];
            }, $calls);

            Response::paginated($formattedCalls, $total, $pagination['page'], $pagination['per_page']);
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
                'closed_by' => $call['closed_by'],
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
}
