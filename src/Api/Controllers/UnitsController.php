<?php

namespace NwsCad\Api\Controllers;

use NwsCad\Database;
use NwsCad\Api\Request;
use NwsCad\Api\Response;
use PDO;
use Exception;

/**
 * Units Controller
 * Handles all unit-related API endpoints
 */
class UnitsController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * List units with filtering
     * GET /api/units
     */
    public function index(): void
    {
        try {
            $pagination = Request::pagination();
            $sorting = Request::sorting('assigned_datetime', 'desc');
            $filters = Request::filters([
                'unit_number',
                'unit_type',
                'jurisdiction',
                'is_primary',
                'call_id',
                'date_from',
                'date_to'
            ]);

            // Build WHERE clause
            $where = [];
            $params = [];

            if (isset($filters['unit_number'])) {
                $where[] = "u.unit_number = :unit_number";
                $params[':unit_number'] = $filters['unit_number'];
            }

            if (isset($filters['unit_type'])) {
                $where[] = "u.unit_type = :unit_type";
                $params[':unit_type'] = $filters['unit_type'];
            }

            if (isset($filters['jurisdiction'])) {
                $where[] = "u.jurisdiction = :jurisdiction";
                $params[':jurisdiction'] = $filters['jurisdiction'];
            }

            if (isset($filters['is_primary'])) {
                $where[] = "u.is_primary = :is_primary";
                $params[':is_primary'] = $filters['is_primary'] === 'true' ? 1 : 0;
            }

            if (isset($filters['call_id'])) {
                $where[] = "u.call_id = :call_id";
                $params[':call_id'] = $filters['call_id'];
            }

            if (isset($filters['date_from'])) {
                $where[] = "u.assigned_datetime >= :date_from";
                $params[':date_from'] = $filters['date_from'];
            }

            if (isset($filters['date_to'])) {
                $where[] = "u.assigned_datetime <= :date_to";
                $params[':date_to'] = $filters['date_to'];
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // Validate sort field
            $allowedSortFields = ['unit_number', 'unit_type', 'assigned_datetime', 'clear_datetime'];
            $sortField = in_array($sorting['sort'], $allowedSortFields) ? $sorting['sort'] : 'assigned_datetime';

            // Get total count
            $countSql = "SELECT COUNT(*) FROM units u {$whereClause}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Get paginated results
            $offset = ($pagination['page'] - 1) * $pagination['per_page'];
            $sql = "
                SELECT 
                    u.*,
                    c.call_number,
                    c.nature_of_call,
                    c.create_datetime as call_create_datetime,
                    (SELECT i.incident_number FROM incidents i WHERE i.call_id = u.call_id LIMIT 1) as incident_number,
                    (SELECT l.latitude_y FROM locations l WHERE l.call_id = u.call_id LIMIT 1) as latitude_y,
                    (SELECT l.longitude_x FROM locations l WHERE l.call_id = u.call_id LIMIT 1) as longitude_x,
                    (SELECT l.full_address FROM locations l WHERE l.call_id = u.call_id LIMIT 1) as full_address,
                    (SELECT l.city FROM locations l WHERE l.call_id = u.call_id LIMIT 1) as city,
                    (SELECT l.state FROM locations l WHERE l.call_id = u.call_id LIMIT 1) as state,
                    COUNT(DISTINCT up.id) as personnel_count,
                    COUNT(DISTINCT ul.id) as log_count
                FROM units u
                LEFT JOIN calls c ON u.call_id = c.id
                LEFT JOIN unit_personnel up ON u.id = up.unit_id
                LEFT JOIN unit_logs ul ON u.id = ul.unit_id
                {$whereClause}
                GROUP BY u.id, c.call_number, c.nature_of_call, c.create_datetime
                ORDER BY u.{$sortField} {$sorting['order']}
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $units = $stmt->fetchAll();

            // Format response
            $formattedUnits = array_map(function ($unit) {
                return [
                    'id' => (int)$unit['id'],
                    'call_id' => (int)$unit['call_id'],
                    'unit_number' => $unit['unit_number'],
                    'unit_type' => $unit['unit_type'],
                    'is_primary' => (bool)$unit['is_primary'],
                    'jurisdiction' => $unit['jurisdiction'],
                    'call' => [
                        'call_number' => $unit['call_number'],
                        'incident_number' => $unit['incident_number'],
                        'nature_of_call' => $unit['nature_of_call'],
                        'create_datetime' => $unit['call_create_datetime']
                    ],
                    'latitude_y' => $unit['latitude_y'] ? (float)$unit['latitude_y'] : null,
                    'longitude_x' => $unit['longitude_x'] ? (float)$unit['longitude_x'] : null,
                    'full_address' => $unit['full_address'],
                    'city' => $unit['city'],
                    'state' => $unit['state'],
                    'assigned_datetime' => $unit['assigned_datetime'],
                    'enroute_datetime' => $unit['enroute_datetime'],
                    'arrive_datetime' => $unit['arrive_datetime'],
                    'clear_datetime' => $unit['clear_datetime'],
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

            Response::paginated($formattedUnits, $total, $pagination['page'], $pagination['per_page']);
        } catch (Exception $e) {
            Response::error('Failed to retrieve units: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get single unit details
     * GET /api/units/:id
     */
    public function show(int $id): void
    {
        try {
            // Get unit details with call info
            $sql = "
                SELECT 
                    u.*,
                    c.call_id as call_call_id,
                    c.call_number,
                    c.nature_of_call,
                    c.create_datetime as call_create_datetime,
                    c.close_datetime as call_close_datetime,
                    c.caller_name,
                    (SELECT i.incident_number FROM incidents i WHERE i.call_id = u.call_id LIMIT 1) as incident_number,
                    l.full_address,
                    l.city,
                    l.state
                FROM units u
                LEFT JOIN calls c ON u.call_id = c.id
                LEFT JOIN locations l ON c.id = l.call_id
                WHERE u.id = :id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            $unit = $stmt->fetch();

            if (!$unit) {
                Response::notFound(['message' => 'Unit not found']);
                return;
            }

            // Get personnel count
            $sql = "SELECT COUNT(*) FROM unit_personnel WHERE unit_id = :unit_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':unit_id' => $unit['id']]);
            $personnelCount = (int)$stmt->fetchColumn();

            // Get log count
            $sql = "SELECT COUNT(*) FROM unit_logs WHERE unit_id = :unit_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':unit_id' => $unit['id']]);
            $logCount = (int)$stmt->fetchColumn();

            // Get disposition count
            $sql = "SELECT COUNT(*) FROM unit_dispositions WHERE unit_id = :unit_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':unit_id' => $unit['id']]);
            $dispositionCount = (int)$stmt->fetchColumn();

            // Format response
            $response = [
                'id' => (int)$unit['id'],
                'call_id' => (int)$unit['call_id'],
                'unit_number' => $unit['unit_number'],
                'unit_type' => $unit['unit_type'],
                'is_primary' => (bool)$unit['is_primary'],
                'jurisdiction' => $unit['jurisdiction'],
                'call' => [
                    'call_id' => (int)$unit['call_call_id'],
                    'call_number' => $unit['call_number'],
                    'incident_number' => $unit['incident_number'],
                    'nature_of_call' => $unit['nature_of_call'],
                    'create_datetime' => $unit['call_create_datetime'],
                    'close_datetime' => $unit['call_close_datetime'],
                    'caller_name' => $unit['caller_name'],
                    'location' => [
                        'address' => $unit['full_address'],
                        'city' => $unit['city'],
                        'state' => $unit['state']
                    ]
                ],
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
                'counts' => [
                    'personnel' => $personnelCount,
                    'logs' => $logCount,
                    'dispositions' => $dispositionCount
                ]
            ];

            Response::success($response);
        } catch (Exception $e) {
            Response::error('Failed to retrieve unit: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get unit logs/status history
     * GET /api/units/:id/logs
     */
    public function logs(int $id): void
    {
        try {
            // Verify unit exists
            $stmt = $this->db->prepare("SELECT id FROM units WHERE id = :id");
            $stmt->execute([':id' => $id]);
            if (!$stmt->fetch()) {
                Response::notFound(['message' => 'Unit not found']);
                return;
            }

            // Get unit logs
            $sql = "
                SELECT *
                FROM unit_logs
                WHERE unit_id = :unit_id
                ORDER BY log_datetime ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':unit_id' => $id]);
            $logs = $stmt->fetchAll();

            Response::success($logs);
        } catch (Exception $e) {
            Response::error('Failed to retrieve unit logs: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get unit personnel
     * GET /api/units/:id/personnel
     */
    public function personnel(int $id): void
    {
        try {
            // Verify unit exists
            $stmt = $this->db->prepare("SELECT id FROM units WHERE id = :id");
            $stmt->execute([':id' => $id]);
            if (!$stmt->fetch()) {
                Response::notFound(['message' => 'Unit not found']);
                return;
            }

            // Get personnel
            $sql = "
                SELECT *
                FROM unit_personnel
                WHERE unit_id = :unit_id
                ORDER BY is_primary_officer DESC, last_name ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':unit_id' => $id]);
            $personnel = $stmt->fetchAll();

            $formattedPersonnel = array_map(function ($person) {
                $fullName = trim($person['first_name'] . ' ' . 
                    ($person['middle_name'] ? $person['middle_name'] . ' ' : '') . 
                    $person['last_name']);
                    
                return [
                    'id' => (int)$person['id'],
                    'name' => [
                        'first' => $person['first_name'],
                        'middle' => $person['middle_name'],
                        'last' => $person['last_name'],
                        'full' => $fullName
                    ],
                    'id_number' => $person['id_number'],
                    'shield_number' => $person['shield_number'],
                    'jurisdiction' => $person['jurisdiction'],
                    'is_primary_officer' => (bool)$person['is_primary_officer']
                ];
            }, $personnel);

            Response::success($formattedPersonnel);
        } catch (Exception $e) {
            Response::error('Failed to retrieve unit personnel: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get unit dispositions
     * GET /api/units/:id/dispositions
     */
    public function dispositions(int $id): void
    {
        try {
            // Verify unit exists
            $stmt = $this->db->prepare("SELECT id FROM units WHERE id = :id");
            $stmt->execute([':id' => $id]);
            if (!$stmt->fetch()) {
                Response::notFound(['message' => 'Unit not found']);
                return;
            }

            // Get dispositions
            $sql = "
                SELECT *
                FROM unit_dispositions
                WHERE unit_id = :unit_id
                ORDER BY id ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':unit_id' => $id]);
            $dispositions = $stmt->fetchAll();

            Response::success($dispositions);
        } catch (Exception $e) {
            Response::error('Failed to retrieve unit dispositions: ' . $e->getMessage(), 500);
        }
    }
}
