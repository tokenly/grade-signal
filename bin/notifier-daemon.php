#!/usr/bin/env php
<?php 

use App\Environment;
use App\EventHandler;
use App\Log;
use App\Notifier;
use App\Store;

require __DIR__.'/../vendor/autoload.php';
Environment::init(__DIR__.'/..');

$notifier = Notifier::instance();

Log::debug("notifier process start");

// try to restore the database of it doesn't exist
if (!Store::databaseExists()) {
    Log::debug("restoring database");
    $notifier->restoreDatabase();
}

$start = time();
$backup_start = time();
while (true) {
    try {
        $notifier->processAllChecks();
        $notifier->notifyAllStates();
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

    if (time() - $backup_start >= 300) {
        // 5 minutes
        Log::debug("backing up database");
        $notifier->backupDatabase();
        $backup_start = time();
    }

}

