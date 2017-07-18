#!/usr/bin/env php
<?php 

use App\Consul;
use App\Environment;
use App\EventHandler;
use App\Log;
use App\Notifier;

require __DIR__.'/../vendor/autoload.php';
Environment::init(__DIR__.'/..');

$notifier = Notifier::instance();

Log::debug("notifier process start");
$start = time();
while (true) {
    try {
        $notifier->checkAllStates();
    } catch (Exception $e) {
        echo "ERROR: ".$e->getMessage()."\n";
        Log::warn("ERROR: ".$e->getMessage());
        sleep(5);
    }

    sleep(5);

    if (time() - $start > 3600) {
        // 1 hour
        Log::debug("notifier process still alive");
        $start = time();
    }

}

