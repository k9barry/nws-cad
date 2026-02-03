<?php

declare(strict_types=1);

/**
 * Router for PHP Built-in Web Server
 * Handles clean URLs for API endpoints
 *
 * @package NwsCad
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// API routes - forward to api.php
// IMPORTANT: All /api/* routes are handled by api.php with Router basePath = '/api'
// This ensures URI paths match correctly (e.g., /api/calls matches pattern #^/api/calls$#)
if (str_starts_with($uri, '/api/') || $uri === '/api' || str_starts_with($uri, '/api.php')) {
    require __DIR__ . '/api.php';
    exit;
}

// For all other requests, return false to let PHP's built-in server handle them
return false;