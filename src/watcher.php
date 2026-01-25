#!/usr/bin/env php
<?php

/**
 * File Watcher Daemon
 * Entry point for the file watching service
 */

require_once __DIR__ . '/../vendor/autoload.php';

use NwsCad\FileWatcher;
use NwsCad\Logger;

// Initialize logger once
$logger = Logger::getInstance();

try {
    $logger->info("Starting NWS CAD File Watcher Service");
    
    $watcher = new FileWatcher();
    $watcher->start();
    
} catch (Exception $e) {
    $logger->error("Fatal error: " . $e->getMessage());
    $logger->error("Stack trace: " . $e->getTraceAsString());
    exit(1);
}
