<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('masdatosdetienda', 'sections')) {
            Schema::table('masdatosdetienda', function (Blueprint $table) {
                $table->longText('sections')->nullable();
            });
        }
    }
    public function down(): void
    {
        Schema::table('masdatosdetienda', function (Blueprint $table) {
            $table->dropColumn('sections');
        });
    }
};
