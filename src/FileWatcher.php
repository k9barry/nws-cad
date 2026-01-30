<?php

declare(strict_types=1);

namespace NwsCad;

use Exception;

/**
 * File Watcher Service
 * Monitors a directory for new XML files and processes them
 *
 * @package NwsCad
 * @version 1.0.0
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

    /**
     * Constructor - Initialize file watcher
     *
     * @throws Exception If database connection fails
     */
    public function __construct()
    {
        $config = Config::getInstance();
        $this->watchFolder = $config->get('watcher.folder');
        $this->interval = $config->get('watcher.interval');
        $this->filePattern = $config->get('watcher.file_pattern');
        $this->logger = Logger::getInstance();

        $this->logger->info("Initializing File Watcher Service");
        $this->logger->info("Watch Folder: {$this->watchFolder}");
        $this->logger->info("File Pattern: {$this->filePattern}");
        $this->logger->info("Check Interval: {$this->interval} seconds");

        // Wait for database BEFORE creating parser
        $this->logger->info("Waiting for database to be ready...");
        $this->waitForDatabase();
        
        // Now create parser after database is ready
        $this->logger->info("Creating AegisXmlParser instance");
        $this->parser = new AegisXmlParser();

        // Ensure watch folder exists
        if (!is_dir($this->watchFolder)) {
            $this->logger->info("Creating watch folder: {$this->watchFolder}");
            mkdir($this->watchFolder, 0755, true);
        }

        // Verify folder permissions
        $perms = substr(sprintf('%o', fileperms($this->watchFolder)), -4);
        $this->logger->info("Watch folder permissions: {$perms}");

        // Setup signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            $this->logger->info("Signal handlers registered");
        }
    }

    /**
     * Handle shutdown signals
     *
     * @param int $signal Signal number
     * @return void
     */
    public function handleSignal(int $signal): void
    {
        $this->logger->info("Received signal $signal, shutting down gracefully...");
        $this->running = false;
    }

    /**
     * Start watching the folder
     *
     * @return void
     */
    public function start(): void
    {
        $this->logger->info("=== File Watcher Started ===");
        $this->logger->info("Watching folder: {$this->watchFolder}");
        $this->logger->info("File pattern: {$this->filePattern}");
        $this->logger->info("Check interval: {$this->interval} seconds");

        // Test database connectivity before starting
        if (!Database::testConnection()) {
            $this->logger->error("Database connection failed, waiting for database...");
            $this->waitForDatabase();
        }

        $checkCount = 0;
        while ($this->running) {
            try {
                $checkCount++;
                $this->logger->info("=== Check #{$checkCount} - Scanning watch folder ===");
                $this->checkForNewFiles();
                
                // Process signals if available
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                
                $this->logger->info("Sleeping for {$this->interval} seconds...");
                sleep($this->interval);
            } catch (Exception $e) {
                $this->logger->error("Error in watch loop: " . $e->getMessage());
                $this->logger->error("Stack trace: " . $e->getTraceAsString());
                sleep($this->interval);
            }
        }

        $this->logger->info("File watcher stopped");
    }

    /**
     * Wait for database to become available
     *
     * @return void
     * @throws Exception If database connection fails after max retries
     */
    private function waitForDatabase(): void
    {
        $maxRetries = 30;
        $retryDelay = 2;
        
        for ($i = 0; $i < $maxRetries; $i++) {
            $this->logger->info("Attempting to connect to database (attempt " . ($i + 1) . "/$maxRetries)");
            
            if (Database::testConnection()) {
                $this->logger->info("✓ Database connection established successfully");
                return;
            }
            
            $this->logger->warning("Database not ready, waiting {$retryDelay} seconds...");
            sleep($retryDelay);
        }
        
        throw new Exception("Failed to connect to database after $maxRetries attempts");
    }

    /**
     * Check for new files in the watch folder
     *
     * @return void
     */
    private function checkForNewFiles(): void
    {
        $files = $this->scanDirectory($this->watchFolder);
        
        $fileCount = count($files);
        $this->logger->info("Found {$fileCount} XML file(s) in watch folder");
        
        if ($fileCount > 0) {
            foreach ($files as $index => $file) {
                $filename = basename($file);
                $this->logger->info("  [{$index}] {$filename}");
            }
        } else {
            $this->logger->info("No XML files found in watch folder");
            return;
        }

        // Use FilenameParser to identify latest files only
        $filenames = array_map('basename', $files);
        $latestFilenames = FilenameParser::getLatestFiles($filenames);
        $filesToSkip = FilenameParser::getFilesToSkip($filenames);
        
        $this->logger->info("--- File Version Analysis ---");
        $grouped = FilenameParser::groupByCallNumber($filenames);
        $this->logger->info("Unique call numbers: " . count($grouped));
        
        foreach ($grouped as $callNumber => $callFiles) {
            if (count($callFiles) > 1) {
                $this->logger->info("Call {$callNumber}: {$callFiles[count($callFiles) - 1]} files found, latest will be processed");
            }
        }
        
        if (count($filesToSkip) > 0) {
            $this->logger->info("Skipping " . count($filesToSkip) . " older versions:");
            foreach ($filesToSkip as $skipFile) {
                $this->logger->info("  ✗ {$skipFile} (older version)");
            }
        }

        $processedCount = 0;
        $skippedCount = 0;
        $versionSkippedCount = count($filesToSkip);
        
        foreach ($files as $file) {
            $filename = basename($file);
            
            // Skip if this is an older version
            if (in_array($filename, $filesToSkip)) {
                $skippedCount++;
                continue;
            }
            
            $this->logger->info("--- Checking file: {$filename} ---");
            
            if ($this->shouldProcessFile($file)) {
                $this->logger->info("✓ File passed checks, processing: {$filename}");
                $this->processFile($file);
                $processedCount++;
            } else {
                $this->logger->info("✗ File skipped: {$filename}");
                $skippedCount++;
            }
        }

        $this->logger->info("Summary: {$processedCount} processed, {$skippedCount} skipped ({$versionSkippedCount} older versions), {$fileCount} total");

        // Clean up old processed files from memory (keep last 1000)
        if (count($this->processedFiles) > 1000) {
            $removed = count($this->processedFiles) - 1000;
            $this->processedFiles = array_slice($this->processedFiles, -1000, 1000, true);
            $this->logger->info("Cleaned up {$removed} old entries from memory");
        }
    }

    /**
     * Scan directory for files matching pattern (non-recursive)
     * Only scans the root watch folder, excludes subdirectories
     *
     * @param string $directory Directory to scan
     * @return array<string> Array of file paths
     */
    private function scanDirectory(string $directory): array
    {
        $files = [];
        
        try {
            $pattern = $this->convertGlobToRegex($this->filePattern);
            $this->logger->info("Scanning directory: {$directory}");
            $this->logger->info("Using pattern: {$this->filePattern} -> {$pattern}");
            
            // Only scan the root directory, not subdirectories
            $items = scandir($directory);
            
            if ($items === false) {
                throw new Exception("Failed to scan directory: $directory");
            }
            
            $this->logger->info("Total items in directory: " . count($items));
            
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                
                $fullPath = $directory . '/' . $item;
                
                // Skip directories (including processed/failed)
                if (is_dir($fullPath)) {
                    $this->logger->info("  Skipping directory: {$item}");
                    continue;
                }
                
                // Check if matches pattern
                $matches = preg_match($pattern, $item);
                $this->logger->info("  File: {$item} - Pattern match: " . ($matches ? 'YES' : 'NO'));
                
                if ($matches) {
                    $files[] = $fullPath;
                }
            }
        } catch (Exception $e) {
            $this->logger->error("Error scanning directory: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
        }

        return $files;
    }

    /**
     * Convert glob pattern to regex
     *
     * @param string $glob Glob pattern (e.g., *.xml)
     * @return string Regular expression pattern
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
     *
     * @param string $filePath Full path to file
     * @return bool True if file should be processed
     */
    private function shouldProcessFile(string $filePath): bool
    {
        $filename = basename($filePath);
        
        $this->logger->info("  → Checking if file is stable: {$filename}");
        
        // Check if file is still being written (size changes)
        if (!$this->isFileStable($filePath)) {
            $this->logger->info("  ✗ File not stable, skipping: {$filename}");
            return false;
        }
        
        $this->logger->info("  ✓ File is stable");

        // Check if already processed in this session
        $fileKey = md5($filePath . filesize($filePath) . filemtime($filePath));
        $this->logger->info("  → Generated file key: {$fileKey}");
        
        if (isset($this->processedFiles[$fileKey])) {
            $processedTime = date('Y-m-d H:i:s', $this->processedFiles[$fileKey]);
            $this->logger->info("  ✗ File already processed in this session at {$processedTime}: {$filename}");
            return false;
        }
        
        $this->logger->info("  ✓ File not yet processed in this session");
        $this->logger->info("  ✓ File ready to process: {$filename}");
        return true;
    }

    /**
     * Check if file size is stable (not being written)
     *
     * @param string $filePath Full path to file
     * @param int $checkInterval Seconds to wait between size checks
     * @return bool True if file size is stable
     */
    private function isFileStable(string $filePath, int $checkInterval = 1): bool
    {
        if (!file_exists($filePath)) {
            $this->logger->warning("  File does not exist: {$filePath}");
            return false;
        }

        $size1 = filesize($filePath);
        $this->logger->info("  Initial size: {$size1} bytes");
        
        sleep($checkInterval);
        clearstatcache(true, $filePath);
        $size2 = filesize($filePath);
        
        $isStable = $size1 === $size2;
        $this->logger->info("  Final size: {$size2} bytes - Stable: " . ($isStable ? 'YES' : 'NO'));

        return $isStable;
    }

    /**
     * Process a single file
     *
     * @param string $filePath Full path to file
     * @return void
     */
    private function processFile(string $filePath): void
    {
        $filename = basename($filePath);
        $this->logger->info("►►► Processing file: {$filename}");

        try {
            // Mark as being processed
            $fileKey = md5($filePath . filesize($filePath) . filemtime($filePath));
            $this->processedFiles[$fileKey] = time();
            $this->logger->info("  Marked as processing with key: {$fileKey}");

            // Process the file
            $this->logger->info("  Calling AegisXmlParser->processFile()");
            $success = $this->parser->processFile($filePath);

            if ($success) {
                $this->logger->info("  ✓ File processed successfully");
                $this->moveToProcessed($filePath);
            } else {
                $this->logger->error("  ✗ File processing failed");
                $this->moveToFailed($filePath);
            }
        } catch (Exception $e) {
            $this->logger->error("  ✗ Exception during processing: " . $e->getMessage());
            $this->logger->error("  Stack trace: " . $e->getTraceAsString());
            $this->moveToFailed($filePath);
        }
    }

    /**
     * Move file to processed folder
     *
     * @param string $filePath Full path to file
     * @return void
     */
    private function moveToProcessed(string $filePath): void
    {
        $processedFolder = $this->watchFolder . '/processed';
        if (!is_dir($processedFolder)) {
            mkdir($processedFolder, 0755, true);
            $this->logger->info("Created processed folder: {$processedFolder}");
        }

        $destination = $processedFolder . '/' . basename($filePath);
        $destination = $this->getUniqueFilename($destination);

        if (rename($filePath, $destination)) {
            $this->logger->info("✓ File moved to processed: " . basename($destination));
        } else {
            $this->logger->error("✗ Failed to move file to processed folder");
        }
    }

    /**
     * Move file to failed folder
     *
     * @param string $filePath Full path to file
     * @return void
     */
    private function moveToFailed(string $filePath): void
    {
        $failedFolder = $this->watchFolder . '/failed';
        if (!is_dir($failedFolder)) {
            mkdir($failedFolder, 0755, true);
            $this->logger->info("Created failed folder: {$failedFolder}");
        }

        $destination = $failedFolder . '/' . basename($filePath);
        $destination = $this->getUniqueFilename($destination);

        if (rename($filePath, $destination)) {
            $this->logger->info("✓ File moved to failed: " . basename($destination));
        } else {
            $this->logger->error("✗ Failed to move file to failed folder");
        }
    }

    /**
     * Get unique filename if file already exists
     *
     * @param string $path Desired file path
     * @return string Unique file path
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
