<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (DB::select("DESCRIBE pventageneral") as $c) echo $c->Field . " => " . $c->Type . "\n";
