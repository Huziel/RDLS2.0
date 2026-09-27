<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_payment_settings')) {
            Schema::create('store_payment_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->boolean('cash_on_delivery_enabled')->default(false);
                $table->timestamps();
                $table->unique('store_id', 'store_payment_settings_store_unique');
            });

            return;
        }

        foreach (['id', 'store_id', 'cash_on_delivery_enabled', 'created_at', 'updated_at'] as $column) {
            if (! Schema::hasColumn('store_payment_settings', $column)) {
                throw new RuntimeException("8B-S retry found incomplete store_payment_settings: missing {$column}.");
            }
        }
        if (DB::table('store_payment_settings')->select('store_id')->groupBy('store_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('8B-S retry found duplicate store_id values in store_payment_settings.');
        }
        if (! Schema::hasIndex('store_payment_settings', ['id'], 'primary')) {
            throw new RuntimeException('8B-S retry found incomplete store_payment_settings: missing primary key on id.');
        }
        if (! Schema::hasIndex('store_payment_settings', ['store_id'], 'unique')) {
            throw new RuntimeException('8B-S retry found incomplete store_payment_settings: missing unique store_id.');
        }

        $columns = collect(Schema::getColumns('store_payment_settings'))->keyBy('name');
        $default = $columns->get('cash_on_delivery_enabled')['default'] ?? null;
        if (! in_array($default, [false, 0, '0', 'false', "'0'"], true)) {
            throw new RuntimeException('8B-S retry found unsafe cash_on_delivery_enabled default; expected false.');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('store_payment_settings') && DB::table('store_payment_settings')->exists()) {
            throw new RuntimeException('Refusing destructive rollback: store_payment_settings contains rows.');
        }

        Schema::dropIfExists('store_payment_settings');
    }
};
