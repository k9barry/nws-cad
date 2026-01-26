<?php

namespace NwsCad;

use Exception;

/**
 * Configuration Manager
 * Handles application configuration from environment variables
 */
class Config
{
    private static ?Config $instance = null;
    private array $config = [];

    private function __construct()
    {
        $this->loadConfig();
    }

    public static function getInstance(): Config
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadConfig(): void
    {
        // Load .env file if exists
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $this->loadEnvFile($envFile);
        }

        $this->config = [
            'db' => [
                'type' => $this->env('DB_TYPE', 'mysql'),
                'mysql' => [
                    'host' => $this->env('MYSQL_HOST', 'mysql'),
                    'port' => $this->env('MYSQL_PORT', '3306'),
                    'database' => $this->env('MYSQL_DATABASE', 'nws_cad'),
                    'username' => $this->env('MYSQL_USER', 'nws_user'),
                    'password' => $this->env('MYSQL_PASSWORD', ''),
                    'charset' => 'utf8mb4',
                ],
                'pgsql' => [
                    'host' => $this->env('POSTGRES_HOST', 'postgres'),
                    'port' => $this->env('POSTGRES_PORT', '5432'),
                    'database' => $this->env('POSTGRES_DB', 'nws_cad'),
                    'username' => $this->env('POSTGRES_USER', 'nws_user'),
                    'password' => $this->env('POSTGRES_PASSWORD', ''),
                ],
            ],
            'app' => [
                'env' => $this->env('APP_ENV', 'production'),
                'debug' => $this->env('APP_DEBUG', 'false') === 'true',
                'log_level' => $this->env('LOG_LEVEL', 'info'),
                'logs_enabled' => $this->env('APP_LOGS_ENABLED', 'false') === 'true',
            ],
            'watcher' => [
                'folder' => $this->env('WATCH_FOLDER', __DIR__ . '/../watch'),
                'interval' => (int)$this->env('WATCHER_INTERVAL', '5'),
                'file_pattern' => $this->env('WATCHER_FILE_PATTERN', '*.xml'),
            ],
            'paths' => [
                'logs' => __DIR__ . '/../logs',
                'tmp' => __DIR__ . '/../tmp',
            ],
        ];
    }

    private function loadEnvFile(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                if (!isset($_ENV[$key])) {
                    putenv("$key=$value");
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    private function env(string $key, string $default = ''): string
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }

    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function getDbConfig(): array
    {
        $dbType = $this->get('db.type');
        if (!in_array($dbType, ['mysql', 'pgsql'])) {
            throw new Exception("Unsupported database type: $dbType");
        }

        return array_merge(
            ['type' => $dbType],
            $this->get("db.$dbType")
        );
    }
}
