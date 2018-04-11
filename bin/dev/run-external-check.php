#!/usr/bin/env php
<?php 

use App\Environment;
use App\EventHandler;
use App\ExternalChecks;
use App\Log;
use App\Notifier;

require __DIR__.'/../../vendor/autoload.php';
Environment::init(__DIR__.'/../..');

$external_check_id = $argv[1] ?? null;
if (!$external_check_id) {
    echo "Usage: {$argv[0]} <check_id | all>\n";
    exit(1);
}

if ($external_check_id == 'all') {
    $external_checks = ExternalChecks::instance();
    $specs = $external_checks->getExternalCheckSpecs();
    $external_check_ids = [];
    foreach($specs as $spec) {
        $external_check_ids[] = $spec['id'];
    }
} else {
    $external_check_ids = [$external_check_id];
}

foreach($external_check_ids as $external_check_id) {
    try {
        $external_checks = ExternalChecks::instance();
        $specs = $external_checks->getExternalCheckSpecs();
        foreach($specs as $spec) {
            if ($spec['id'] == $external_check_id) {
                // echo "checking: ".json_encode($spec, 192)."\n";
                list($status, $note) = $external_checks->runCheck($spec);
                if ($status !== null) {
                    echo "\$status: $status\n";
                    if (strlen($note)) {
                        echo "\$note: $note\n";
                    }
                }
            }
        }
    } catch (Exception $e) {
        echo "ERROR: ".$e->getMessage()."\n";
        Log::warn("ERROR: ".$e->getMessage());
    }

}
