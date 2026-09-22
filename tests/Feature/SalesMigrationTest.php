<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SalesMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->buildLegacyTables();
    }

    public function test_sales_hardening_migration_is_retryable(): void
    {
        $migration = require database_path('migrations/2026_09_21_000004_harden_checkout_and_loyalty.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('ordencompra', 'checkout_key'));
        $this->assertTrue(Schema::hasColumn('ordencompra', 'loyalty_discount'));
        $this->assertTrue(Schema::hasIndex('ordencompra', 'online_order_unique'));
        $this->assertTrue(Schema::hasIndex('ordencompra', 'online_checkout_key_unique'));
        $this->assertTrue(Schema::hasIndex('loyalty_configs', 'loyalty_store_unique'));
        $this->assertTrue(Schema::hasIndex('loyalty_transactions', 'loyalty_reference_unique'));
        $this->assertTrue(Schema::hasIndex('mercadopago', 'mercadopago_order_unique'));
        $this->assertTrue(Schema::hasIndex('mercadopagocuentas', 'mercadopago_account_unique'));
        $this->assertTrue(Schema::hasColumn('mercadopagocuentas', 'merchantId'));
    }

    public function test_sales_hardening_preflights_duplicate_loyalty_references(): void
    {
        DB::table('loyalty_transactions')->insert([
            ['store_id' => 1, 'client_id' => 1, 'points' => -5, 'type' => 'redeem', 'reference' => 'same'],
            ['store_id' => 1, 'client_id' => 1, 'points' => -5, 'type' => 'redeem', 'reference' => 'same'],
        ]);
        $migration = require database_path('migrations/2026_09_21_000004_harden_checkout_and_loyalty.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate loyalty transaction references');
        $migration->up();
    }

    public function test_sales_hardening_rejects_duplicate_order_numbers_across_stores(): void
    {
        DB::table('ordencompra')->insert([
            ['order' => 'SAME-ORDER', 'serial' => 'STORE-A'],
            ['order' => 'SAME-ORDER', 'serial' => 'STORE-B'],
        ]);
        $migration = require database_path('migrations/2026_09_21_000004_harden_checkout_and_loyalty.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate online order numbers');
        $migration->up();
    }

    private function buildLegacyTables(): void
    {
        Schema::create('ordencompra', function (Blueprint $table) {
            $table->id();
            $table->string('order');
            $table->string('serial');
        });
        Schema::create('loyalty_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
        });
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('client_id');
            $table->integer('points');
            $table->string('type');
            $table->string('reference')->nullable();
        });
        Schema::create('mercadopago', function (Blueprint $table) {
            $table->id();
            $table->string('orderP');
        });
        Schema::create('mercadopagocuentas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idLog');
        });
    }
}
