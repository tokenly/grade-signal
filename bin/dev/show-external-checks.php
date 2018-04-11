#!/usr/bin/env php
<?php 

use App\Environment;
use App\EventHandler;
use App\ExternalChecks;
use App\Log;
use App\Notifier;

require __DIR__.'/../../vendor/autoload.php';
Environment::init(__DIR__.'/../..');


$external_checks = ExternalChecks::instance();
$specs = $external_checks->getExternalCheckSpecs();
echo "\$specs: ".json_encode($specs, 192)."\n";
