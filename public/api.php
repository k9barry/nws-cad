<?php

/**
 * NWS CAD API Entry Point
 * REST API for accessing CAD database
 */

require_once __DIR__ . '/../src/bootstrap.php';

use NwsCad\Api\Router;
use NwsCad\Api\Response;
use NwsCad\Api\Controllers\CallsController;
use NwsCad\Api\Controllers\UnitsController;
use NwsCad\Api\Controllers\SearchController;
use NwsCad\Api\Controllers\StatsController;
use NwsCad\Api\Controllers\LogsController;
use NwsCad\Api\Controllers\NotificationsController;
use NwsCad\Api\Controllers\OutboxController;
use NwsCad\Api\Controllers\HealthController;
use NwsCad\Api\Controllers\FilterOptionsController;

// Set error handling
set_exception_handler(function ($e) {
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    Response::serverError('An unexpected error occurred');
});

// Create router
// Initialize router with correct basePath
// IMPORTANT: Must be '/api' NOT '/api.php' to match URI paths correctly
$router = new Router('/api');

// Health Check Route (used by docker-compose api healthcheck)
$router->get('/health', [HealthController::class, 'index']);

// Filter Options Route
$router->get('/filter-options', [FilterOptionsController::class, 'index']);

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
            'notifications' => '/api/notifications/channels',
            'health' => '/api/health',
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

// Notifications Controller Routes (read-only)
$router->get('/notifications/channels', [NotificationsController::class, 'channels']);
$router->get('/notifications/log',      [NotificationsController::class, 'log']);

// Notifications Controller Routes (write)
$router->post('/notifications/channels/{type}/enable',  [NotificationsController::class, 'enable']);
$router->post('/notifications/channels/{type}/disable', [NotificationsController::class, 'disable']);
$router->post('/notifications/channels/{type}/test',    [NotificationsController::class, 'test']);
$router->post('/notifications/channels/{type}/clear-error', [NotificationsController::class, 'clearChannelError']);
$router->delete('/notifications/log/{id}',              [NotificationsController::class, 'dismissLogEntry']);
$router->post('/notifications/log/clear-failed',        [NotificationsController::class, 'clearFailed']);

// Outbox Controller Routes (operator admin for notification_outbox queue)
$router->get('/notifications/outbox',                  [OutboxController::class, 'index']);
$router->get('/notifications/outbox/{id}',             [OutboxController::class, 'show']);
$router->post('/notifications/outbox/{id}/retry',      [OutboxController::class, 'retry']);
$router->post('/notifications/outbox/{id}/schedule',   [OutboxController::class, 'schedule']);
$router->delete('/notifications/outbox/{id}',          [OutboxController::class, 'dismiss']);
$router->post('/notifications/outbox/clear',           [OutboxController::class, 'clear']);

// Logs Controller Routes
$router->get('/logs', [LogsController::class, 'index']);
$router->get('/logs/recent', [LogsController::class, 'recent']);
$router->get('/logs/{filename}', [LogsController::class, 'show']);
$router->delete('/logs/cleanup', [LogsController::class, 'cleanup']);

// Dispatch request
try {
    // Get URI path without query string
    $uri = Router::getUri();
    $uri = parse_url($uri, PHP_URL_PATH);
    
    $router->dispatch(Router::getMethod(), $uri);
} catch (Exception $e) {
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    Response::serverError('An unexpected error occurred');
}
