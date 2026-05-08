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
        // Note: Sensitive connection details (host, port, credentials) are not logged for security

        try {
            $logger->debug("Building DSN string for {$dbConfig['type']}");
            $dsn = self::buildDsn($dbConfig);
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            if ($dbConfig['type'] === 'mysql') {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES {$dbConfig['charset']}";
                $logger->debug("MySQL charset configured");
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
            // Log generic error message, avoid exposing connection details
            $logger->error("Database connection failed");
            throw new Exception("Database connection failed. Check your database configuration.");
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
     * Force reconnection to database
     * Useful when connection has been lost (e.g., MySQL timeout)
     */
    public static function reconnect(): void
    {
        $logger = Logger::getInstance();
        $logger->info("Forcing database reconnection...");
        self::$connection = null;
        self::connect();
    }

    /**
     * Run a database callable with one transparent reconnect-and-retry on a
     * lost connection. Use for any single-statement DB op in a long-lived
     * process (the file watcher) where MySQL may have been bounced underneath
     * us. Do NOT wrap calls that are part of an open transaction — a mid-
     * transaction reconnect would silently retry only the failing statement
     * and corrupt the unit of work.
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function run(callable $fn): mixed
    {
        try {
            return $fn(self::getConnection());
        } catch (PDOException $e) {
            if (!self::isConnectionLost($e)) {
                throw $e;
            }
            $logger = Logger::getInstance();
            $logger->warning("Database connection lost, reconnecting and retrying once: " . $e->getMessage());
            self::reconnect();
            return $fn(self::getConnection());
        }
    }

    /**
     * Detect whether a PDOException represents a lost server connection
     * (as opposed to a query/syntax/constraint error). Covers both MySQL
     * and PostgreSQL transport-layer failures.
     */
    public static function isConnectionLost(PDOException $e): bool
    {
        $msg = $e->getMessage();
        // MySQL: 2006 = server has gone away, 2013 = lost connection during query.
        // Postgres: server-closed and no-connection messages, plus SQLSTATE 08xxx.
        $needles = [
            'MySQL server has gone away',
            'Lost connection to MySQL server',
            'Error while sending',
            ' 2006 ',
            ' 2013 ',
            'server closed the connection',
            'no connection to the server',
            'connection is closed',
            'SQLSTATE[08',
            'SQLSTATE[HY000] [2002]', // can't connect (mysql restart in progress)
            'SQLSTATE[HY000] [2003]', // can't connect tcp
        ];
        foreach ($needles as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Prevent cloning and serialization
     */
    private function __construct() {}
    private function __clone() {}
}
