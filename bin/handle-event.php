#!/usr/bin/env php
<?php 

use App\Environment;
use App\Log;
use App\EventHandler;

require __DIR__.'/../vendor/autoload.php';
Environment::init(__DIR__.'/..');

$input = file_get_contents("php://stdin");
$events = json_decode($input, true);

$event_handler = EventHandler::instance();

foreach($events as $event) {
    // Log::debug('Received event: '.json_encode($event, 192));
    $event_handler->handleEvent($event);
}


