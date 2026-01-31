#!/usr/bin/env php
<?php

/**
 * File Watcher Daemon
 * Entry point for the file watching service
 */

require_once __DIR__ . '/../vendor/autoload.php';

use NwsCad\FileWatcher;
use NwsCad\Logger;
use NwsCad\Config;

// Initialize logger once
$logger = Logger::getInstance();
$config = Config::getInstance();

try {
    $logLevel = strtoupper($config->get('app.log_level', 'INFO'));
    $logger->info("Starting NWS CAD File Watcher Service");
    $logger->info("Log level: {$logLevel}");
    $logger->debug("Debug logging is enabled - detailed step-by-step information will be shown");
    $logger->debug("Using Aegis CAD XML Parser for New World Systems format");
    
    $watcher = new FileWatcher();
    $watcher->start();
    
} catch (Exception $e) {
    $logger->error("Fatal error: " . $e->getMessage());
    $logger->error("Stack trace: " . $e->getTraceAsString());
    exit(1);
}
