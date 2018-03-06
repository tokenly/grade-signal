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

$state_id = $argv[1] ?? null;
if (!$state_id) {
    echo "Usage: {$argv[0]} <state_id>\n";
    exit(1);
}

$state = State::findByID($state_id);
if (!$state) {
    echo "State not found for id $state_id\n";
    exit(1);
}

$name = $state->name;
$check_id = $state->check_id;
$status = $state->status;
$timestamp = $state->timestamp;

$state->delete();
echo "Deleted $check_id ({$status}) {$name} [".date("Y-m-d H:i:s", $timestamp)."]\n";
