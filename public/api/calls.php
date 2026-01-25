<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use NwsCad\Database;
use NwsCad\Logger;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $db = Database::getConnection();
    $logger = Logger::getInstance();
    
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? min(100, max(1, (int)$_GET['per_page'])) : 30;
    $offset = ($page - 1) * $perPage;
    
    $where = [];
    $params = [];
    
    if (!empty($_GET['call_number'])) {
        $where[] = "c.call_number LIKE ?";
        $params[] = '%' . $_GET['call_number'] . '%';
    }
    
    if (!empty($_GET['date_from'])) {
        $where[] = "c.create_datetime >= ?";
        $params[] = $_GET['date_from'] . ' 00:00:00';
    }
    
    if (!empty($_GET['date_to'])) {
        $where[] = "c.create_datetime <= ?";
        $params[] = $_GET['date_to'] . ' 23:59:59';
    }
    
    if (!empty($_GET['nature_of_call'])) {
        $where[] = "c.nature_of_call LIKE ?";
        $params[] = '%' . $_GET['nature_of_call'] . '%';
    }
    
    if (isset($_GET['closed_flag']) && $_GET['closed_flag'] !== '') {
        $where[] = "c.closed_flag = ?";
        $params[] = (int)$_GET['closed_flag'];
    }
    
    $allowedSort = ['create_datetime', 'call_number', 'nature_of_call', 'caller_name'];
    $sort = in_array($_GET['sort'] ?? '', $allowedSort) ? $_GET['sort'] : 'create_datetime';
    $order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $countSql = "SELECT COUNT(*) as total FROM calls c {$whereClause}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $sql = "
        SELECT 
            c.id,
            c.call_id,
            c.call_number,
            c.call_source,
            c.caller_name,
            c.caller_phone,
            c.nature_of_call,
            c.additional_info,
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
            COUNT(DISTINCT u.id) as unit_count,
            COUNT(DISTINCT n.id) as narrative_count
        FROM calls c
        LEFT JOIN locations l ON c.id = l.call_id
        LEFT JOIN units u ON c.id = u.call_id
        LEFT JOIN narratives n ON c.id = n.call_id
        {$whereClause}
        GROUP BY c.id, c.call_id, c.call_number, c.call_source, c.caller_name, 
                 c.caller_phone, c.nature_of_call, c.additional_info, c.create_datetime, 
                 c.close_datetime, c.created_by, c.closed_flag, c.canceled_flag, 
                 c.alarm_level, c.emd_code, l.id, l.full_address, l.city, l.state, 
                 l.latitude_y, l.longitude_x
        ORDER BY c.{$sort} {$order}
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $perPage;
    $params[] = $offset;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalPages = ceil($total / $perPage);
    
    $response = [
        'success' => true,
        'data' => $calls,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1
        ]
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $logger->error("API Error (calls): " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ], JSON_PRETTY_PRINT);
}