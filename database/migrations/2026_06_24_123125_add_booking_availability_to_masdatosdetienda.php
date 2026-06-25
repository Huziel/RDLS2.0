<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masdatosdetienda', function (Blueprint $table) {
            if (!Schema::hasColumn('masdatosdetienda', 'booking_days')) {
                $table->text('booking_days')->nullable();
            }
            if (!Schema::hasColumn('masdatosdetienda', 'booking_hours')) {
                $table->string('booking_hours', 20)->nullable()->default('09:00-18:00');
            }
        });
    }

    public function down(): void
    {
        Schema::table('masdatosdetienda', function (Blueprint $table) {
            $table->dropColumn(['booking_days', 'booking_hours']);
        });
    }
};
