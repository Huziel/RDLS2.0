<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class InventoryMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->buildLegacyTables();
    }

    public function test_inventory_hardening_migration_is_retryable(): void
    {
        $migration = require database_path('migrations/2026_09_21_000002_harden_inventory_constraints.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasIndex('stock', 'stock_product_unique'));
        $this->assertTrue(Schema::hasIndex('cbarras', 'barcode_product_unique'));
        $this->assertTrue(Schema::hasIndex('cbarras', 'barcode_code_index'));
        $this->assertTrue(Schema::hasIndex('pventageneral', 'pos_creator_order_unique'));
        $this->assertTrue(Schema::hasIndex('pventageneralhisto', 'pos_history_creator_order_unique'));
        $this->assertSame('varchar', Schema::getColumnType('cbarras', 'code'));
        $this->assertSame('varchar', Schema::getColumnType('pventageneral', 'noOrder'));
    }

    public function test_inventory_hardening_preflights_duplicates_before_ddl(): void
    {
        DB::table('stock')->insert([
            ['idProd' => 1, 'stock' => 2],
            ['idProd' => 1, 'stock' => 3],
        ]);
        $migration = require database_path('migrations/2026_09_21_000002_harden_inventory_constraints.php');

        try {
            $migration->up();
            $this->fail('Expected duplicate stock rows to abort the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Duplicate stock rows', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasIndex('stock', 'stock_product_unique'));
        $this->assertSame('double', Schema::getColumnType('cbarras', 'code'));
    }

    public function test_inventory_hardening_preflights_duplicate_barcodes_within_a_store(): void
    {
        DB::table('data')->insert([
            ['id' => 1, 'session' => 'owner@example.test'],
            ['id' => 2, 'session' => 'owner@example.test'],
        ]);
        DB::table('cbarras')->insert([
            ['idProd' => 1, 'code' => 750123],
            ['idProd' => 2, 'code' => 750123],
        ]);
        $migration = require database_path('migrations/2026_09_21_000002_harden_inventory_constraints.php');

        try {
            $migration->up();
            $this->fail('Expected duplicate barcode values to abort the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Duplicate barcode values within a store', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasIndex('stock', 'stock_product_unique'));
        $this->assertSame('double', Schema::getColumnType('cbarras', 'code'));
    }

    private function buildLegacyTables(): void
    {
        Schema::create('data', function (Blueprint $table) {
            $table->id();
            $table->string('session')->nullable();
        });
        Schema::create('stock', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idProd');
            $table->double('stock')->nullable();
            $table->integer('typesd')->nullable();
        });
        Schema::create('cbarras', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idProd');
            $table->double('code');
        });
        Schema::create('pventageneral', function (Blueprint $table) {
            $table->id();
            $table->double('noOrder');
            $table->string('creator')->nullable();
        });
        Schema::create('pventageneralhisto', function (Blueprint $table) {
            $table->id();
            $table->double('noOrder');
            $table->string('creator')->nullable();
        });
    }
}
