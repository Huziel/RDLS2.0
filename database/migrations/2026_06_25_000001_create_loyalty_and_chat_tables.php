<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_configs', function (Blueprint $table) {
            $table->id();
            $table->integer('store_id')->index();
            $table->integer('points_per_peso')->default(1);
            $table->integer('pesos_per_point')->default(1);
            $table->integer('minimum_points_to_redeem')->default(100);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('loyalty_points', function (Blueprint $table) {
            $table->id();
            $table->integer('store_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->integer('points')->default(0);
            $table->timestamps();
            $table->unique(['store_id', 'client_id']);
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->integer('store_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->integer('points');
            $table->string('type');
            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->integer('store_id')->index();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('session_id')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id')->index();
            $table->string('sender_type');
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_points');
        Schema::dropIfExists('loyalty_configs');
    }
};
