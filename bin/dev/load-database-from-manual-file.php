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

$filepath = $argv[1] ?? null;
if (!$filepath) {
    echo "Usage: {$argv[0]} <filepath>\n";
    exit(1);
}

$store = Store::instance();

$json_string = file_get_contents($filepath);
$backup_data = json_decode($json_string, true);
$store->destroyDatabase();

foreach($backup_data as $entry) {
    if (!$entry) {
        continue;
    }
    $check_id = $entry['check_id'];
    unset($entry['check_id']);
    $store->newState($check_id, $entry);
}

echo "database loaded\n";

