<?php

namespace Tests\Support;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

trait InteractsWithInventorySchema
{
    protected function buildInventorySchema(): void
    {
        Schema::dropAllTables();

        Schema::create('log', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('keyvalue')->nullable();
            $table->string('type')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('liks', function (Blueprint $table) {
            $table->id();
            $table->string('serial')->unique();
            $table->string('createdby')->unique();
            $table->string('logo')->nullable();
            $table->string('color')->nullable();
            $table->string('lat')->nullable();
            $table->string('long')->nullable();
            $table->string('adress')->nullable();
        });

        Schema::create('data', function (Blueprint $table) {
            $table->id();
            $table->string('number');
            $table->string('keyy');
            $table->string('link')->nullable();
            $table->string('session')->nullable();
            $table->text('dscr')->nullable();
            $table->text('var')->nullable();
            $table->string('category', 50)->nullable();
            $table->integer('active')->nullable()->default(1);
        });

        Schema::create('stock', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idProd')->unique();
            $table->double('stock')->nullable();
            $table->integer('typesd')->nullable();
        });

        Schema::create('cbarras', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idProd')->unique();
            $table->string('code')->index();
        });

        Schema::create('img', function (Blueprint $table) {
            $table->id();
            $table->string('picture')->nullable();
            $table->string('dom')->nullable();
            $table->unsignedBigInteger('product')->nullable();
        });

        Schema::create('aditivos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idProd');
            $table->string('nombre');
            $table->double('precio');
            $table->string('categoria')->default('');
            $table->text('descripcion')->default('');
            $table->integer('activo')->default(1);
            $table->integer('stock')->default(0);
        });

        Schema::create('pventageneral', function (Blueprint $table) {
            $table->id();
            $table->string('noOrder', 50);
            $table->string('nombre');
            $table->string('telefono')->nullable();
            $table->string('fecha');
            $table->integer('estado');
            $table->double('total');
            $table->double('extra')->nullable();
            $table->integer('descuento')->nullable();
            $table->integer('tipoPago');
            $table->string('creator')->nullable();
        });

        Schema::create('pventageneraldetalle', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idPventaGeneral');
            $table->unsignedBigInteger('productoId');
            $table->double('cantidad');
            $table->string('nameProd')->nullable();
            $table->double('precioBruto')->nullable();
            $table->double('precioNeto')->nullable();
        });

        Schema::create('pventageneralhisto', function (Blueprint $table) {
            $table->id();
            $table->string('noOrder', 50);
            $table->string('nombre');
            $table->string('telefono')->nullable();
            $table->string('fecha');
            $table->integer('estado');
            $table->double('total');
            $table->double('extra')->nullable();
            $table->integer('descuento')->nullable();
            $table->integer('tipoPago');
            $table->string('creator')->nullable();
        });

        Schema::create('pventageneraldetallehisto', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idPventaGeneral');
            $table->unsignedBigInteger('productoId');
            $table->double('cantidad');
            $table->string('nameProd')->nullable();
            $table->double('precioBruto')->nullable();
            $table->double('precioNeto')->nullable();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function signInStore(string $email, array $permissions = []): array
    {
        $user = User::create([
            'name' => $email,
            'keyvalue' => 'test',
            'type' => '1',
            'active' => true,
        ]);
        $store = Store::create([
            'serial' => 'STORE-'.strtoupper(substr(md5($email), 0, 8)),
            'createdby' => $email,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::findOrCreate($permissionName, 'web');
            $user->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user, ['*']);

        return [$user, $store];
    }

    protected function createProduct(string $owner, array $attributes = [], ?float $stock = 5): Product
    {
        $product = Product::create(array_merge([
            'number' => '100',
            'keyy' => 'Producto',
            'session' => $owner,
            'category' => 'General',
            'active' => true,
        ], $attributes));

        if ($stock !== null) {
            ProductStock::create([
                'idProd' => $product->id,
                'stock' => $stock,
                'typesd' => null,
            ]);
        }

        return $product;
    }
}
