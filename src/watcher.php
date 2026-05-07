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
    
    $deltaSeconds = (int) ($_ENV['NOTIFICATION_DELTA_SECONDS'] ?? getenv('NOTIFICATION_DELTA_SECONDS') ?: 900);

    $incidentLoader = function (int $dbCallId): IncidentDto {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT
                c.id, c.call_id, c.call_number, c.alarm_level, c.create_datetime,
                c.nature_of_call,
                ac.call_type, ac.agency_type,
                l.full_address, l.nearest_cross_streets, l.latitude_y AS latitude, l.longitude_x AS longitude,
                (SELECT GROUP_CONCAT(jurisdiction SEPARATOR '|')
                   FROM incidents WHERE call_id = c.id) AS jurisdiction,
                (SELECT GROUP_CONCAT(unit_number SEPARATOR '|')
                   FROM units WHERE call_id = c.id) AS units,
                (SELECT GROUP_CONCAT(text SEPARATOR ' ')
                   FROM narratives WHERE call_id = c.id) AS narrative
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

    $channelFactory = function (array $row) use ($config): NotificationChannel {
        $type = $row['type'];
        $cfg = json_decode($row['config_json'] ?: '{}', true) ?: [];
        if ($type === 'ntfy') {
            $tokenEnv = $cfg['auth_token_env'] ?? 'NTFY_AUTH_TOKEN';
            return new NtfyChannel(
                baseUrl: $row['base_url'],
                authToken: $config->secret($tokenEnv),
                config: $cfg,
            );
        }
        if ($type === 'pushover') {
            $tokenEnv = $cfg['token_env'] ?? 'PUSHOVER_TOKEN';
            $userEnv = $cfg['user_env'] ?? 'PUSHOVER_USER';
            return new PushoverChannel(
                baseUrl: $row['base_url'],
                token: $config->secret($tokenEnv),
                user: $config->secret($userEnv),
                config: $cfg,
            );
        }
        throw new \RuntimeException("Unknown channel type: {$type}");
    };

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
