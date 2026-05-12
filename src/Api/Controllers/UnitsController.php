<?php

declare(strict_types=1);

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
            $criteria = \NwsCad\Api\Filtering\FilterCriteria::fromQuery(
                $_GET,
                \NwsCad\Api\Filtering\FilterRegistry::for('units')
            );
        } catch (\NwsCad\Api\Filtering\InvalidFilterException $e) {
            Response::error($e->getMessage(), 400);
            return;
        }

        try {
            $pagination = Request::pagination();
            $sorting    = Request::sorting('assigned_datetime', 'desc');
            $allowedSort = ['unit_number', 'unit_type', 'assigned_datetime', 'clear_datetime'];
            $sortField   = in_array($sorting['sort'], $allowedSort, true) ? $sorting['sort'] : 'assigned_datetime';

            $builder = new \NwsCad\Api\Filtering\FilterSqlBuilder();
            $sql     = $builder->build(
                $criteria,
                new \NwsCad\Api\Filtering\FilterContext('units', ['units'], unitsBase: true)
            );

            $joinsStr = implode(' ', $sql->joins);

            $countSql  = 'SELECT COUNT(DISTINCT units.id) FROM units ' . $joinsStr . ' ' . $sql->whereClause;
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($sql->params);
            $total = (int) $countStmt->fetchColumn();

            $offset = ($pagination['page'] - 1) * $pagination['per_page'];

            // Always join calls for the SELECT columns; skip if already in joins from builder
            $callsJoin = str_contains($joinsStr, 'LEFT JOIN calls') ? '' : 'LEFT JOIN calls ON calls.id = units.call_id ';

            $listSql = 'SELECT DISTINCT units.*, calls.call_number, calls.nature_of_call, calls.create_datetime AS call_create_datetime '
                . 'FROM units '
                . $callsJoin
                . $joinsStr . ' '
                . $sql->whereClause
                . " ORDER BY units.{$sortField} {$sorting['order']}"
                . ' LIMIT :limit OFFSET :offset';

            $stmt = $this->db->prepare($listSql);
            foreach ($sql->params as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':limit',  $pagination['per_page'], PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            Response::success([
                'items'      => $stmt->fetchAll(),
                'pagination' => [
                    'total'        => $total,
                    'per_page'     => $pagination['per_page'],
                    'current_page' => $pagination['page'],
                    'total_pages'  => $pagination['per_page'] > 0 ? (int) ceil($total / $pagination['per_page']) : 0,
                ],
                'filters'    => $criteria->toArray(),
            ]);
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
