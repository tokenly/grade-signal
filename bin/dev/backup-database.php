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


$notifier = Notifier::instance();
echo "backing up database\n";
$notifier->backupDatabase();
echo "done\n";
