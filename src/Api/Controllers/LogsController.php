<?php

declare(strict_types=1);

namespace NwsCad\Api\Controllers;

use NwsCad\Config;
use NwsCad\Api\Request;
use NwsCad\Api\Response;
use Exception;

/**
 * Logs Controller
 * 
 * Handles log viewing API endpoints for monitoring and debugging.
 * 
 * SECURITY WARNING: These endpoints expose application logs which may contain
 * sensitive information (IP addresses, usernames, error details, etc.).
 * 
 * Security measures implemented:
 * - Production environment check (disabled by default)
 * - Directory traversal prevention
 * - Log level whitelist validation
 * - Filename format validation
 * 
 * TODO: Add proper authentication (API key, admin session, etc.) before
 * enabling in production.
 * 
 * @package NwsCad\Api\Controllers
 */
class LogsController
{
    /**
     * Allowed log levels for filtering
     * 
     * @var array<string>
     */
    private const ALLOWED_LOG_LEVELS = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
    
    /**
     * Valid log filename pattern (alphanumeric, hyphens, underscores, .log extension)
     * 
     * @var string
     */
    private const FILENAME_PATTERN = '/^[a-zA-Z0-9_-]+\.log$/';

    /**
     * Check if logs endpoints are enabled and user has access
     * 
     * @throws Exception If access is denied
     * @return void
     */
    private function checkAccess(): void
    {
        $config = Config::getInstance();
        $logsEnabled = $config->get('app.logs_enabled', false); // Default to disabled
        
        // In production, logs should be disabled unless explicitly enabled
        if ($config->get('app.env') === 'production' && !$logsEnabled) {
            Response::forbidden('Log access is disabled in production');
        }
        
        // Development environments can access logs
        // TODO: Add proper authentication here:
        // - Check for admin API key in header
        // - Verify admin session
        // - Check IP whitelist
    }
    
    /**
     * Validate log level filter against whitelist
     * 
     * @param string|null $level Level filter from request
     * @return string|null Validated uppercase level or null
     */
    private function validateLogLevel(?string $level): ?string
    {
        if ($level === null || $level === '') {
            return null;
        }
        
        $level = strtoupper(trim($level));
        
        if (!in_array($level, self::ALLOWED_LOG_LEVELS, true)) {
            return null; // Invalid level - ignore filter
        }
        
        return $level;
    }
    
    /**
     * Validate log filename format
     * 
     * @param string $filename Filename to validate
     * @return bool True if filename is valid
     */
    private function isValidFilename(string $filename): bool
    {
        // Must match safe filename pattern
        if (!preg_match(self::FILENAME_PATTERN, $filename)) {
            return false;
        }
        
        // Prevent directory traversal (extra safety)
        if (basename($filename) !== $filename) {
            return false;
        }
        
        // Must not contain special sequences
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            return false;
        }
        
        return true;
    }

    /**
     * Get available log files
     * 
     * Lists all .log files in the configured logs directory.
     * 
     * GET /api/logs
     * 
     * @return void
     */
    public function index(): void
    {
        $this->checkAccess();
        
        try {
            $config = Config::getInstance();
            $logPath = $config->get('paths.logs');

            if (!is_dir($logPath)) {
                Response::success(['files' => []]);
                return;
            }

            $files = [];
            $logFiles = glob($logPath . '/*.log');
            
            if ($logFiles) {
                foreach ($logFiles as $file) {
                    $basename = basename($file);
                    
                    // Only include files with valid names
                    if (!$this->isValidFilename($basename)) {
                        continue;
                    }
                    
                    $files[] = [
                        'name' => $basename,
                        'size' => filesize($file),
                        'modified' => filemtime($file),
                        'modified_formatted' => date('Y-m-d H:i:s', filemtime($file))
                    ];
                }
                
                // Sort by modification time, newest first
                usort($files, function($a, $b) {
                    return $b['modified'] - $a['modified'];
                });
            }

            Response::success(['files' => $files]);
        } catch (Exception $e) {
            error_log('[LogsController] Failed to list logs: ' . $e->getMessage());
            Response::error('Failed to list log files', 500);
        }
    }

    /**
     * Get log file contents
     * 
     * Retrieves paginated contents of a specific log file.
     * 
     * GET /api/logs/:filename
     * 
     * Query parameters:
     * - lines: Number of lines per page (1-1000, default: 100)
     * - level: Filter by log level (DEBUG, INFO, WARNING, ERROR, etc.)
     * - page: Page number
     * - per_page: Results per page
     * 
     * @param string $filename Log filename to read
     * @return void
     */
    public function show(string $filename): void
    {
        $this->checkAccess();
        
        try {
            // Validate filename to prevent directory traversal
            if (!$this->isValidFilename($filename)) {
                Response::error('Invalid filename format', 400);
                return;
            }

            $config = Config::getInstance();
            $logPath = $config->get('paths.logs');
            $filePath = $logPath . DIRECTORY_SEPARATOR . $filename;
            
            // Verify file is within log directory (realpath check)
            $realLogPath = realpath($logPath);
            $realFilePath = realpath($filePath);
            
            if ($realFilePath === false || $realLogPath === false) {
                Response::notFound(['message' => 'Log file not found']);
                return;
            }
            
            // Ensure file is within log directory
            if (strpos($realFilePath, $realLogPath) !== 0) {
                Response::error('Access denied', 403);
                return;
            }

            if (!file_exists($filePath)) {
                Response::notFound(['message' => 'Log file not found']);
                return;
            }

            // Get pagination parameters
            $pagination = Request::pagination();
            $lines = (int)(Request::query('lines', 100));
            $lines = max(1, min(1000, $lines)); // Limit between 1 and 1000

            // Get and validate log level filter
            $levelFilter = $this->validateLogLevel(Request::query('level'));
            
            // Read file in reverse order (newest first)
            $content = file_get_contents($filePath);
            $allLines = explode("\n", $content);
            $allLines = array_reverse($allLines);
            
            // Filter empty lines
            $allLines = array_filter($allLines, function($line) {
                return trim($line) !== '';
            });

            // Apply level filter if specified
            if ($levelFilter !== null) {
                $allLines = array_filter($allLines, function($line) use ($levelFilter) {
                    return stripos($line, $levelFilter) !== false;
                });
            }

            // Paginate
            $total = count($allLines);
            $offset = ($pagination['page'] - 1) * $pagination['per_page'];
            $pageLines = array_slice($allLines, $offset, $pagination['per_page']);
            
            // Parse log lines
            $parsedLines = array_map(function($line) {
                return $this->parseLogLine($line);
            }, $pageLines);

            Response::paginated($parsedLines, $total, $pagination['page'], $pagination['per_page']);
        } catch (Exception $e) {
            error_log('[LogsController] Failed to read log: ' . $e->getMessage());
            Response::error('Failed to read log file', 500);
        }
    }

    /**
     * Get recent log entries
     * 
     * Retrieves recent entries from the most recently modified log file.
     * 
     * GET /api/logs/recent
     * 
     * Query parameters:
     * - lines: Number of entries (1-500, default: 50)
     * - level: Filter by log level
     * 
     * @return void
     */
    public function recent(): void
    {
        $this->checkAccess();
        
        try {
            $lines = (int)(Request::query('lines', 50));
            $lines = max(1, min(500, $lines));
            
            $levelFilter = $this->validateLogLevel(Request::query('level'));

            $config = Config::getInstance();
            $logDir = $config->get('paths.logs');
            
            // Find the most recent log file
            $logFiles = glob($logDir . '/app-*.log');
            if (empty($logFiles)) {
                Response::success(['entries' => []]);
                return;
            }
            
            // Sort by modification time, most recent first
            usort($logFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            $logPath = $logFiles[0];

            if (!file_exists($logPath)) {
                Response::success(['entries' => []]);
                return;
            }

            // Read last N lines efficiently
            $content = $this->tail($logPath, $lines * 2); // Read more to account for filtering
            $allLines = explode("\n", $content);
            
            // Filter empty lines
            $allLines = array_filter($allLines, function($line) {
                return trim($line) !== '';
            });

            // Apply level filter if specified
            if ($levelFilter !== null) {
                $allLines = array_filter($allLines, function($line) use ($levelFilter) {
                    return stripos($line, $levelFilter) !== false;
                });
            }

            // Limit to requested number and reverse to show newest first
            $allLines = array_slice($allLines, -$lines);
            $allLines = array_reverse($allLines);

            // Parse log lines
            $entries = array_map(function($line) {
                return $this->parseLogLine($line);
            }, $allLines);

            Response::success(['entries' => $entries]);
        } catch (Exception $e) {
            error_log('[LogsController] Failed to read recent logs: ' . $e->getMessage());
            Response::error('Failed to read recent logs', 500);
        }
    }

    /**
     * Parse a log line into structured components
     * 
     * Expected format: [2024-01-26 12:34:56] channel.LEVEL: message context
     * 
     * @param string $line Raw log line
     * @return array{timestamp: ?string, channel: ?string, level: string, message: string, raw: string}
     */
    private function parseLogLine(string $line): array
    {
        // Pattern: [2024-01-26 12:34:56] channel.LEVEL: message context
        $pattern = '/^\[([^\]]+)\]\s+([^.]+)\.([^:]+):\s+(.*)$/';
        
        if (preg_match($pattern, $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'channel' => $matches[2],
                'level' => $matches[3],
                'message' => $matches[4],
                'raw' => $line
            ];
        }

        // If pattern doesn't match, return as raw
        return [
            'timestamp' => null,
            'channel' => null,
            'level' => 'UNKNOWN',
            'message' => $line,
            'raw' => $line
        ];
    }

    /**
     * Read last N lines from a file efficiently
     * 
     * Uses seeking to avoid loading entire file into memory.
     * 
     * @param string $filepath Path to file
     * @param int $lines Number of lines to read
     * @return string Last N lines of file
     * @throws Exception If file cannot be opened
     */
    private function tail(string $filepath, int $lines = 50): string
    {
        $handle = @fopen($filepath, 'r');
        if (!$handle) {
            throw new Exception("Failed to open log file");
        }

        try {
            $linecounter = $lines;
            $pos = -1;
            $text = [];

            fseek($handle, $pos, SEEK_END);

            while ($linecounter > 0) {
                $t = fgetc($handle);
                if ($t === false) {
                    break;
                }
                
                if ($t === "\n") {
                    $linecounter--;
                }
                
                $text[] = $t;
                $pos--;
                
                $result = fseek($handle, $pos, SEEK_END);
                if ($result === -1) {
                    // Reached beginning of file
                    rewind($handle);
                    break;
                }
            }

            // Always reverse since we read backwards
            $text = array_reverse($text);

            return implode('', $text);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Clear old log files
     * 
     * Deletes log files older than specified number of days.
     * The current app.log file is never deleted.
     * 
     * DELETE /api/logs/cleanup
     * 
     * Query parameters:
     * - days: Delete files older than N days (1-90, default: 7)
     * 
     * @return void
     */
    public function cleanup(): void
    {
        $this->checkAccess();
        
        try {
            $days = (int)(Request::query('days', 7));
            $days = max(1, min(90, $days));

            $config = Config::getInstance();
            $logPath = $config->get('paths.logs');
            
            $deleted = [];
            $logFiles = glob($logPath . '/*.log');
            $cutoffTime = time() - ($days * 86400);

            if ($logFiles) {
                foreach ($logFiles as $file) {
                    $basename = basename($file);
                    
                    // Don't delete the current log file
                    if ($basename === 'app.log') {
                        continue;
                    }
                    
                    // Only delete files with valid names
                    if (!$this->isValidFilename($basename)) {
                        continue;
                    }

                    if (filemtime($file) < $cutoffTime) {
                        if (@unlink($file)) {
                            $deleted[] = $basename;
                        }
                    }
                }
            }

            Response::success([
                'message' => count($deleted) . ' log file(s) deleted',
                'deleted' => $deleted
            ]);
        } catch (Exception $e) {
            error_log('[LogsController] Failed to cleanup logs: ' . $e->getMessage());
            Response::error('Failed to cleanup logs', 500);
        }
    }
}
