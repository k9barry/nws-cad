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
use NwsCad\Notifications\ChannelFactory;
use NwsCad\Notifications\ChannelRepository;
use NwsCad\Notifications\EventDispatcher;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\Outbox\OutboxProcessor;
use NwsCad\Notifications\Outbox\OutboxRepository;
use NwsCad\Notifications\Outbox\OutboxWriter;
use NwsCad\Notifications\Outbox\WorkerId;
use NwsCad\Notifications\Events\CallProcessedEvent;

$logger = Logger::getInstance();
$config = Config::getInstance();
require_once __DIR__ . '/Notifications/registerChannels.php';

try {
    $logLevel = strtoupper($config->get('app.log_level', 'INFO'));
    $logger->info("Starting NWS CAD File Watcher Service");
    $logger->info("Log level: {$logLevel}");

    $deltaSeconds = (int) $config->get('notifications.delta_seconds', 900);
    $batchSize    = (int) ($_ENV['OUTBOX_BATCH_SIZE'] ?? getenv('OUTBOX_BATCH_SIZE') ?: 10);
    $maxAttempts  = (int) ($_ENV['OUTBOX_MAX_ATTEMPTS'] ?? getenv('OUTBOX_MAX_ATTEMPTS') ?: 5);

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
                l.full_address, l.nearest_cross_streets,
                l.common_name, l.police_beat, l.fire_quadrant,
                l.latitude_y AS latitude, l.longitude_x AS longitude,
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

    $outboxRepo      = new OutboxRepository();
    $channelRepo     = new ChannelRepository();
    $channelFactory  = new ChannelFactory($config);
    $outboxWriter    = new OutboxWriter($outboxRepo, $channelRepo, $deltaSeconds);
    $outboxProcessor = new OutboxProcessor(
        $outboxRepo,
        $channelFactory,
        $channelRepo,
        $incidentLoader,
        batchSize:    $batchSize,
        maxAttempts:  $maxAttempts,
        workerId:     WorkerId::current(),
    );

    EventDispatcher::subscribe(static function (CallProcessedEvent $e) use ($outboxWriter): void {
        $outboxWriter->handle($e);
    });

    $watcher = new FileWatcher();
    $watcher->setOnTick(static function () use ($outboxProcessor): void {
        $outboxProcessor->tick();
    });
    $watcher->start();

} catch (Exception $e) {
    $logger->error("Fatal error: " . $e->getMessage());
    $logger->error("Stack trace: " . $e->getTraceAsString());
    exit(1);
}
