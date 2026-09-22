<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait InteractsWithSalesSchema
{
    use InteractsWithInventorySchema;

    protected function buildSalesSchema(): void
    {
        $this->buildInventorySchema();

        Schema::create('cart', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product')->nullable();
            $table->string('price')->nullable();
            $table->string('dom')->nullable();
            $table->string('user')->nullable();
            $table->string('variation')->nullable();
            $table->integer('cant')->nullable();
            $table->string('orderC', 50)->nullable();
            $table->integer('status')->nullable();
        });

        Schema::create('cartaditivos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('noOrder')->nullable();
            $table->unsignedBigInteger('idAditivo');
            $table->string('session', 100);
        });

        Schema::create('ordencompra', function (Blueprint $table) {
            $table->id();
            $table->string('order', 50);
            $table->string('tel', 50);
            $table->string('serial');
            $table->string('session');
            $table->string('lat', 50);
            $table->string('long', 50);
            $table->string('total', 100);
            $table->double('totEnvio');
            $table->string('nombre', 50);
            $table->string('date', 100);
            $table->string('checkout_key', 64)->nullable();
            $table->decimal('loyalty_discount', 12, 2)->default(0);
            $table->unique('order');
            $table->unique(['serial', 'checkout_key']);
        });

        Schema::create('formularioenvios', function (Blueprint $table) {
            $table->id();
            $table->string('noOrder', 50);
            $table->string('nombre', 200);
            $table->string('direccion', 100);
            $table->string('ciudad', 50);
            $table->string('pais', 50);
            $table->string('codigoPostal', 20);
            $table->string('tipoEnvio', 50);
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->json('tags')->nullable();
            $table->text('notes')->nullable();
            $table->string('stage')->default('lead');
            $table->timestamp('last_purchase_at')->nullable();
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->integer('purchase_count')->default(0);
            $table->timestamps();
        });

        Schema::create('loyalty_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->unique();
            $table->integer('points_per_peso')->default(1);
            $table->integer('pesos_per_point')->default(1);
            $table->integer('minimum_points_to_redeem')->default(100);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('loyalty_points', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('client_id');
            $table->integer('points')->default(0);
            $table->timestamps();
            $table->unique(['store_id', 'client_id']);
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('client_id');
            $table->integer('points');
            $table->string('type');
            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'type', 'reference']);
        });

        Schema::create('caracteristicasadicionales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idTienda');
            $table->integer('idComponent');
            $table->boolean('active')->default(true);
        });

        Schema::create('masdatosdetienda', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idTienda');
            $table->string('nombreTienda')->nullable();
        });

        Schema::create('mercadopagocuentas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idLog');
            $table->text('secretKey');
            $table->text('publicKey');
            $table->string('merchantId')->nullable();
            $table->unique('idLog');
        });

        Schema::create('mercadopago', function (Blueprint $table) {
            $table->id();
            $table->string('orderP', 100);
            $table->integer('status');
            $table->string('preference')->default('');
            $table->string('fecha')->nullable();
            $table->unique('orderP');
        });
    }
}
