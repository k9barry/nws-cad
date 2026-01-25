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
    
    $stats = [];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM calls");
    $stats['total_calls'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $db->query("SELECT COUNT(DISTINCT unit_number) as total FROM units");
    $stats['total_units'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM narratives");
    $stats['total_narratives'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $db->query("
        SELECT 
            SUM(CASE WHEN closed_flag = 1 THEN 1 ELSE 0 END) as closed,
            SUM(CASE WHEN closed_flag = 0 THEN 1 ELSE 0 END) as open,
            SUM(CASE WHEN canceled_flag = 1 THEN 1 ELSE 0 END) as canceled
        FROM calls
    ");
    $statusCounts = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['calls_by_status'] = [
        'closed' => (int)$statusCounts['closed'],
        'open' => (int)$statusCounts['open'],
        'canceled' => (int)$statusCounts['canceled']
    ];
    
    $stmt = $db->query("
        SELECT 
            DATE(create_datetime) as date,
            COUNT(*) as count
        FROM calls
        WHERE create_datetime >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(create_datetime)
        ORDER BY date DESC
        LIMIT 30
    ");
    $stats['calls_by_date'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->query("
        SELECT 
            nature_of_call,
            COUNT(*) as count
        FROM calls
        WHERE nature_of_call IS NOT NULL AND nature_of_call != ''
        GROUP BY nature_of_call
        ORDER BY count DESC
        LIMIT 10
    ");
    $stats['top_call_types'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->query("
        SELECT 
            AVG(TIMESTAMPDIFF(MINUTE, dispatch_datetime, arrive_datetime)) as avg_response_time,
            MIN(TIMESTAMPDIFF(MINUTE, dispatch_datetime, arrive_datetime)) as min_response_time,
            MAX(TIMESTAMPDIFF(MINUTE, dispatch_datetime, arrive_datetime)) as max_response_time
        FROM units
        WHERE dispatch_datetime IS NOT NULL 
        AND arrive_datetime IS NOT NULL
        AND arrive_datetime > dispatch_datetime
    ");
    $responseTimes = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['response_times'] = [
        'average_minutes' => $responseTimes['avg_response_time'] ? round((float)$responseTimes['avg_response_time'], 2) : null,
        'min_minutes' => $responseTimes['min_response_time'] ? (int)$responseTimes['min_response_time'] : null,
        'max_minutes' => $responseTimes['max_response_time'] ? (int)$responseTimes['max_response_time'] : null
    ];
    
    $stmt = $db->query("
        SELECT 
            status,
            COUNT(*) as count
        FROM processed_files
        GROUP BY status
    ");
    $fileStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['processed_files'] = [
        'success' => (int)($fileStats['success'] ?? 0),
        'failed' => (int)($fileStats['failed'] ?? 0),
        'total' => array_sum($fileStats)
    ];
    
    $response = [
        'success' => true,
        'data' => $stats
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $logger->error("API Error (stats): " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ], JSON_PRETTY_PRINT);
}