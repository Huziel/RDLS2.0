<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('datospersonales', function (Blueprint $table) {
            $table->string('tipo')->nullable()->change();
            $table->string('modelo')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('datospersonales', function (Blueprint $table) {
            $table->integer('tipo')->nullable()->change();
            $table->integer('modelo')->nullable()->change();
        });
    }
};
