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
    private ParserInterface $parser;
    private $logger;
    private bool $running = true;
    private string $heartbeatPath;
    /** @var (callable():void)|null */
    private $onTick = null;
    /** @var callable(int):void */
    private $sleep;

    public function setOnTick(?callable $cb): void
    {
        $this->onTick = $cb;
    }

    /**
     * Constructor - Initialize file watcher.
     *
     * All parameters are optional test/injection seams. With no arguments the
     * watcher behaves exactly as in production: configuration is read from the
     * {@see Config} singleton, it blocks on {@see waitForDatabase()}, and it
     * constructs a real {@see AegisXmlParser}. Passing a parser (and optional
     * config/sleep overrides) bypasses the database wait so the file-handling
     * logic can be unit-tested against real temp directories with no database.
     *
     * @param ParserInterface|null $parser    Injected ingest step; null → real AegisXmlParser (after DB wait).
     * @param array<string,mixed>|null $config Override watcher config. Recognized keys:
     *                                         'folder', 'interval', 'file_pattern', 'heartbeat_path'.
     *                                         When provided, the Config singleton and DB wait are skipped.
     * @param callable(int):void|null $sleep   Sleep seam (seconds → void); null → PHP sleep().
     * @throws Exception If database connection fails (production path only).
     */
    public function __construct(
        ?ParserInterface $parser = null,
        ?array $config = null,
        ?callable $sleep = null
    ) {
        $this->logger = Logger::getInstance();
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };

        if ($config !== null) {
            // Injection mode: caller supplies configuration and (optionally) a
            // parser. The DB wait and Config singleton are skipped so unit
            // tests need neither a database nor environment configuration.
            $this->watchFolder = (string) $config['folder'];
            $this->interval = (int) ($config['interval'] ?? 5);
            $this->filePattern = (string) ($config['file_pattern'] ?? '*.xml');
            $this->heartbeatPath = (string) ($config['heartbeat_path']
                ?? rtrim($this->watchFolder, '/') . '/.watcher-heartbeat');
            // A caller that supplies config but no parser still gets a real
            // AegisXmlParser, which connects to the database on construction —
            // so we must wait for the DB here too, exactly as the production
            // path does. Only an injected parser (the unit-test case) is
            // allowed to skip the wait.
            if ($parser !== null) {
                $this->parser = $parser;
            } else {
                $this->waitForDatabase();
                $this->parser = new AegisXmlParser();
            }
        } else {
            $configSingleton = Config::getInstance();
            $this->watchFolder = $configSingleton->get('watcher.folder');
            $this->interval = $configSingleton->get('watcher.interval');
            $this->filePattern = $configSingleton->get('watcher.file_pattern');
            $this->heartbeatPath = rtrim($configSingleton->get('paths.logs'), '/') . '/.watcher-heartbeat';

            $this->logger->info("Initializing File Watcher Service");
            $this->logger->debug("Loading configuration from Config singleton");
            $this->logger->debug("Watch Folder: {$this->watchFolder}");
            $this->logger->debug("File Pattern: {$this->filePattern}");
            $this->logger->debug("Check Interval: {$this->interval} seconds");

            // Wait for database BEFORE creating parser
            $this->logger->info("Waiting for database connection...");
            $this->logger->debug("Starting database connection wait loop");
            $this->waitForDatabase();

            // Now create parser after database is ready
            $this->logger->debug("Creating AegisXmlParser instance");
            $this->parser = $parser ?? new AegisXmlParser();
            $this->logger->debug("AegisXmlParser instance created successfully");
        }

        // Ensure watch folder exists
        if (!is_dir($this->watchFolder)) {
            $this->logger->debug("Watch folder does not exist, creating: {$this->watchFolder}");
            mkdir($this->watchFolder, 0755, true);
            $this->logger->info("Created watch folder: {$this->watchFolder}");
        }

        // Verify folder permissions
        $perms = substr(sprintf('%o', fileperms($this->watchFolder)), -4);
        $this->logger->debug("Watch folder permissions: {$perms}");

        // Setup signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            $this->logger->debug("Signal handlers registered for SIGTERM and SIGINT");
        }

        $this->logger->info("File Watcher Service initialized successfully");
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
        $this->logger->debug("Configuration: folder={$this->watchFolder}, pattern={$this->filePattern}, interval={$this->interval}s");
        $this->touchHeartbeat();

        // Test database connectivity before starting
        $this->logger->debug("Testing database connectivity before entering main loop");
        if (!Database::testConnection()) {
            $this->logger->error("Database connection failed, waiting for database...");
            $this->waitForDatabase();
        }

        $checkCount = 0;
        while ($this->running) {
            try {
                $checkCount++;
                $this->touchHeartbeat();
                $this->logger->debug("=== Check #{$checkCount} - Starting folder scan ===");
                $this->checkForNewFiles();

                if ($this->onTick !== null) {
                    try {
                        ($this->onTick)();
                    } catch (\Throwable $t) {
                        $this->logger->error("onTick callback failed: " . $t->getMessage());
                    }
                }

                // Process signals if available
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                
                $this->logger->debug("Sleeping for {$this->interval} seconds before next check...");
                ($this->sleep)($this->interval);
            } catch (Exception $e) {
                $this->logger->error("Error in watch loop: " . $e->getMessage());
                $this->logger->error("Stack trace: " . $e->getTraceAsString());
                ($this->sleep)($this->interval);
            }
        }

        $this->logger->info("File watcher stopped");
    }

    /**
     * Update the watcher heartbeat file. Consumed by the docker healthcheck
     * for the `app` service: a stale mtime grades the container unhealthy.
     */
    private function touchHeartbeat(): void
    {
        // Suppressed — heartbeat write failure must not crash the watcher
        // loop. A persistently failing touch will manifest as an unhealthy
        // container, which is the correct signal.
        @touch($this->heartbeatPath);
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
            $this->logger->debug("Attempting database connection (attempt " . ($i + 1) . "/$maxRetries)");
            
            if (Database::testConnection()) {
                $this->logger->info("Database connection established successfully");
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
        $this->logger->debug("Scanning watch folder for new files...");
        $files = $this->scanDirectory($this->watchFolder);
        
        $fileCount = count($files);
        $this->logger->debug("Found {$fileCount} XML file(s) in watch folder");
        
        if ($fileCount > 0) {
            foreach ($files as $index => $file) {
                $filename = basename($file);
                $this->logger->debug("  File [{$index}]: {$filename}");
            }
        } else {
            $this->logger->debug("Scan complete: 0 files found, 0 processed, 0 skipped");
            return;
        }

        // Use FilenameParser to identify latest files only
        $this->logger->debug("Analyzing filenames to identify latest versions...");
        $filenames = array_map('basename', $files);
        
        // Identify unparseable files (e.g., files with tildes)
        $unparseableFilenames = FilenameParser::getUnparseableFilenames($filenames);
        
        // Move unparseable files to failed folder
        if (count($unparseableFilenames) > 0) {
            $this->logger->info("Found " . count($unparseableFilenames) . " unparseable file(s), moving to failed");
            foreach ($unparseableFilenames as $unparseableFile) {
                $this->logger->info("  Moving unparseable file: {$unparseableFile}");
                $fullPath = $this->watchFolder . '/' . $unparseableFile;
                if (file_exists($fullPath)) {
                    $this->moveToFailed($fullPath);
                }
            }
        }
        
        // Filter out unparseable files from further processing
        $filenames = array_diff($filenames, $unparseableFilenames);
        $files = array_filter($files, function($file) use ($unparseableFilenames) {
            return !in_array(basename($file), $unparseableFilenames);
        });
        
        // Continue with normal version analysis for parseable files
        $latestFilenames = FilenameParser::getLatestFiles($filenames);
        $filesToSkip = FilenameParser::getFilesToSkip($filenames);
        
        $this->logger->debug("File version analysis complete");
        $grouped = FilenameParser::groupByCallNumber($filenames);
        $this->logger->debug("Unique call numbers: " . count($grouped));
        
        foreach ($grouped as $callNumber => $callFiles) {
            if (count($callFiles) > 1) {
                $this->logger->debug("Call {$callNumber}: " . count($callFiles) . " versions found, processing latest only");
            }
        }
        
        if (count($filesToSkip) > 0) {
            $this->logger->debug("Skipping " . count($filesToSkip) . " older file versions");
            foreach ($filesToSkip as $skipFile) {
                $this->logger->debug("  Skipping: {$skipFile} (older version)");
            }
        }

        $processedCount = 0;
        $skippedCount = 0;
        $versionSkippedCount = count($filesToSkip);
        $unparseableCount = count($unparseableFilenames);
        
        foreach ($files as $file) {
            $filename = basename($file);
            
            // Skip if this is an older version
            if (in_array($filename, $filesToSkip)) {
                $skippedCount++;
                continue;
            }
            
            $this->logger->debug("Checking file: {$filename}");
            
            if ($this->shouldProcessFile($file)) {
                $this->logger->info("Processing file: {$filename}");
                $this->processFile($file);
                $processedCount++;
            } else {
                $this->logger->debug("File skipped (already processed or unstable): {$filename}");
                $skippedCount++;
            }
        }

        if ($fileCount > 0 || $unparseableCount > 0) {
            $this->logger->info("Scan complete: {$processedCount} processed, {$skippedCount} skipped ({$versionSkippedCount} older versions, {$unparseableCount} unparseable)");
        }

        // Clean up old processed files from memory (keep last 1000)
        if (count($this->processedFiles) > 1000) {
            $removed = count($this->processedFiles) - 1000;
            $this->processedFiles = array_slice($this->processedFiles, -1000, 1000, true);
            $this->logger->debug("Memory cleanup: removed {$removed} old file entries from tracking");
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
            $this->logger->debug("Scanning directory: {$directory}");
            $this->logger->debug("Using regex pattern: {$pattern}");
            
            // Only scan the root directory, not subdirectories
            $items = scandir($directory);
            
            if ($items === false) {
                throw new Exception("Failed to scan directory: $directory");
            }
            
            $this->logger->debug("Total items in directory: " . count($items));
            
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                
                $fullPath = $directory . '/' . $item;
                
                // Skip directories (including processed/failed)
                if (is_dir($fullPath)) {
                    $this->logger->debug("  Skipping subdirectory: {$item}");
                    continue;
                }
                
                // Check if matches pattern
                $matches = preg_match($pattern, $item);
                $this->logger->debug("  File: {$item} - Pattern match: " . ($matches ? 'YES' : 'NO'));
                
                if ($matches) {
                    $files[] = $fullPath;
                }
            }
        } catch (Exception $e) {
            $this->logger->error("Error scanning directory: " . $e->getMessage());
            $this->logger->debug("Stack trace: " . $e->getTraceAsString());
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
        
        $this->logger->debug("  Checking file stability: {$filename}");
        
        // Check if file is still being written (size changes)
        if (!$this->isFileStable($filePath)) {
            $this->logger->debug("  File not stable (still being written): {$filename}");
            return false;
        }
        
        $this->logger->debug("  File is stable");

        // Check if already processed in this session
        $fileKey = md5($filePath . filesize($filePath) . filemtime($filePath));
        $this->logger->debug("  Generated file key: {$fileKey}");
        
        if (isset($this->processedFiles[$fileKey])) {
            $processedTime = date('Y-m-d H:i:s', $this->processedFiles[$fileKey]);
            $this->logger->debug("  File already processed at {$processedTime}: {$filename}");
            return false;
        }
        
        $this->logger->debug("  File ready for processing: {$filename}");
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
        $this->logger->debug("  Initial file size: {$size1} bytes");

        ($this->sleep)($checkInterval);
        clearstatcache(true, $filePath);
        $size2 = filesize($filePath);
        
        $isStable = $size1 === $size2;
        $this->logger->debug("  Final file size: {$size2} bytes - Stable: " . ($isStable ? 'YES' : 'NO'));

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
        $this->logger->debug("Starting file processing: {$filename}");

        try {
            // Mark as being processed
            $fileKey = md5($filePath . filesize($filePath) . filemtime($filePath));
            $this->processedFiles[$fileKey] = time();
            $this->logger->debug("  Marked file with key: {$fileKey}");

            // Process the file
            $this->logger->debug("  Invoking AegisXmlParser->processFile()");
            $success = $this->parser->processFile($filePath);

            if ($success) {
                $this->logger->info("File processed successfully: {$filename}");
                $this->moveToProcessed($filePath);
            } else {
                $this->logger->error("File processing failed: {$filename}");
                $this->moveToFailed($filePath);
            }
        } catch (Exception $e) {
            $this->logger->error("Exception during file processing: " . $e->getMessage());
            $this->logger->debug("Stack trace: " . $e->getTraceAsString());
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
            $this->logger->debug("Created processed folder: {$processedFolder}");
        }

        $destination = $processedFolder . '/' . basename($filePath);
        $destination = $this->getUniqueFilename($destination);

        $this->logger->debug("Moving file to processed folder: " . basename($destination));
        if (rename($filePath, $destination)) {
            $this->logger->debug("File moved successfully to processed: " . basename($destination));
        } else {
            $this->logger->error("Failed to move file to processed folder: " . basename($filePath));
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
            $this->logger->debug("Created failed folder: {$failedFolder}");
        }

        $destination = $failedFolder . '/' . basename($filePath);
        $destination = $this->getUniqueFilename($destination);

        $this->logger->debug("Moving file to failed folder: " . basename($destination));
        if (rename($filePath, $destination)) {
            $this->logger->debug("File moved to failed: " . basename($destination));
        } else {
            $this->logger->error("Failed to move file to failed folder: " . basename($filePath));
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
