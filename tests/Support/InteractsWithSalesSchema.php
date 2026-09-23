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
            $table->string('order_state', 20)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('restock_key', 64)->nullable();
            $table->string('delivery_type', 20)->nullable();
            $table->unique('order');
            $table->unique(['serial', 'checkout_key']);
            $table->unique('restock_key');
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
            $table->string('merchantId')->nullable()->unique();
            $table->unique('idLog');
        });

        Schema::create('mercadopago', function (Blueprint $table) {
            $table->id();
            $table->string('orderP', 100);
            $table->integer('status');
            $table->string('preference')->default('');
            $table->string('fecha')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unique('orderP');
        });

        Schema::create('gastosextras', function (Blueprint $table) {
            $table->id();
            $table->string('orderP', 100);
            $table->decimal('precio', 14, 2);
            $table->string('tipoCargo');
        });

        Schema::create('ordenenvio', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tienda');
            $table->unsignedBigInteger('delivery')->nullable();
            $table->unsignedBigInteger('ordenCompra')->unique();
            $table->string('fechaIn')->nullable();
            $table->string('status')->default('0');
            $table->string('assignment_mode')->default('pool');
            $table->string('departure_state')->default('not_departed');
            $table->timestamp('departed_at')->nullable();
        });

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('store_id');
            $table->string('method');
            $table->string('terms');
            $table->string('status')->default('pending');
            $table->string('currency', 3)->default('MXN');
            $table->decimal('products_amount', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('shipping_amount', 14, 2)->default(0);
            $table->decimal('extra_amount', 14, 2)->default(0);
            $table->decimal('amount_due', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('amount_refunded', 14, 2)->default(0);
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refund_requested_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->unsignedBigInteger('refunded_by')->nullable();
            $table->string('bank_reference')->nullable();
            $table->string('cash_reference')->nullable();
            $table->string('refund_reference')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_payment_id')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_payment_id']);
        });

        Schema::create('order_payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('store_id');
            $table->string('disk');
            $table->string('path')->unique();
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('reference');
            $table->timestamps();
        });

        Schema::create('order_provider_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('order_id');
            $table->string('provider', 40);
            $table->string('provider_payment_id', 190);
            $table->string('preference_id', 190)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('MXN');
            $table->string('remote_status', 50);
            $table->timestamps();
            $table->unique(['provider', 'provider_payment_id']);
            $table->index(['payment_id', 'created_at']);
        });

        Schema::create('order_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('store_id');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('restock_key', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('order_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type');
            $table->string('event_type');
            $table->string('event_key')->unique();
            $table->string('request_hash', 64);
            $table->json('previous')->nullable();
            $table->json('next')->nullable();
            $table->unsignedSmallInteger('response_status');
            $table->json('response_data')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price_percent', 5, 2)->default(5.00);
            $table->integer('max_products')->nullable();
            $table->json('modules')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('store_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->integer('store_id')->index();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->decimal('monthly_sales', 12, 2)->default(0);
            $table->decimal('amount_due', 12, 2)->default(0);
            $table->string('status')->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->integer('store_id')->index();
            $table->foreignId('store_subscription_id')->constrained('store_subscriptions')->cascadeOnDelete();
            $table->decimal('monthly_sales', 12, 2)->default(0);
            $table->decimal('percent', 5, 2);
            $table->decimal('amount', 12, 2);
            $table->string('period');
            $table->string('status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }
}
