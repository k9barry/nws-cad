<?php

namespace NwsCad;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Logger Singleton
 * Provides application-wide logging functionality
 */
class Logger
{
    private static ?MonologLogger $instance = null;

    public static function getInstance(): MonologLogger
    {
        if (self::$instance === null) {
            self::$instance = self::createLogger();
        }
        return self::$instance;
    }

    private static function createLogger(): MonologLogger
    {
        $config = Config::getInstance();
        $logger = new MonologLogger('nws-cad');

        // Get log level from config with validation
        $logLevelStr = strtoupper($config->get('app.log_level', 'INFO'));
        $validLevels = [
            'DEBUG' => MonologLogger::DEBUG,
            'INFO' => MonologLogger::INFO,
            'NOTICE' => MonologLogger::NOTICE,
            'WARNING' => MonologLogger::WARNING,
            'ERROR' => MonologLogger::ERROR,
            'CRITICAL' => MonologLogger::CRITICAL,
            'ALERT' => MonologLogger::ALERT,
            'EMERGENCY' => MonologLogger::EMERGENCY,
        ];
        
        $level = $validLevels[$logLevelStr] ?? MonologLogger::INFO;

        // Log to file with rotation
        $logPath = $config->get('paths.logs') . '/app.log';
        $handler = new RotatingFileHandler($logPath, 7, $level);
        
        // Custom formatter
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            "Y-m-d H:i:s"
        );
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);

        // Also log to stdout in debug mode
        if ($config->get('app.debug')) {
            $stdoutHandler = new StreamHandler('php://stdout', $level);
            $stdoutHandler->setFormatter($formatter);
            $logger->pushHandler($stdoutHandler);
        }

        return $logger;
    }

    // Prevent instantiation
    private function __construct() {}
    private function __clone() {}
}
