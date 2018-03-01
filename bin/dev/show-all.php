#!/usr/bin/env php
<?php 

use App\Environment;
use App\EventHandler;
use App\ExternalChecks;
use App\Log;
use App\Notifier;
use App\State;
use App\Store;

require __DIR__.'/../../vendor/autoload.php';
Environment::init(__DIR__.'/../..');


$store = Store::instance();

foreach ($store->findAllStateIDs() as $state_id) {
    $state = State::findByID($state_id);
    if (!$state) { continue; }

    $name = $state->name;
    $check_id = $state->check_id;
    $status = $state->status;
    $timestamp = $state->timestamp;


    echo "{$status} ({$check_id}) {$name}: ".date("Y-m-d H:i:s", $timestamp)."\n";

}
