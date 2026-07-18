<?php

declare(strict_types=1);

namespace NwsCad\Api\Controllers;

use NwsCad\Api\Response;
use NwsCad\Config;
use NwsCad\Database;
use PDO;
use Throwable;

final class HealthController
{
    private PDO $db;

    /** Disk usage warn/critical thresholds (percent used). */
    private const DISK_WARN_PCT     = 80.0;
    private const DISK_CRITICAL_PCT = 90.0;

    /** PHP memory usage warn/critical thresholds (percent of limit). */
    private const MEM_WARN_PCT     = 80.0;
    private const MEM_CRITICAL_PCT = 95.0;

    /** Watcher heartbeat staleness thresholds (seconds). */
    private const HEARTBEAT_WARN_SECONDS     = 60;
    private const HEARTBEAT_CRITICAL_SECONDS = 300;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function index(): void
    {
        try {
            $row = $this->db->query('SELECT 1 AS ok')->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int) ($row['ok'] ?? 0) !== 1) {
                Response::error('Database unreachable', 503, ['db' => 'unreachable']);
                return;
            }
            Response::success([
                'status'    => 'ok',
                'db'        => 'ok',
                'timestamp' => date('c'),
            ]);
        } catch (Throwable $e) {
            Response::error('Database unreachable', 503, ['db' => 'unreachable']);
        }
    }

    /**
     * Extended system health: app version, DB latency, disk usage, PHP memory,
     * load average, file-watcher heartbeat, and notification-outbox backlog.
     *
     * Every metric is best-effort and degrades gracefully (null / "unknown")
     * so the endpoint never fails just because one probe is unavailable on a
     * given host (e.g. sys_getloadavg() on non-Linux).
     */
    public function system(): void
    {
        $config = Config::getInstance();

        $db      = $this->probeDatabase();
        $disks   = $this->probeDisks($config);
        $memory  = $this->probeMemory();
        $load    = $this->probeLoad();
        $watcher = $this->probeWatcher($config);
        $outbox  = $this->probeOutbox();

        // Overall status = worst of the sections that carry a status.
        // "unknown" is informational and never drives the overall verdict.
        $statuses = [
            $db['status'],
            $memory['status'],
            $watcher['status'],
        ];
        foreach ($disks as $disk) {
            $statuses[] = $disk['status'];
        }

        Response::success([
            'status'    => $this->worstStatus($statuses),
            'timestamp' => date('c'),
            'app'       => [
                'version'     => $this->appVersion(),
                'php_version' => PHP_VERSION,
                'environment' => (string) $config->get('app.env', 'production'),
            ],
            'db'      => $db,
            'disks'   => $disks,
            'memory'  => $memory,
            'load'    => $load,
            'watcher' => $watcher,
            'outbox'  => $outbox,
        ]);
    }

    /**
     * @return array{status:string,latency_ms:float|null}
     */
    private function probeDatabase(): array
    {
        try {
            $start = microtime(true);
            $row   = $this->db->query('SELECT 1 AS ok')->fetch(PDO::FETCH_ASSOC);
            $ms    = round((microtime(true) - $start) * 1000, 2);

            if (!$row || (int) ($row['ok'] ?? 0) !== 1) {
                return ['status' => 'critical', 'latency_ms' => null];
            }
            return ['status' => 'ok', 'latency_ms' => $ms];
        } catch (Throwable $e) {
            return ['status' => 'critical', 'latency_ms' => null];
        }
    }

    /**
     * Disk usage for the data (watch) and log volumes. Duplicate mounts are
     * collapsed so a shared volume is reported once.
     *
     * @return list<array{label:string,path:string,total_bytes:int|null,free_bytes:int|null,used_bytes:int|null,used_pct:float|null,status:string}>
     */
    private function probeDisks(Config $config): array
    {
        $candidates = [
            'Data (watch)' => (string) $config->get('watcher.folder', ''),
            'Logs'         => (string) $config->get('paths.logs', ''),
        ];

        $disks = [];
        $seen  = [];
        foreach ($candidates as $label => $path) {
            if ($path === '' || !is_dir($path)) {
                continue;
            }
            $total = @disk_total_space($path);
            $free  = @disk_free_space($path);
            if ($total === false || $free === false || $total <= 0) {
                $disks[] = [
                    'label'       => $label,
                    'path'        => $path,
                    'total_bytes' => null,
                    'free_bytes'  => null,
                    'used_bytes'  => null,
                    'used_pct'    => null,
                    'status'      => 'unknown',
                ];
                continue;
            }

            // Collapse identical mounts (same total+free) reported under two paths.
            $key = (int) $total . ':' . (int) $free;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $used    = $total - $free;
            $usedPct = round(($used / $total) * 100, 1);

            $disks[] = [
                'label'       => $label,
                'path'        => $path,
                'total_bytes' => (int) $total,
                'free_bytes'  => (int) $free,
                'used_bytes'  => (int) $used,
                'used_pct'    => $usedPct,
                'status'      => $this->thresholdStatus($usedPct, self::DISK_WARN_PCT, self::DISK_CRITICAL_PCT),
            ];
        }

        return $disks;
    }

    /**
     * @return array{php_usage_bytes:int,php_peak_bytes:int,limit_bytes:int|null,used_pct:float|null,status:string}
     */
    private function probeMemory(): array
    {
        $usage = memory_get_usage(true);
        $peak  = memory_get_peak_usage(true);
        $limit = $this->parseBytes((string) ini_get('memory_limit'));

        $usedPct = null;
        $status  = 'ok';
        if ($limit !== null && $limit > 0) {
            $usedPct = round(($usage / $limit) * 100, 1);
            $status  = $this->thresholdStatus($usedPct, self::MEM_WARN_PCT, self::MEM_CRITICAL_PCT);
        }

        return [
            'php_usage_bytes' => $usage,
            'php_peak_bytes'  => $peak,
            'limit_bytes'     => $limit,
            'used_pct'        => $usedPct,
            'status'          => $status,
        ];
    }

    /**
     * @return array{'1m':float,'5m':float,'15m':float,cpus:int|null}|null
     */
    private function probeLoad(): ?array
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }
        $load = @sys_getloadavg();
        if (!is_array($load) || count($load) < 3) {
            return null;
        }

        return [
            '1m'   => round((float) $load[0], 2),
            '5m'   => round((float) $load[1], 2),
            '15m'  => round((float) $load[2], 2),
            'cpus' => $this->cpuCount(),
        ];
    }

    /**
     * @return array{heartbeat_age_seconds:int|null,threshold_seconds:int,status:string}
     */
    private function probeWatcher(Config $config): array
    {
        $logDir = rtrim((string) $config->get('paths.logs', ''), '/');
        $path   = $logDir !== '' ? $logDir . '/.watcher-heartbeat' : '';

        if ($path === '' || !is_file($path)) {
            return [
                'heartbeat_age_seconds' => null,
                'threshold_seconds'     => self::HEARTBEAT_WARN_SECONDS,
                'status'                => 'unknown',
            ];
        }

        $mtime = @filemtime($path);
        if ($mtime === false) {
            return [
                'heartbeat_age_seconds' => null,
                'threshold_seconds'     => self::HEARTBEAT_WARN_SECONDS,
                'status'                => 'unknown',
            ];
        }

        $age = max(0, time() - $mtime);
        if ($age >= self::HEARTBEAT_CRITICAL_SECONDS) {
            $status = 'critical';
        } elseif ($age >= self::HEARTBEAT_WARN_SECONDS) {
            $status = 'warn';
        } else {
            $status = 'ok';
        }

        return [
            'heartbeat_age_seconds' => $age,
            'threshold_seconds'     => self::HEARTBEAT_WARN_SECONDS,
            'status'                => $status,
        ];
    }

    /**
     * Notification-outbox backlog by status. Returns null if the table is
     * absent (e.g. notifications module not provisioned).
     *
     * @return array{pending:int,in_flight:int,retry:int,done:int,failed:int,total:int}|null
     */
    private function probeOutbox(): ?array
    {
        try {
            $rows = $this->db
                ->query('SELECT status, COUNT(*) AS c FROM notification_outbox GROUP BY status')
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }

        $counts = ['pending' => 0, 'in_flight' => 0, 'retry' => 0, 'done' => 0, 'failed' => 0];
        $total  = 0;
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $count  = (int) ($row['c'] ?? 0);
            $total += $count;
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $count;
            }
        }
        $counts['total'] = $total;

        return $counts;
    }

    private function appVersion(): string
    {
        $file = __DIR__ . '/../../../VERSION';
        if (is_file($file)) {
            $version = trim((string) @file_get_contents($file));
            if ($version !== '') {
                return $version;
            }
        }
        return 'unknown';
    }

    private function cpuCount(): ?int
    {
        $cpuinfo = '/proc/cpuinfo';
        if (is_readable($cpuinfo)) {
            $contents = @file_get_contents($cpuinfo);
            if ($contents !== false) {
                $count = preg_match_all('/^processor\s*:/m', $contents);
                if ($count > 0) {
                    return $count;
                }
            }
        }
        return null;
    }

    /**
     * Parse a PHP ini shorthand byte value ("256M", "1G", "-1", "512K").
     * Returns null for unlimited (-1) or unparseable input.
     */
    private function parseBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return null;
        }
        if (!preg_match('/^(\d+)\s*([KMG]?)$/i', $value, $m)) {
            return null;
        }
        $number = (int) $m[1];
        switch (strtoupper($m[2])) {
            case 'G':
                return $number * 1024 * 1024 * 1024;
            case 'M':
                return $number * 1024 * 1024;
            case 'K':
                return $number * 1024;
            default:
                return $number;
        }
    }

    private function thresholdStatus(float $pct, float $warn, float $critical): string
    {
        if ($pct >= $critical) {
            return 'critical';
        }
        if ($pct >= $warn) {
            return 'warn';
        }
        return 'ok';
    }

    /**
     * @param list<string> $statuses
     */
    private function worstStatus(array $statuses): string
    {
        $rank = ['ok' => 0, 'unknown' => 0, 'warn' => 1, 'critical' => 2];
        $worst = 'ok';
        $worstRank = 0;
        foreach ($statuses as $status) {
            $r = $rank[$status] ?? 0;
            if ($r > $worstRank) {
                $worstRank = $r;
                $worst = $status;
            }
        }
        return $worst;
    }
}
