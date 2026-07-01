<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price_percent', 5, 2)->default(5.00); // % of monthly sales
            $table->integer('max_products')->nullable(); // null = unlimited
            $table->json('modules')->nullable(); // enabled module list
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
            $table->string('status')->default('active'); // active, cancelled, expired
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
            $table->string('period'); // YYYY-MM
            $table->string('status')->default('pending'); // pending, paid
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('store_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
