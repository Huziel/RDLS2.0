<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('type')->default('store');
            $table->string('target_url');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('label')->nullable();
            $table->integer('scans')->default(0);
            $table->string('image_path')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('qr_codes'); }
};
