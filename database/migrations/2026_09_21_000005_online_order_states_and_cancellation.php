<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ordencompra', 'mercadopago'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Required sales table {$table} does not exist.");
            }
        }

        if (Schema::hasColumn('ordencompra', 'restock_key') && DB::table('ordencompra')
            ->whereNotNull('restock_key')
            ->select('restock_key')
            ->groupBy('restock_key')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('Duplicate restock markers must be resolved before hardening cancellation.');
        }

        if (! Schema::hasColumn('ordencompra', 'order_state')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->string('order_state', 20)->nullable();
            });
        }

        if (! Schema::hasColumn('ordencompra', 'cancelled_at')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->timestamp('cancelled_at')->nullable();
            });
        }

        if (! Schema::hasColumn('ordencompra', 'restock_key')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->string('restock_key', 64)->nullable();
            });
        }

        if (! Schema::hasIndex('ordencompra', 'online_restock_unique')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->unique('restock_key', 'online_restock_unique');
            });
        }

        if (! Schema::hasColumn('mercadopago', 'payment_id')) {
            Schema::table('mercadopago', function (Blueprint $table) {
                $table->unsignedBigInteger('payment_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('ordencompra', 'online_restock_unique')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->dropUnique('online_restock_unique');
            });
        }

        if (Schema::hasColumn('mercadopago', 'payment_id')) {
            Schema::table('mercadopago', function (Blueprint $table) {
                $table->dropColumn('payment_id');
            });
        }

        if (Schema::hasColumn('ordencompra', 'order_state')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->dropColumn('order_state');
            });
        }

        if (Schema::hasColumn('ordencompra', 'cancelled_at')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->dropColumn('cancelled_at');
            });
        }

        if (Schema::hasColumn('ordencompra', 'restock_key')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->dropColumn('restock_key');
            });
        }
    }
};
