<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File
 * Initializes test environment
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Set timezone
date_default_timezone_set('UTC');

// Ensure logs directory exists
$logsDir = __DIR__ . '/../logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// Ensure tmp directory exists
$tmpDir = __DIR__ . '/../tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0755, true);
}

// Set testing environment variables
$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_DEBUG'] = 'false';
$_ENV['LOG_LEVEL'] = 'error';

// Helper function to create test database connection
function getTestDbConnection(): PDO
{
    $dsn = sprintf(
        "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
        $_ENV['MYSQL_HOST'] ?? '127.0.0.1',
        $_ENV['MYSQL_PORT'] ?? '3306',
        $_ENV['MYSQL_DATABASE'] ?? 'nws_cad_test',
    );
    
    return new PDO(
        $dsn,
        $_ENV['MYSQL_USER'] ?? 'test_user',
        $_ENV['MYSQL_PASSWORD'] ?? 'test_pass',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

// Helper function to clean test database
function cleanTestDatabase(): void
{
    try {
        $pdo = getTestDbConnection();
        $tables = [
            'unit_dispositions', 'unit_logs', 'unit_personnel', 'units',
            'call_dispositions', 'vehicles', 'persons', 'narratives',
            'incidents', 'locations', 'agency_contexts', 'calls', 'processed_files'
        ];
        
        foreach ($tables as $table) {
            $pdo->exec("DELETE FROM $table");
        }
    } catch (PDOException $e) {
        // Ignore if database doesn't exist or tables don't exist
    }
}
