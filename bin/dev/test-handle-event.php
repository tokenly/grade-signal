#!/usr/bin/env php
<?php 

use App\Environment;
use App\Log;
use App\EventHandler;

require __DIR__.'/../../vendor/autoload.php';
Environment::init(__DIR__.'/../..');

$event_handler = EventHandler::instance();

$test_event = json_decode($argv[1], true);
Log::debug("Handling test_event: ".json_encode($test_event, 192));
$event_handler->handleEvent($test_event);


