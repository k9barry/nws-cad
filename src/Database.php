<?php

declare(strict_types=1);

namespace NwsCad;

use PDO;
use PDOException;
use Exception;

/**
 * Database Connection Manager
 * Provides database abstraction layer supporting MySQL and PostgreSQL
 */
class Database
{
    private static ?PDO $connection = null;
    private static ?string $dbType = null;

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            self::connect();
        }
        return self::$connection;
    }

    private static function connect(): void
    {
        $config = Config::getInstance();
        $dbConfig = $config->getDbConfig();
        self::$dbType = $dbConfig['type'];

        $logger = Logger::getInstance();
        $logger->info("Connecting to {$dbConfig['type']} database");
        $logger->debug("Database host: {$dbConfig['host']}");
        $logger->debug("Database port: {$dbConfig['port']}");
        $logger->debug("Database name: {$dbConfig['database']}");
        $logger->debug("Database user: {$dbConfig['username']}");

        try {
            $logger->debug("Building DSN string for {$dbConfig['type']}");
            $dsn = self::buildDsn($dbConfig);
            $logger->debug("DSN: {$dsn}");
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            if ($dbConfig['type'] === 'mysql') {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES {$dbConfig['charset']}";
                $logger->debug("MySQL charset set to: {$dbConfig['charset']}");
            }

            $logger->debug("Creating PDO connection...");
            self::$connection = new PDO(
                $dsn,
                $dbConfig['username'],
                $dbConfig['password'],
                $options
            );

            $logger->info("Database connection established successfully");
            $logger->debug("PDO connection created with error mode EXCEPTION");
        } catch (PDOException $e) {
            $logger->error("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    private static function buildDsn(array $config): string
    {
        if ($config['type'] === 'mysql') {
            return sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );
        } elseif ($config['type'] === 'pgsql') {
            return sprintf(
                "pgsql:host=%s;port=%s;dbname=%s",
                $config['host'],
                $config['port'],
                $config['database']
            );
        }

        throw new Exception("Unsupported database type: {$config['type']}");
    }

    public static function getDbType(): ?string
    {
        return self::$dbType;
    }

    /**
     * Test database connectivity and health
     */
    public static function testConnection(): bool
    {
        $logger = Logger::getInstance();
        try {
            $logger->debug("Testing database connection health...");
            $pdo = self::getConnection();
            $pdo->query('SELECT 1');
            $logger->debug("Database health check passed");
            return true;
        } catch (Exception $e) {
            $logger->error("Database health check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Prevent cloning and serialization
     */
    private function __construct() {}
    private function __clone() {}
}
