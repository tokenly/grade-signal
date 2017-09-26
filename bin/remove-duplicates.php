#!/usr/bin/env php
<?php 

use App\Environment;
use App\EventHandler;
use App\Log;
use App\Notifier;

require __DIR__.'/../vendor/autoload.php';
Environment::init(__DIR__.'/..');

$notifier = Notifier::instance();

try {
    $notifier->removeDuplicates();
} catch (Exception $e) {
    echo "ERROR: ".$e->getMessage()."\n";
    Log::warn("ERROR: ".$e->getMessage());
}
