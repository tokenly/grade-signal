<?php

use App\Environment;
use App\Log;
use App\State;
use App\Store;

require __DIR__.'/../vendor/autoload.php';
Environment::init(__DIR__.'/..');
date_default_timezone_set('America/Chicago');

require(__DIR__.'/include/auth.php');
require(__DIR__.'/include/header.php');

$show_down_only = $_GET['down'] ?? false;

$self = $_SERVER['PHP_SELF'];
echo <<<EOT

<div class="float-right">
<a class="btn btn-danger" href="{$self}?down=1">Show Down Only</a>
<a class="btn btn-light" href="{$self}">Show All</a>
</div>

<h1 class="mt-4">Status</h1>

<table class="table mt-3">
<thead>
<tr>
    <th>Name</th>
    <th>ID</th>
    <th>Status</th>
    <th>Time</th>
</tr>
</thead>
<tbody>
EOT;

echo '<pre>';

$store = Store::instance();
foreach ($store->findAllStateIDs() as $state_id) {
    $state = State::findByID($state_id);
    if (!$state) { continue; }

    $status = $state->status;

    if ($show_down_only and $status == 'up') {
        continue;
    }

    $name = $state->name;
    $check_id = $state->check_id;
    $status_class = $state->status == 'up' ? 'text-success' : 'text-danger';
    $timestamp = $state->timestamp;

    $date = date("Y-m-d h:i:s A T", $timestamp);
    echo <<<EOT
    <tr>
    <td>{$name}</td>
    <td>{$check_id}</td>
    <td><span class="{$status_class}">{$status}</span></td>
    <td>{$date}</td>
    </tr>
EOT;


}

echo <<<EOT
</tbody>
</table>

EOT;


require(__DIR__.'/include/footer.php');
