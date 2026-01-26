<?php

declare(strict_types=1);

/**
 * Router for PHP Built-in Web Server
 * Handles clean URLs for API endpoints
 *
 * @package NwsCad
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// API routes - match /api.php/{endpoint}
if (str_starts_with($uri, '/api.php')) {
    // Let api.php handle all /api.php/* requests
    require __DIR__ . '/api.php';
    exit;
}

// Old API routes format - match /api/{endpoint} (for backward compatibility)
if (preg_match('#^/api/([a-z_]+)$#', $uri, $matches)) {
    $endpoint = $matches[1];
    $apiFile = __DIR__ . '/api/' . $endpoint . '.php';
    
    if (file_exists($apiFile)) {
        require $apiFile;
        exit; // Important: exit after including the API file
    }
    
    // API endpoint not found
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'API endpoint not found'
    ]);
    exit;
}

// For all other requests, return false to let PHP's built-in server handle them
return false;