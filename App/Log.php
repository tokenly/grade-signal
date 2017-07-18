<?php

namespace App;

use Exception;
use Fluent\Logger\FluentLogger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Tokenly\FluentdLogger\FluentEventLogger;
use Tokenly\FluentdLogger\FluentMonologHandler;

/**
* Utilities
*/
class Log
{
    
    static $LOGGER;
    static $MEASUREMENT_LOGGER;

    public static function initMonolog() {
        // init monolog
        self::$LOGGER = new Logger('sm');

        // fluent logger
        $fluent_logger = new FluentLogger('unix://'.env('FLUENTD_SOCKET', '/var/run/td-agent/td-agent.sock'), null);
        $app_descriptor = env('APP_CODE', 'semaphore').'.'.env('APP_ENV', 'production');
        $tag = 'applog.'.$app_descriptor;

        # set up monolog
        self::$LOGGER->pushHandler(new FluentMonologHandler($fluent_logger, $tag));

        # set up fluent event logger for measurements
        self::$MEASUREMENT_LOGGER = new FluentEventLogger($fluent_logger, 'measure.'.$app_descriptor);
    }

    public static function measure($event, $data=[], $tags=null) {
        self::$MEASUREMENT_LOGGER->log($event, $data, $tags);
    }

    public static function debug($text, $other_logger=null) { self::wlog($text, Logger::DEBUG, $other_logger); }
    public static function info($text, $other_logger=null) { self::wlog($text, Logger::INFO, $other_logger); }
    public static function warn($text, $other_logger=null) { self::wlog($text, Logger::WARNING, $other_logger); }

    public static function wlog($text, $level = Logger::INFO, $other_logger=null) {
        if (self::$LOGGER === null) { throw new Exception("log not inited", 1); }
        self::$LOGGER->log($level, $text);

        // log to additonal logger
        if ($other_logger !== null) { $other_logger->log($level, $text); }
    }

    public static function logError(Exception $e, $other_logger=null) {
        $msg = "Error at ".$e->getFile().", line ".$e->getLine().": ".$e->getMessage()."\n\n".$e->getTraceAsString();
        self::wlog($msg, Logger::ERROR);
        if ($other_logger !== null) { $other_logger->log(Logger::ERROR, $msg); }
    }


}
