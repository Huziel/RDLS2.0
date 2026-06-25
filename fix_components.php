<?php
require_once __DIR__ . "/vendor/autoload.php";
$app = require_once __DIR__ . "/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DB::statement("INSERT IGNORE INTO components (id, name, description) VALUES (1, 'shipping_form', 'Formulario de envios')");
DB::statement("INSERT IGNORE INTO components (id, name, description) VALUES (2, 'pickup', 'Recoger en tienda')");
echo "Components inserted\n";

$store = App\Models\Store::where("createdby", "admin@seda.com")->first();
if ($store) {
    DB::statement("INSERT IGNORE INTO caracteristicasadicionales (idTienda, idComponent, active) VALUES ({$store->id}, 2, 1)");
    echo "Pickup enabled for store {$store->id}\n";
}
echo "Done\n";
