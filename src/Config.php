<?php

declare(strict_types=1);

namespace NwsCad;

use Exception;
use NwsCad\Exceptions\MissingSecretException;
use NwsCad\Logging\SecretRegistry;

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

    /**
     * Split and trim a comma-separated string. Empty input or empty segments
     * are dropped. Used for env-driven list values (CIDRs, origins, etc.).
     *
     * @return string[]
     */
    public static function csv(string $value): array
    {
        if ($value === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $value));
        return array_values(array_filter($parts, static fn (string $s): bool => $s !== ''));
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
            'logs' => [
                // Allowlist of identity-header users permitted to read logs
                // in production (when app.logs_enabled is true). Empty list
                // in production = denied (fail-secure). Ignored in non-prod
                // environments where logs are open to anyone reaching the API.
                'admin_users' => self::csv($this->env('LOGS_ADMIN_USERS', '')),
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
            'notifications' => [
                'delta_seconds'          => (int) $this->env('NOTIFICATION_DELTA_SECONDS', '900'),
                'base_url_allowlist'     => self::csv($this->env('NOTIFICATION_BASE_URL_ALLOWLIST', '')),
                'allow_http_for_private' => $this->env('NOTIFICATION_ALLOW_HTTP_PRIVATE', 'false') === 'true',
            ],
            'cors' => [
                'allowed_origins' => self::csv($this->env('ALLOWED_ORIGINS', '')),
            ],
            'proxy' => [
                'trusted_cidrs'   => self::csv($this->env('TRUSTED_PROXY_CIDRS', '127.0.0.1/32,::1/128')),
                'identity_header' => $this->env('PROXY_IDENTITY_HEADER', 'X-Auth-User'),
            ],
            'calls' => [
                // Guardrail: after this many hours since create_datetime, an
                // otherwise-open call is reclassified as closed everywhere we
                // derive status in SQL (FilterSqlBuilder, StatsController) and
                // surfaced to JS via is_stale on call rows.
                'stale_open_hours' => (int) $this->env('STALE_OPEN_CALL_HOURS', '72'),
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

                // Strip a single matched pair of surrounding quotes (standard dotenv behavior).
                // Without this, a value like `KEY="Bearer tk_xxx"` ends up containing the literal
                // quote characters and silently breaks anything that uses it as an HTTP header.
                $len = strlen($value);
                if ($len >= 2) {
                    $first = $value[0];
                    $last = $value[$len - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }

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

    public function secret(string $key): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            throw MissingSecretException::forKey($key);
        }
        SecretRegistry::register($value);
        return $value;
    }

    public function secretOptional(string $key): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return null;
        }
        SecretRegistry::register($value);
        return $value;
    }
}
