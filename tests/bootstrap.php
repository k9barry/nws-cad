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

// CRITICAL SAFETY: redirect ALL DB access during tests to a dedicated test
// database. cleanTestDatabase() does a sweeping DELETE across 15 tables, so
// a connection to production destroys live CAD data on every test run.
//
// 1. Remember the original (production) database name so the guard can
//    refuse to run if anything later tries to point us back at it.
// 2. Override MYSQL_DATABASE in this process to the test database. Both
//    Database::getConnection() (used by controllers under test) and
//    getTestDbConnection() (used by cleanTestDatabase) read this env, so
//    both will target nws_cad_test.
$originalDb = $_ENV['MYSQL_DATABASE'] ?? getenv('MYSQL_DATABASE') ?: '';
$_ENV['MYSQL_PRODUCTION_DATABASE'] = $originalDb;
putenv('MYSQL_PRODUCTION_DATABASE=' . $originalDb);

$testDbName = $_ENV['MYSQL_TEST_DATABASE'] ?? getenv('MYSQL_TEST_DATABASE') ?: 'nws_cad_test';
if ($originalDb !== '' && $testDbName === $originalDb) {
    throw new RuntimeException(
        "Refusing to run tests: MYSQL_TEST_DATABASE ({$testDbName}) equals MYSQL_DATABASE ({$originalDb}). " .
        "Tests truncate every row in 15 tables; pointing them at production destroys live CAD data. " .
        "Set MYSQL_TEST_DATABASE to a different database name (e.g. nws_cad_test) and ensure it exists."
    );
}

$_ENV['MYSQL_DATABASE'] = $testDbName;
putenv('MYSQL_DATABASE=' . $testDbName);

// Helper function to create test database connection.
//
// Now safe: MYSQL_DATABASE has already been forced to the test database
// at bootstrap time, and the assertion above guarantees it cannot equal
// the saved MYSQL_PRODUCTION_DATABASE.
function getTestDbConnection(): PDO
{
    $dsn = sprintf(
        "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
        $_ENV['MYSQL_HOST'] ?? '127.0.0.1',
        $_ENV['MYSQL_PORT'] ?? '3306',
        $_ENV['MYSQL_DATABASE'],
    );

    return new PDO(
        $dsn,
        $_ENV['MYSQL_TEST_USER'] ?? getenv('MYSQL_TEST_USER') ?: ($_ENV['MYSQL_USER'] ?? 'test_user'),
        $_ENV['MYSQL_TEST_PASSWORD'] ?? getenv('MYSQL_TEST_PASSWORD') ?: ($_ENV['MYSQL_PASSWORD'] ?? 'test_pass'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

// Helper function to clean test database.
//
// Several integration tests (notably ApiFilteringTest::seedFilterTestData)
// hard-code primary-key values like agency_contexts.call_id=1..7 and rely on
// the auto-increment counter on `calls` starting from 1 each run. DELETE FROM
// clears rows but leaves AUTO_INCREMENT advanced, so any prior test that
// inserts into a table here would break the next test that assumes id=1.
// TRUNCATE under FOREIGN_KEY_CHECKS=0 deletes rows AND resets the counter
// without fighting the FK graph; we restore the FK check before returning.
function cleanTestDatabase(): void
{
    try {
        $pdo = getTestDbConnection();
        $tables = [
            'notification_outbox',
            'notification_send_log',
            'notification_channels',
            'unit_dispositions', 'unit_logs', 'unit_personnel', 'units',
            'call_dispositions', 'vehicles', 'persons', 'narratives',
            'incidents', 'locations', 'agency_contexts', 'calls', 'processed_files'
        ];

        // DELETE first (FK ON DELETE CASCADE handles children), then reset
        // AUTO_INCREMENT separately. We avoid TRUNCATE here because some MySQL
        // versions reject it on a parent table referenced by a FK even with
        // FOREIGN_KEY_CHECKS=0.
        foreach ($tables as $table) {
            $pdo->exec("DELETE FROM $table");
            $pdo->exec("ALTER TABLE $table AUTO_INCREMENT = 1");
        }
    } catch (PDOException $e) {
        // Ignore if database doesn't exist or tables don't exist
    }
}
