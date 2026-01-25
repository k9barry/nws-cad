<?php

namespace NwsCad;

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * File Watcher Service
 * Monitors a directory for new XML files and processes them
 */
class FileWatcher
{
    private string $watchFolder;
    private int $interval;
    private string $filePattern;
    private array $processedFiles = [];
    private AegisXmlParser $parser;
    private $logger;
    private bool $running = true;

    public function __construct()
    {
        $config = Config::getInstance();
        $this->watchFolder = $config->get('watcher.folder');
        $this->interval = $config->get('watcher.interval');
        $this->filePattern = $config->get('watcher.file_pattern');
        $this->parser = new AegisXmlParser();
        $this->logger = Logger::getInstance();

        // Ensure watch folder exists
        if (!is_dir($this->watchFolder)) {
            mkdir($this->watchFolder, 0755, true);
        }

        // Setup signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }
    }

    /**
     * Handle shutdown signals
     */
    public function handleSignal(int $signal): void
    {
        $this->logger->info("Received signal $signal, shutting down gracefully...");
        $this->running = false;
    }

    /**
     * Start watching the folder
     */
    public function start(): void
    {
        $this->logger->info("File watcher started");
        $this->logger->info("Watching folder: {$this->watchFolder}");
        $this->logger->info("File pattern: {$this->filePattern}");
        $this->logger->info("Check interval: {$this->interval} seconds");

        // Test database connectivity before starting
        if (!Database::testConnection()) {
            $this->logger->error("Database connection failed, waiting for database...");
            $this->waitForDatabase();
        }

        while ($this->running) {
            try {
                $this->checkForNewFiles();
                
                // Process signals if available
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                
                sleep($this->interval);
            } catch (Exception $e) {
                $this->logger->error("Error in watch loop: " . $e->getMessage());
                sleep($this->interval);
            }
        }

        $this->logger->info("File watcher stopped");
    }

    /**
     * Wait for database to become available
     */
    private function waitForDatabase(): void
    {
        $maxRetries = 30;
        $retryDelay = 2;
        
        for ($i = 0; $i < $maxRetries; $i++) {
            $this->logger->info("Attempting to connect to database (attempt " . ($i + 1) . "/$maxRetries)");
            
            if (Database::testConnection()) {
                $this->logger->info("Database connection established");
                return;
            }
            
            sleep($retryDelay);
        }
        
        throw new Exception("Failed to connect to database after $maxRetries attempts");
    }

    /**
     * Check for new files in the watch folder
     */
    private function checkForNewFiles(): void
    {
        $files = $this->scanDirectory($this->watchFolder);

        foreach ($files as $file) {
            if ($this->shouldProcessFile($file)) {
                $this->processFile($file);
            }
        }

        // Clean up old processed files from memory (keep last 1000)
        if (count($this->processedFiles) > 1000) {
            $this->processedFiles = array_slice($this->processedFiles, -1000, 1000, true);
        }
    }

    /**
     * Scan directory for files matching pattern
     */
    private function scanDirectory(string $directory): array
    {
        $files = [];
        
        try {
            $pattern = $this->convertGlobToRegex($this->filePattern);
            
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && preg_match($pattern, $file->getFilename())) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (Exception $e) {
            $this->logger->error("Error scanning directory: " . $e->getMessage());
        }

        return $files;
    }

    /**
     * Convert glob pattern to regex
     */
    private function convertGlobToRegex(string $glob): string
    {
        $regex = preg_quote($glob, '/');
        $regex = str_replace('\*', '.*', $regex);
        $regex = str_replace('\?', '.', $regex);
        return '/^' . $regex . '$/i';
    }

    /**
     * Check if file should be processed
     */
    private function shouldProcessFile(string $filePath): bool
    {
        // Check if file is still being written (size changes)
        if (!$this->isFileStable($filePath)) {
            return false;
        }

        // Check if already processed in this session
        $fileKey = md5($filePath . filesize($filePath) . filemtime($filePath));
        if (isset($this->processedFiles[$fileKey])) {
            return false;
        }

        return true;
    }

    /**
     * Check if file size is stable (not being written)
     */
    private function isFileStable(string $filePath, int $checkInterval = 1): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $size1 = filesize($filePath);
        sleep($checkInterval);
        clearstatcache(true, $filePath);
        $size2 = filesize($filePath);

        return $size1 === $size2;
    }

    /**
     * Process a single file
     */
    private function processFile(string $filePath): void
    {
        $filename = basename($filePath);
        $this->logger->info("New file detected: $filename");

        try {
            // Mark as being processed
            $fileKey = md5($filePath . filesize($filePath) . filemtime($filePath));
            $this->processedFiles[$fileKey] = time();

            // Process the file
            $success = $this->parser->processFile($filePath);

            if ($success) {
                $this->moveToProcessed($filePath);
            } else {
                $this->moveToFailed($filePath);
            }
        } catch (Exception $e) {
            $this->logger->error("Failed to process file $filename: " . $e->getMessage());
            $this->moveToFailed($filePath);
        }
    }

    /**
     * Move file to processed folder
     */
    private function moveToProcessed(string $filePath): void
    {
        $processedFolder = $this->watchFolder . '/processed';
        if (!is_dir($processedFolder)) {
            mkdir($processedFolder, 0755, true);
        }

        $destination = $processedFolder . '/' . basename($filePath);
        $destination = $this->getUniqueFilename($destination);

        if (rename($filePath, $destination)) {
            $this->logger->info("File moved to processed: " . basename($destination));
        } else {
            $this->logger->warning("Failed to move file to processed folder");
        }
    }

    /**
     * Move file to failed folder
     */
    private function moveToFailed(string $filePath): void
    {
        $failedFolder = $this->watchFolder . '/failed';
        if (!is_dir($failedFolder)) {
            mkdir($failedFolder, 0755, true);
        }

        $destination = $failedFolder . '/' . basename($filePath);
        $destination = $this->getUniqueFilename($destination);

        if (rename($filePath, $destination)) {
            $this->logger->info("File moved to failed: " . basename($destination));
        } else {
            $this->logger->warning("Failed to move file to failed folder");
        }
    }

    /**
     * Get unique filename if file already exists
     */
    private function getUniqueFilename(string $path): string
    {
        if (!file_exists($path)) {
            return $path;
        }

        $info = pathinfo($path);
        $counter = 1;

        do {
            $newPath = $info['dirname'] . '/' . $info['filename'] . '_' . $counter;
            if (isset($info['extension'])) {
                $newPath .= '.' . $info['extension'];
            }
            $counter++;
        } while (file_exists($newPath));

        return $newPath;
    }
}
