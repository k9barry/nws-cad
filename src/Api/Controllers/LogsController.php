<?php

namespace NwsCad\Api\Controllers;

use NwsCad\Config;
use NwsCad\Api\Request;
use NwsCad\Api\Response;
use Exception;

/**
 * Logs Controller
 * Handles log viewing endpoints
 */
class LogsController
{
    /**
     * Get available log files
     * GET /api/logs
     */
    public function index(): void
    {
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
                    $files[] = [
                        'name' => basename($file),
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
            Response::error('Failed to list log files: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get log file contents
     * GET /api/logs/:filename
     */
    public function show(string $filename): void
    {
        try {
            // Validate filename to prevent directory traversal
            if (strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
                Response::error('Invalid filename', 400);
                return;
            }

            $config = Config::getInstance();
            $logPath = $config->get('paths.logs');
            $filePath = $logPath . '/' . $filename;

            if (!file_exists($filePath)) {
                Response::notFound(['message' => 'Log file not found']);
                return;
            }

            // Get pagination parameters
            $pagination = Request::pagination();
            $lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
            $lines = max(1, min(1000, $lines)); // Limit between 1 and 1000

            // Get log level filter
            $levelFilter = $_GET['level'] ?? null;
            
            // Read file in reverse order (newest first)
            $content = file_get_contents($filePath);
            $allLines = explode("\n", $content);
            $allLines = array_reverse($allLines);
            
            // Filter empty lines
            $allLines = array_filter($allLines, function($line) {
                return trim($line) !== '';
            });

            // Apply level filter if specified
            if ($levelFilter) {
                $allLines = array_filter($allLines, function($line) use ($levelFilter) {
                    return stripos($line, strtoupper($levelFilter)) !== false;
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
            Response::error('Failed to read log file: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get recent log entries
     * GET /api/logs/recent
     */
    public function recent(): void
    {
        try {
            $lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 50;
            $lines = max(1, min(500, $lines));
            
            $levelFilter = $_GET['level'] ?? null;

            $config = Config::getInstance();
            $logPath = $config->get('paths.logs') . '/app.log';

            if (!file_exists($logPath)) {
                Response::success(['entries' => []]);
                return;
            }

            // Read last N lines efficiently
            $content = $this->tail($logPath, $lines * 2); // Read more to account for filtering
            $allLines = explode("\n", $content);
            $allLines = array_reverse($allLines);
            
            // Filter empty lines
            $allLines = array_filter($allLines, function($line) {
                return trim($line) !== '';
            });

            // Apply level filter if specified
            if ($levelFilter) {
                $allLines = array_filter($allLines, function($line) use ($levelFilter) {
                    return stripos($line, strtoupper($levelFilter)) !== false;
                });
            }

            // Limit to requested number
            $allLines = array_slice($allLines, 0, $lines);

            // Parse log lines
            $entries = array_map(function($line) {
                return $this->parseLogLine($line);
            }, $allLines);

            Response::success(['entries' => $entries]);
        } catch (Exception $e) {
            Response::error('Failed to read recent logs: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Parse a log line into components
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
     */
    private function tail(string $filepath, int $lines = 50): string
    {
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return '';
        }

        $linecounter = $lines;
        $pos = -2;
        $beginning = false;
        $text = [];

        fseek($handle, $pos, SEEK_END);

        while ($linecounter > 0) {
            $t = fgetc($handle);
            if ($t == "\n") {
                $linecounter--;
            }
            
            if ($pos == -1) {
                rewind($handle);
                $beginning = true;
                break;
            }
            
            $text[] = $t;
            $pos--;
            fseek($handle, $pos, SEEK_END);
        }

        fclose($handle);

        if ($beginning) {
            $text = array_reverse($text);
        }

        return implode('', $text);
    }

    /**
     * Clear old log files
     * DELETE /api/logs/cleanup
     */
    public function cleanup(): void
    {
        try {
            $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
            $days = max(1, min(90, $days));

            $config = Config::getInstance();
            $logPath = $config->get('paths.logs');
            
            $deleted = [];
            $logFiles = glob($logPath . '/*.log');
            $cutoffTime = time() - ($days * 86400);

            if ($logFiles) {
                foreach ($logFiles as $file) {
                    // Don't delete the current log file
                    if (basename($file) === 'app.log') {
                        continue;
                    }

                    if (filemtime($file) < $cutoffTime) {
                        if (unlink($file)) {
                            $deleted[] = basename($file);
                        }
                    }
                }
            }

            Response::success([
                'message' => count($deleted) . ' log file(s) deleted',
                'deleted' => $deleted
            ]);
        } catch (Exception $e) {
            Response::error('Failed to cleanup logs: ' . $e->getMessage(), 500);
        }
    }
}
