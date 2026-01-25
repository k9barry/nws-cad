<?php

namespace NwsCad\Api\Controllers;

use NwsCad\Database;
use NwsCad\Api\Request;
use NwsCad\Api\Response;
use NwsCad\Api\DbHelper;
use PDO;
use Exception;

/**
 * Search Controller
 * Handles search endpoints across multiple criteria
 */
class SearchController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Search calls by various criteria
     * GET /api/search/calls
     */
    public function calls(): void
    {
        try {
            $pagination = Request::pagination();
            $search = Request::search();
            $filters = Request::filters([
                'call_number',
                'nature_of_call',
                'caller_name',
                'caller_phone',
                'address',
                'city',
                'agency_type',
                'call_type',
                'status',
                'unit_number',
                'incident_number',
                'person_name',
                'date_from',
                'date_to',
                'closed_flag',
                'canceled_flag'
            ]);

            // Build WHERE clause
            $where = [];
            $params = [];
            $joins = [];

            // General search across multiple fields
            if ($search) {
                $searchConditions = [
                    "c.call_number LIKE :search",
                    "c.nature_of_call LIKE :search",
                    "c.caller_name LIKE :search",
                    "c.caller_phone LIKE :search",
                    "l.full_address LIKE :search"
                ];
                $where[] = '(' . implode(' OR ', $searchConditions) . ')';
                $params[':search'] = '%' . $search . '%';
            }

            // Specific filters
            if (isset($filters['call_number'])) {
                $where[] = "c.call_number = :call_number";
                $params[':call_number'] = $filters['call_number'];
            }

            if (isset($filters['nature_of_call'])) {
                $where[] = "c.nature_of_call LIKE :nature_of_call";
                $params[':nature_of_call'] = '%' . $filters['nature_of_call'] . '%';
            }

            if (isset($filters['caller_name'])) {
                $where[] = "c.caller_name LIKE :caller_name";
                $params[':caller_name'] = '%' . $filters['caller_name'] . '%';
            }

            if (isset($filters['caller_phone'])) {
                $where[] = "c.caller_phone LIKE :caller_phone";
                $params[':caller_phone'] = '%' . $filters['caller_phone'] . '%';
            }

            if (isset($filters['address'])) {
                $where[] = "l.full_address LIKE :address";
                $params[':address'] = '%' . $filters['address'] . '%';
            }

            if (isset($filters['city'])) {
                $where[] = "l.city = :city";
                $params[':city'] = $filters['city'];
            }

            if (isset($filters['agency_type'])) {
                $joins['agency_contexts'] = true;
                $where[] = "ac.agency_type = :agency_type";
                $params[':agency_type'] = $filters['agency_type'];
            }

            if (isset($filters['call_type'])) {
                $joins['agency_contexts'] = true;
                $where[] = "ac.call_type = :call_type";
                $params[':call_type'] = $filters['call_type'];
            }

            if (isset($filters['status'])) {
                $joins['agency_contexts'] = true;
                $where[] = "ac.status = :status";
                $params[':status'] = $filters['status'];
            }

            if (isset($filters['unit_number'])) {
                $joins['units'] = true;
                $where[] = "u.unit_number = :unit_number";
                $params[':unit_number'] = $filters['unit_number'];
            }

            if (isset($filters['incident_number'])) {
                $joins['incidents'] = true;
                $where[] = "i.incident_number = :incident_number";
                $params[':incident_number'] = $filters['incident_number'];
            }

            if (isset($filters['person_name'])) {
                $joins['persons'] = true;
                $personNameConditions = [
                    "CONCAT(p.first_name, ' ', p.last_name) LIKE :person_name",
                    "CONCAT(p.first_name, ' ', p.middle_name, ' ', p.last_name) LIKE :person_name"
                ];
                $where[] = '(' . implode(' OR ', $personNameConditions) . ')';
                $params[':person_name'] = '%' . $filters['person_name'] . '%';
            }

            if (isset($filters['date_from'])) {
                $where[] = "c.create_datetime >= :date_from";
                $params[':date_from'] = $filters['date_from'];
            }

            if (isset($filters['date_to'])) {
                $where[] = "c.create_datetime <= :date_to";
                $params[':date_to'] = $filters['date_to'];
            }

            if (isset($filters['closed_flag'])) {
                $where[] = "c.closed_flag = :closed_flag";
                $params[':closed_flag'] = $filters['closed_flag'] === 'true' ? 1 : 0;
            }

            if (isset($filters['canceled_flag'])) {
                $where[] = "c.canceled_flag = :canceled_flag";
                $params[':canceled_flag'] = $filters['canceled_flag'] === 'true' ? 1 : 0;
            }

            // Build JOIN clauses
            $joinClause = '';
            if (isset($joins['agency_contexts'])) {
                $joinClause .= " LEFT JOIN agency_contexts ac ON c.id = ac.call_id";
            }
            if (isset($joins['units'])) {
                $joinClause .= " LEFT JOIN units u ON c.id = u.call_id";
            }
            if (isset($joins['incidents'])) {
                $joinClause .= " LEFT JOIN incidents i ON c.id = i.call_id";
            }
            if (isset($joins['persons'])) {
                $joinClause .= " LEFT JOIN persons p ON c.id = p.call_id";
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // Get total count
            $countSql = "
                SELECT COUNT(DISTINCT c.id)
                FROM calls c
                LEFT JOIN locations l ON c.id = l.call_id
                {$joinClause}
                {$whereClause}
            ";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Get paginated results
            $offset = ($pagination['page'] - 1) * $pagination['per_page'];
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
                    c.closed_flag,
                    c.canceled_flag,
                    l.full_address,
                    l.city,
                    l.state,
                    l.latitude_y,
                    l.longitude_x
                FROM calls c
                LEFT JOIN locations l ON c.id = l.call_id
                {$joinClause}
                {$whereClause}
                GROUP BY c.id
                ORDER BY c.create_datetime DESC
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
                    'closed_flag' => (bool)$call['closed_flag'],
                    'canceled_flag' => (bool)$call['canceled_flag'],
                    'location' => [
                        'address' => $call['full_address'],
                        'city' => $call['city'],
                        'state' => $call['state'],
                        'coordinates' => $call['latitude_y'] && $call['longitude_x'] ? [
                            'lat' => (float)$call['latitude_y'],
                            'lng' => (float)$call['longitude_x']
                        ] : null
                    ]
                ];
            }, $calls);

            Response::paginated($formattedCalls, $total, $pagination['page'], $pagination['per_page']);
        } catch (Exception $e) {
            Response::error('Search failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Search by location/address/coordinates
     * GET /api/search/location
     */
    public function location(): void
    {
        try {
            $pagination = Request::pagination();
            $filters = Request::filters([
                'address',
                'city',
                'state',
                'zip',
                'police_beat',
                'ems_district',
                'fire_quadrant',
                'lat',
                'lng',
                'radius',
                'date_from',
                'date_to'
            ]);

            $where = [];
            $params = [];

            // Address search
            if (isset($filters['address'])) {
                $where[] = "l.full_address LIKE :address";
                $params[':address'] = '%' . $filters['address'] . '%';
            }

            if (isset($filters['city'])) {
                $where[] = "l.city = :city";
                $params[':city'] = $filters['city'];
            }

            if (isset($filters['state'])) {
                $where[] = "l.state = :state";
                $params[':state'] = $filters['state'];
            }

            if (isset($filters['zip'])) {
                $where[] = "l.zip = :zip";
                $params[':zip'] = $filters['zip'];
            }

            // Response zones
            if (isset($filters['police_beat'])) {
                $where[] = "l.police_beat = :police_beat";
                $params[':police_beat'] = $filters['police_beat'];
            }

            if (isset($filters['ems_district'])) {
                $where[] = "l.ems_district = :ems_district";
                $params[':ems_district'] = $filters['ems_district'];
            }

            if (isset($filters['fire_quadrant'])) {
                $where[] = "l.fire_quadrant = :fire_quadrant";
                $params[':fire_quadrant'] = $filters['fire_quadrant'];
            }

            // Coordinate-based search (radius search)
            if (isset($filters['lat']) && isset($filters['lng'])) {
                $radius = isset($filters['radius']) ? (float)$filters['radius'] : 1.0; // default 1km
                $distanceFormula = DbHelper::haversineDistance('l.latitude_y', 'l.longitude_x');
                $where[] = "{$distanceFormula} <= :radius";
                $params[':lat'] = (float)$filters['lat'];
                $params[':lng'] = (float)$filters['lng'];
                $params[':radius'] = $radius;
            }

            // Date range
            if (isset($filters['date_from'])) {
                $where[] = "c.create_datetime >= :date_from";
                $params[':date_from'] = $filters['date_from'];
            }

            if (isset($filters['date_to'])) {
                $where[] = "c.create_datetime <= :date_to";
                $params[':date_to'] = $filters['date_to'];
            }

            if (empty($where)) {
                Response::error('At least one location search parameter is required', 400);
                return;
            }

            $whereClause = 'WHERE ' . implode(' AND ', $where);

            // Get total count
            $countSql = "
                SELECT COUNT(*)
                FROM locations l
                INNER JOIN calls c ON l.call_id = c.id
                {$whereClause}
            ";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Get paginated results
            $offset = ($pagination['page'] - 1) * $pagination['per_page'];
            $sql = "
                SELECT 
                    c.id,
                    c.call_id,
                    c.call_number,
                    c.nature_of_call,
                    c.create_datetime,
                    c.close_datetime,
                    l.*
                FROM locations l
                INNER JOIN calls c ON l.call_id = c.id
                {$whereClause}
                ORDER BY c.create_datetime DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $results = $stmt->fetchAll();

            // Format response
            $formattedResults = array_map(function ($row) {
                return [
                    'call' => [
                        'id' => (int)$row['id'],
                        'call_id' => (int)$row['call_id'],
                        'call_number' => $row['call_number'],
                        'nature_of_call' => $row['nature_of_call'],
                        'create_datetime' => $row['create_datetime'],
                        'close_datetime' => $row['close_datetime']
                    ],
                    'location' => [
                        'full_address' => $row['full_address'],
                        'house_number' => $row['house_number'],
                        'street_name' => $row['street_name'],
                        'street_type' => $row['street_type'],
                        'city' => $row['city'],
                        'state' => $row['state'],
                        'zip' => $row['zip'],
                        'common_name' => $row['common_name'],
                        'coordinates' => $row['latitude_y'] && $row['longitude_x'] ? [
                            'lat' => (float)$row['latitude_y'],
                            'lng' => (float)$row['longitude_x']
                        ] : null,
                        'police_beat' => $row['police_beat'],
                        'ems_district' => $row['ems_district'],
                        'fire_quadrant' => $row['fire_quadrant']
                    ]
                ];
            }, $results);

            Response::paginated($formattedResults, $total, $pagination['page'], $pagination['per_page']);
        } catch (Exception $e) {
            Response::error('Location search failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Search units
     * GET /api/search/units
     */
    public function units(): void
    {
        try {
            $pagination = Request::pagination();
            $search = Request::search();
            $filters = Request::filters([
                'unit_number',
                'unit_type',
                'jurisdiction',
                'personnel_name',
                'personnel_id',
                'shield_number',
                'date_from',
                'date_to'
            ]);

            $where = [];
            $params = [];
            $joins = [];

            // General search
            if ($search) {
                $where[] = "u.unit_number LIKE :search";
                $params[':search'] = '%' . $search . '%';
            }

            // Specific filters
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

            if (isset($filters['personnel_name'])) {
                $joins['personnel'] = true;
                $personnelNameConditions = [
                    "CONCAT(up.first_name, ' ', up.last_name) LIKE :personnel_name",
                    "CONCAT(up.first_name, ' ', up.middle_name, ' ', up.last_name) LIKE :personnel_name"
                ];
                $where[] = '(' . implode(' OR ', $personnelNameConditions) . ')';
                $params[':personnel_name'] = '%' . $filters['personnel_name'] . '%';
            }

            if (isset($filters['personnel_id'])) {
                $joins['personnel'] = true;
                $where[] = "up.id_number = :personnel_id";
                $params[':personnel_id'] = $filters['personnel_id'];
            }

            if (isset($filters['shield_number'])) {
                $joins['personnel'] = true;
                $where[] = "up.shield_number = :shield_number";
                $params[':shield_number'] = $filters['shield_number'];
            }

            if (isset($filters['date_from'])) {
                $where[] = "u.assigned_datetime >= :date_from";
                $params[':date_from'] = $filters['date_from'];
            }

            if (isset($filters['date_to'])) {
                $where[] = "u.assigned_datetime <= :date_to";
                $params[':date_to'] = $filters['date_to'];
            }

            // Build JOIN clause
            $joinClause = '';
            if (isset($joins['personnel'])) {
                $joinClause .= " LEFT JOIN unit_personnel up ON u.id = up.unit_id";
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // Get total count
            $countSql = "
                SELECT COUNT(DISTINCT u.id)
                FROM units u
                {$joinClause}
                {$whereClause}
            ";
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
                    c.create_datetime as call_create_datetime
                FROM units u
                LEFT JOIN calls c ON u.call_id = c.id
                {$joinClause}
                {$whereClause}
                GROUP BY u.id
                ORDER BY u.assigned_datetime DESC
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
                    'unit_number' => $unit['unit_number'],
                    'unit_type' => $unit['unit_type'],
                    'jurisdiction' => $unit['jurisdiction'],
                    'is_primary' => (bool)$unit['is_primary'],
                    'call' => [
                        'call_number' => $unit['call_number'],
                        'nature_of_call' => $unit['nature_of_call'],
                        'create_datetime' => $unit['call_create_datetime']
                    ],
                    'timestamps' => [
                        'assigned' => $unit['assigned_datetime'],
                        'dispatch' => $unit['dispatch_datetime'],
                        'enroute' => $unit['enroute_datetime'],
                        'arrive' => $unit['arrive_datetime'],
                        'clear' => $unit['clear_datetime']
                    ]
                ];
            }, $units);

            Response::paginated($formattedUnits, $total, $pagination['page'], $pagination['per_page']);
        } catch (Exception $e) {
            Response::error('Unit search failed: ' . $e->getMessage(), 500);
        }
    }
}
