<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$e = DB::table("masdatosdetienda")->where("idTienda", 3)->first();
if ($e) {
    echo $e->sections ? "HAS DATA: " . substr($e->sections, 0, 80) : "EMPTY/NULL";
} else {
    echo "NOT FOUND - creating row";
    DB::table("masdatosdetienda")->insert(["idTienda" => 3, "sections" => "[]"]);
    echo " - CREATED";
}
