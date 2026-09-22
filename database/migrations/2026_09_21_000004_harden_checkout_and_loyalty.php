<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ordencompra', 'loyalty_configs', 'loyalty_transactions'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Required sales table {$table} does not exist.");
            }
        }

        if (DB::table('ordencompra')
            ->select('order')
            ->groupBy('order')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('Duplicate online order numbers must be resolved before hardening checkout.');
        }

        if (DB::table('loyalty_configs')
            ->select('store_id')
            ->groupBy('store_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('Duplicate loyalty configurations must be resolved before hardening checkout.');
        }

        if (DB::table('loyalty_transactions')
            ->whereNotNull('reference')
            ->select(['store_id', 'type', 'reference'])
            ->groupBy('store_id', 'type', 'reference')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('Duplicate loyalty transaction references must be resolved before hardening checkout.');
        }

        if (Schema::hasTable('mercadopago') && DB::table('mercadopago')
            ->select('orderP')
            ->groupBy('orderP')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('Duplicate MercadoPago order references must be resolved before hardening checkout.');
        }

        if (Schema::hasTable('mercadopagocuentas') && DB::table('mercadopagocuentas')
            ->select('idLog')
            ->groupBy('idLog')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('Duplicate MercadoPago accounts must be resolved before hardening checkout.');
        }

        if (! Schema::hasColumn('ordencompra', 'checkout_key')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->string('checkout_key', 64)->nullable();
            });
        }

        if (! Schema::hasColumn('ordencompra', 'loyalty_discount')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->decimal('loyalty_discount', 12, 2)->default(0);
            });
        }

        if (Schema::hasTable('mercadopagocuentas') && ! Schema::hasColumn('mercadopagocuentas', 'merchantId')) {
            Schema::table('mercadopagocuentas', function (Blueprint $table) {
                $table->string('merchantId', 100)->nullable();
            });
        }

        if (! Schema::hasIndex('ordencompra', 'online_order_unique')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->unique('order', 'online_order_unique');
            });
        }

        if (! Schema::hasIndex('ordencompra', 'online_checkout_key_unique')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->unique(['serial', 'checkout_key'], 'online_checkout_key_unique');
            });
        }

        if (! Schema::hasIndex('loyalty_configs', 'loyalty_store_unique')) {
            Schema::table('loyalty_configs', function (Blueprint $table) {
                $table->unique('store_id', 'loyalty_store_unique');
            });
        }

        if (! Schema::hasIndex('loyalty_transactions', 'loyalty_reference_unique')) {
            Schema::table('loyalty_transactions', function (Blueprint $table) {
                $table->unique(['store_id', 'type', 'reference'], 'loyalty_reference_unique');
            });
        }

        if (Schema::hasTable('mercadopago') && ! Schema::hasIndex('mercadopago', 'mercadopago_order_unique')) {
            Schema::table('mercadopago', function (Blueprint $table) {
                $table->unique('orderP', 'mercadopago_order_unique');
            });
        }

        if (Schema::hasTable('mercadopagocuentas') && ! Schema::hasIndex('mercadopagocuentas', 'mercadopago_account_unique')) {
            Schema::table('mercadopagocuentas', function (Blueprint $table) {
                $table->unique('idLog', 'mercadopago_account_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mercadopagocuentas') && Schema::hasIndex('mercadopagocuentas', 'mercadopago_account_unique')) {
            Schema::table('mercadopagocuentas', function (Blueprint $table) {
                $table->dropUnique('mercadopago_account_unique');
            });
        }

        if (Schema::hasTable('mercadopago') && Schema::hasIndex('mercadopago', 'mercadopago_order_unique')) {
            Schema::table('mercadopago', function (Blueprint $table) {
                $table->dropUnique('mercadopago_order_unique');
            });
        }

        if (Schema::hasIndex('ordencompra', 'online_order_unique')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->dropUnique('online_order_unique');
            });
        }

        if (Schema::hasIndex('ordencompra', 'online_checkout_key_unique')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->dropUnique('online_checkout_key_unique');
            });
        }

        if (Schema::hasIndex('loyalty_configs', 'loyalty_store_unique')) {
            Schema::table('loyalty_configs', function (Blueprint $table) {
                $table->dropUnique('loyalty_store_unique');
            });
        }

        if (Schema::hasIndex('loyalty_transactions', 'loyalty_reference_unique')) {
            Schema::table('loyalty_transactions', function (Blueprint $table) {
                $table->dropUnique('loyalty_reference_unique');
            });
        }

        if (Schema::hasColumn('ordencompra', 'checkout_key')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->dropColumn('checkout_key');
            });
        }

        if (Schema::hasColumn('ordencompra', 'loyalty_discount')) {
            Schema::table('ordencompra', function (Blueprint $table) {
                $table->dropColumn('loyalty_discount');
            });
        }

        if (Schema::hasTable('mercadopagocuentas') && Schema::hasColumn('mercadopagocuentas', 'merchantId')) {
            Schema::table('mercadopagocuentas', function (Blueprint $table) {
                $table->dropColumn('merchantId');
            });
        }
    }
};
