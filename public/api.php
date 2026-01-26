<?php

/**
 * NWS CAD API Entry Point
 * REST API for accessing CAD database
 */

require_once __DIR__ . '/../vendor/autoload.php';

use NwsCad\Api\Router;
use NwsCad\Api\Response;
use NwsCad\Api\Controllers\CallsController;
use NwsCad\Api\Controllers\UnitsController;
use NwsCad\Api\Controllers\SearchController;
use NwsCad\Api\Controllers\StatsController;

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD' ] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Set error handling
set_exception_handler(function ($e) {
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    Response::serverError('An unexpected error occurred');
});

// Create router
$router = new Router('/api');

// API info endpoint
$router->get('/', function() {
    Response::success([
        'name' => 'NWS CAD API',
        'version' => '1.0.0',
        'description' => 'REST API for New World Systems CAD database',
        'endpoints' => [
            'calls' => '/api/calls',
            'units' => '/api/units',
            'search' => '/api/search',
            'stats' => '/api/stats',
            'docs' => '/api/docs'
        ]
    ]);
});

// Documentation endpoint
$router->get('/docs', function() {
    $readme = file_get_contents(__DIR__ . '/../src/Api/Controllers/README.md');
    Response::success(['documentation' => $readme]);
});

// Calls Controller Routes
$router->get('/calls', [CallsController::class, 'index']);
$router->get('/calls/{id}', [CallsController::class, 'show']);
$router->get('/calls/{id}/units', [CallsController::class, 'units']);
$router->get('/calls/{id}/narratives', [CallsController::class, 'narratives']);
$router->get('/calls/{id}/persons', [CallsController::class, 'persons']);
$router->get('/calls/{id}/location', [CallsController::class, 'location']);
$router->get('/calls/{id}/incidents', [CallsController::class, 'incidents']);
$router->get('/calls/{id}/dispositions', [CallsController::class, 'dispositions']);

// Units Controller Routes
$router->get('/units', [UnitsController::class, 'index']);
$router->get('/units/{id}', [UnitsController::class, 'show']);
$router->get('/units/{id}/logs', [UnitsController::class, 'logs']);
$router->get('/units/{id}/personnel', [UnitsController::class, 'personnel']);
$router->get('/units/{id}/dispositions', [UnitsController::class, 'dispositions']);

// Search Controller Routes
$router->get('/search/calls', [SearchController::class, 'calls']);
$router->get('/search/location', [SearchController::class, 'location']);
$router->get('/search/units', [SearchController::class, 'units']);

// Stats Controller Routes
$router->get('/stats', [StatsController::class, 'index']);
$router->get('/stats/calls', [StatsController::class, 'calls']);
$router->get('/stats/units', [StatsController::class, 'units']);
$router->get('/stats/response-times', [StatsController::class, 'responseTimes']);

// Dispatch request
try {
    $router->dispatch(Router::getMethod(), Router::getUri());
} catch (Exception $e) {
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    Response::serverError('An unexpected error occurred');
}
