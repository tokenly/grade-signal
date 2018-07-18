<?php

use App\Environment;
use App\Log;

require __DIR__.'/../vendor/autoload.php';
Environment::init(__DIR__.'/..');

require(__DIR__.'/include/auth.php');
require(__DIR__.'/include/header.php');

$self = $_SERVER['PHP_SELF'];
echo <<<EOT
<h1 class="mt-4">Grade Signal</h1>

<div class="mt-4">
<a class="btn btn-primary" href="/status.php">Status</a>
</div>
EOT;


require(__DIR__.'/include/footer.php');
