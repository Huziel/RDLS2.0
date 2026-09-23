<?php

namespace Tests\Feature;

use App\Models\DeliveryLink;
use App\Models\DeliveryProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithInventorySchema;
use Tests\TestCase;

class DeliveryMigrationFixTest extends TestCase
{
    use InteractsWithInventorySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildInventorySchema();
    }

    /*
    |--------------------------------------------------------------------------
    | Migracion 2026_09_22_000001 (FASE 6B P1 + P0-1 indices unicos)
    |--------------------------------------------------------------------------
    */

    public function test_shipping_migration_backfills_not_null_and_unique_indexes(): void
    {
        Schema::create('ordenenvio', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tienda');
            $table->unsignedBigInteger('delivery')->nullable();
            $table->unsignedBigInteger('ordenCompra');
            $table->dateTime('fechaIn')->nullable();
            $table->integer('status')->nullable();
        });
        Schema::create('imageevidence', function (Blueprint $table) {
            $table->id();
            $table->string('orderC');
            $table->string('img');
        });

        DB::table('ordenenvio')->insert([
            ['tienda' => 1, 'delivery' => 10, 'ordenCompra' => 100, 'fechaIn' => now(), 'status' => '1'],
            ['tienda' => 1, 'delivery' => null, 'ordenCompra' => 101, 'fechaIn' => now(), 'status' => '0'],
            ['tienda' => 1, 'delivery' => null, 'ordenCompra' => 102, 'fechaIn' => now(), 'status' => '0'],
        ]);
        DB::table('imageevidence')->insert([
            ['orderC' => 'ORD-EVID-1', 'img' => '/uploads/a.png'],
        ]);

        $migration = require database_path('migrations/2026_09_22_000001_add_assignment_mode_to_shipping_orders.php');
        $migration->up();

        // (a) backfill idempotente
        $this->assertDatabaseHas('ordenenvio', ['ordenCompra' => 100, 'assignment_mode' => 'direct']);
        $this->assertDatabaseHas('ordenenvio', ['ordenCompra' => 101, 'assignment_mode' => 'pool']);
        $this->assertDatabaseHas('ordenenvio', ['ordenCompra' => 102, 'assignment_mode' => 'pool']);

        // (b) NOT NULL DEFAULT 'pool' compatible con el backfill
        DB::table('ordenenvio')->insert([
            'tienda' => 2, 'delivery' => null, 'ordenCompra' => 103, 'fechaIn' => now(), 'status' => '0',
        ]);
        $this->assertDatabaseHas('ordenenvio', ['ordenCompra' => 103, 'assignment_mode' => 'pool']);
        $this->assertNull(DB::table('ordenenvio')->where('ordenCompra', 103)->value('delivery'));

        // (c) indice unico ordenCompra
        $this->assertTrue(Schema::hasIndex('ordenenvio', 'shipping_orden_compra_unique'));
        try {
            DB::table('ordenenvio')->insert([
                'tienda' => 1, 'delivery' => null, 'ordenCompra' => 100, 'fechaIn' => now(), 'status' => '0',
            ]);
            $this->fail('Duplicate ordenCompra should violate the unique index.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('unique', $e->getMessage());
        }

        // (d) indice unico orderC en evidencias
        $this->assertTrue(Schema::hasIndex('imageevidence', 'evidence_order_c_unique'));
        try {
            DB::table('imageevidence')->insert(['orderC' => 'ORD-EVID-1', 'img' => '/uploads/b.png']);
            $this->fail('Duplicate evidence orderC should violate the unique index.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('unique', $e->getMessage());
        }

        // down(): columnas vuelven a nullable, indices unicos retirados, filas intactas.
        $migration->down();
        $this->assertDatabaseHas('ordenenvio', ['ordenCompra' => 100, 'assignment_mode' => 'direct']);
        $this->assertFalse(Schema::hasIndex('ordenenvio', 'shipping_orden_compra_unique'));
        $this->assertFalse(Schema::hasIndex('imageevidence', 'evidence_order_c_unique'));
        $this->assertTrue(Schema::hasColumn('ordenenvio', 'assignment_mode'));
        DB::table('ordenenvio')->insert([
            'tienda' => 1, 'delivery' => null, 'ordenCompra' => 100, 'fechaIn' => now(), 'status' => '0',
        ]);
        $this->assertDatabaseCount('ordenenvio', 5);
    }

    public function test_shipping_migration_preflights_duplicate_orden_compra_without_deleting(): void
    {
        Schema::create('ordenenvio', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tienda');
            $table->unsignedBigInteger('delivery')->nullable();
            $table->unsignedBigInteger('ordenCompra');
            $table->dateTime('fechaIn')->nullable();
            $table->integer('status')->nullable();
        });
        DB::table('ordenenvio')->insert([
            ['tienda' => 1, 'delivery' => 10, 'ordenCompra' => 200, 'fechaIn' => now(), 'status' => '1'],
            ['tienda' => 1, 'delivery' => null, 'ordenCompra' => 200, 'fechaIn' => now(), 'status' => '0'],
        ]);

        $migration = require database_path('migrations/2026_09_22_000001_add_assignment_mode_to_shipping_orders.php');

        try {
            $migration->up();
            $this->fail('Migration should abort on duplicate ordenCompra.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Duplicate ordenCompra', $e->getMessage());
        }

        // Ninguna fila se borro; el preflight aborto antes del indice unico.
        $this->assertDatabaseCount('ordenenvio', 2);
        $this->assertFalse(Schema::hasIndex('ordenenvio', 'shipping_orden_compra_unique'));
    }

    public function test_shipping_migration_preflights_duplicate_evidence_order_c(): void
    {
        Schema::create('ordenenvio', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tienda');
            $table->unsignedBigInteger('delivery')->nullable();
            $table->unsignedBigInteger('ordenCompra');
            $table->dateTime('fechaIn')->nullable();
            $table->integer('status')->nullable();
        });
        Schema::create('imageevidence', function (Blueprint $table) {
            $table->id();
            $table->string('orderC');
            $table->string('img');
        });
        DB::table('ordenenvio')->insert([
            ['tienda' => 1, 'delivery' => null, 'ordenCompra' => 300, 'fechaIn' => now(), 'status' => '0'],
        ]);
        DB::table('imageevidence')->insert([
            ['orderC' => 'DUP-ORDER', 'img' => '/uploads/a.png'],
            ['orderC' => 'DUP-ORDER', 'img' => '/uploads/b.png'],
        ]);

        $migration = require database_path('migrations/2026_09_22_000001_add_assignment_mode_to_shipping_orders.php');

        try {
            $migration->up();
            $this->fail('Migration should abort on duplicate evidence orderC.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Duplicate orderC', $e->getMessage());
        }

        $this->assertDatabaseCount('imageevidence', 2);
        $this->assertFalse(Schema::hasIndex('imageevidence', 'evidence_order_c_unique'));
    }

    /*
    |--------------------------------------------------------------------------
    | Migracion 2026_09_22_000002 (FASE 6B P1-3 roles legacy)
    |--------------------------------------------------------------------------
    */

    public function test_roles_backfill_grants_owners_and_verified_riders_only_their_permissions(): void
    {
        Schema::create('datospersonales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idLog')->unique();
            $table->integer('verificado')->default(0);
        });
        Schema::create('anexosdeliver', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deliveryMan');
            $table->unsignedBigInteger('store');
            $table->integer('bloqueo')->default(0);
        });

        // Dueno legacy: type 1 y createdby de una tienda, sin roles.
        $owner = User::create(['name' => 'legacy-owner@example.test', 'keyvalue' => 'test', 'type' => '1', 'active' => true]);
        Store::create(['serial' => 'LEGACY-STORE', 'createdby' => 'legacy-owner@example.test']);

        // Rider verificado y vinculado: type 3.
        $rider = User::create(['name' => 'legacy-rider@example.test', 'keyvalue' => 'test', 'type' => '3', 'active' => true]);
        DeliveryProfile::create(['idLog' => $rider->id, 'verificado' => '1']);
        DeliveryLink::create(['deliveryMan' => $rider->id, 'store' => 1, 'bloqueo' => '0']);

        // Rider NO verificado: no debe recibir el rol.
        $unverified = User::create(['name' => 'legacy-unverified@example.test', 'keyvalue' => 'test', 'type' => '3', 'active' => true]);
        DeliveryProfile::create(['idLog' => $unverified->id, 'verificado' => '0']);
        DeliveryLink::create(['deliveryMan' => $unverified->id, 'store' => 1, 'bloqueo' => '0']);

        $migration = require database_path('migrations/2026_09_22_000002_backfill_delivery_roles.php');
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($owner->fresh()->hasRole('store-owner'));
        $this->assertTrue($owner->can('orders.read'));
        $this->assertTrue($owner->can('delivery.manage'));
        $this->assertTrue($owner->can('delivery.block'));
        $this->assertFalse($owner->can('pos.use'), 'El owner legacy no debe recibir permisos POS de FASE 5 aqui.');
        $this->assertFalse($owner->can('products.read'));

        $this->assertTrue($rider->fresh()->hasRole('deliver'));
        $this->assertTrue($rider->can('delivery.accept'));
        $this->assertTrue($rider->can('delivery.complete'));
        $this->assertTrue($rider->can('delivery.location'));
        $this->assertFalse($rider->can('products.read'));

        $this->assertFalse($unverified->fresh()->hasAnyRole(['deliver', 'store-owner']));

        // Idempotente y guardado contra duplicados en model_has_roles.
        DB::table('model_has_roles')->where('model_id', $rider->id)->delete();
        $migration->up();
        $this->assertSame(1, DB::table('model_has_roles')
            ->where('role_id', Role::findByName('deliver', 'web')->id)
            ->where('model_id', $rider->id)
            ->count());
    }

    public function test_roles_backfill_skips_gracefully_when_the_delivery_module_tables_are_absent(): void
    {
        // Esquema legacy sin modulo delivery: log/liks existen pero
        // datospersonales/anexosdeliver no. La migracion no debe romper.
        $migration = require database_path('migrations/2026_09_22_000002_backfill_delivery_roles.php');
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertDatabaseCount('model_has_roles', 0);
    }

    public function test_roles_backfill_grants_role_to_a_verified_rider_without_store_link(): void
    {
        Schema::create('datospersonales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idLog')->unique();
            $table->integer('verificado')->default(0);
        });
        Schema::create('anexosdeliver', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deliveryMan');
            $table->unsignedBigInteger('store');
            $table->integer('bloqueo')->default(0);
        });

        // Rider verificado SIN vinculo a ninguna tienda: regla FASE 6B P2
        // (active + type '3' + verificado) => rol deliver para poder luego
        // usar attach-store.
        $verifiedNoLink = User::create(['name' => 'legacy-verified-nolink@example.test', 'keyvalue' => 'test', 'type' => '3', 'active' => true]);
        DeliveryProfile::create(['idLog' => $verifiedNoLink->id, 'verificado' => '1']);

        // Rider no verificado y sin vinculo: no recibe el rol.
        $unverified = User::create(['name' => 'legacy-unverified-nolink@example.test', 'keyvalue' => 'test', 'type' => '3', 'active' => true]);
        DeliveryProfile::create(['idLog' => $unverified->id, 'verificado' => '0']);

        $migration = require database_path('migrations/2026_09_22_000002_backfill_delivery_roles.php');
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($verifiedNoLink->fresh()->hasRole('deliver'));
        $this->assertFalse($unverified->fresh()->hasAnyRole(['deliver', 'store-owner']));
    }
}
