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
use NwsCad\Database;
use NwsCad\Notifications\ChannelRepository;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\Channels\PushoverChannel;
use NwsCad\Notifications\EventDispatcher;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationChannel;
use NwsCad\Notifications\NotificationDispatcher;
use NwsCad\Notifications\Events\CallProcessedEvent;

// Initialize logger once
$logger = Logger::getInstance();
$config = Config::getInstance();

try {
    $logLevel = strtoupper($config->get('app.log_level', 'INFO'));
    $logger->info("Starting NWS CAD File Watcher Service");
    $logger->info("Log level: {$logLevel}");
    $logger->debug("Debug logging is enabled - detailed step-by-step information will be shown");
    $logger->debug("Using Aegis CAD XML Parser for New World Systems format");
    
    $deltaSeconds = (int) Config::getInstance()->get('notifications.delta_seconds', 900);

    $incidentLoader = function (int $dbCallId): IncidentDto {
        $db = Database::getConnection();
        $jurisdictionAgg = \NwsCad\Api\DbHelper::groupConcat('jurisdiction', '|');
        $unitsAgg        = \NwsCad\Api\DbHelper::groupConcat('unit_number', '|');
        $narrativeAgg    = \NwsCad\Api\DbHelper::groupConcat('text', ' ');
        $stmt = $db->prepare(
            "SELECT
                c.id, c.call_id, c.call_number, c.alarm_level, c.create_datetime,
                c.nature_of_call,
                ac.call_type, ac.agency_type,
                l.full_address, l.nearest_cross_streets, l.latitude_y AS latitude, l.longitude_x AS longitude,
                (SELECT {$jurisdictionAgg} FROM incidents WHERE call_id = c.id) AS jurisdiction,
                (SELECT {$unitsAgg}        FROM units     WHERE call_id = c.id) AS units,
                (SELECT {$narrativeAgg}    FROM narratives WHERE call_id = c.id) AS narrative
             FROM calls c
             LEFT JOIN agency_contexts ac ON ac.call_id = c.id
             LEFT JOIN locations l ON l.call_id = c.id
             WHERE c.id = ?
             LIMIT 1"
        );
        $stmt->execute([$dbCallId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['id' => $dbCallId];
        return IncidentDto::fromRow($row);
    };

    $channelFactoryInstance = new \NwsCad\Notifications\ChannelFactory($config);
    $channelFactory = [$channelFactoryInstance, 'create'];

    $notificationDispatcher = new NotificationDispatcher(
        channelRepo: new ChannelRepository(),
        incidentLoader: $incidentLoader,
        channelFactory: $channelFactory,
        deltaSeconds: $deltaSeconds,
    );

    EventDispatcher::subscribe(function (CallProcessedEvent $e) use ($notificationDispatcher): void {
        $notificationDispatcher->handle($e);
    });

    $watcher = new FileWatcher();
    $watcher->start();
    
} catch (Exception $e) {
    $logger->error("Fatal error: " . $e->getMessage());
    $logger->error("Stack trace: " . $e->getTraceAsString());
    exit(1);
}
