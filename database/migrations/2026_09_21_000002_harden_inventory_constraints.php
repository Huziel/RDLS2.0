<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['data', 'stock', 'cbarras', 'pventageneral', 'pventageneralhisto'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Required legacy table {$table} does not exist.");
            }
        }

        if (DB::table('stock')->select('idProd')->groupBy('idProd')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate stock rows must be resolved before hardening inventory.');
        }

        if (DB::table('cbarras')->select('idProd')->groupBy('idProd')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate barcode rows must be resolved before hardening inventory.');
        }

        if (DB::table('cbarras')
            ->join('data', 'data.id', '=', 'cbarras.idProd')
            ->select(['data.session', 'cbarras.code'])
            ->groupBy('data.session', 'cbarras.code')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('Duplicate barcode values within a store must be resolved before hardening inventory.');
        }

        foreach (['pventageneral', 'pventageneralhisto'] as $table) {
            if (DB::table($table)->select(['creator', 'noOrder'])->groupBy('creator', 'noOrder')->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException("Duplicate POS order numbers exist in {$table}.");
            }
        }

        if (! Schema::hasIndex('stock', 'stock_product_unique')) {
            Schema::table('stock', function (Blueprint $table) {
                $table->unique('idProd', 'stock_product_unique');
            });
        }

        if (! in_array(Schema::getColumnType('cbarras', 'code'), ['string', 'varchar'], true)) {
            Schema::table('cbarras', function (Blueprint $table) {
                $table->string('code', 255)->change();
            });
        }

        if (! Schema::hasIndex('cbarras', 'barcode_product_unique')) {
            Schema::table('cbarras', function (Blueprint $table) {
                $table->unique('idProd', 'barcode_product_unique');
            });
        }

        if (! Schema::hasIndex('cbarras', 'barcode_code_index')) {
            Schema::table('cbarras', function (Blueprint $table) {
                $table->index('code', 'barcode_code_index');
            });
        }

        foreach (['pventageneral', 'pventageneralhisto'] as $tableName) {
            if (! in_array(Schema::getColumnType($tableName, 'noOrder'), ['string', 'varchar'], true)) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('noOrder', 50)->change();
                });
            }
        }

        if (! Schema::hasIndex('pventageneral', 'pos_creator_order_unique')) {
            Schema::table('pventageneral', function (Blueprint $table) {
                $table->unique(['creator', 'noOrder'], 'pos_creator_order_unique');
            });
        }

        if (! Schema::hasIndex('pventageneralhisto', 'pos_history_creator_order_unique')) {
            Schema::table('pventageneralhisto', function (Blueprint $table) {
                $table->unique(['creator', 'noOrder'], 'pos_history_creator_order_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('stock', 'stock_product_unique')) {
            Schema::table('stock', function (Blueprint $table) {
                $table->dropUnique('stock_product_unique');
            });
        }

        if (Schema::hasIndex('cbarras', 'barcode_product_unique')) {
            Schema::table('cbarras', function (Blueprint $table) {
                $table->dropUnique('barcode_product_unique');
            });
        }

        if (Schema::hasIndex('cbarras', 'barcode_code_index')) {
            Schema::table('cbarras', function (Blueprint $table) {
                $table->dropIndex('barcode_code_index');
            });
        }

        if (Schema::hasIndex('pventageneral', 'pos_creator_order_unique')) {
            Schema::table('pventageneral', function (Blueprint $table) {
                $table->dropUnique('pos_creator_order_unique');
            });
        }

        if (Schema::hasIndex('pventageneralhisto', 'pos_history_creator_order_unique')) {
            Schema::table('pventageneralhisto', function (Blueprint $table) {
                $table->dropUnique('pos_history_creator_order_unique');
            });
        }

        // Narrowing barcode and order identifiers back to DOUBLE would destroy valid data.
    }
};
