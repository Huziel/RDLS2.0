<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pventageneral', 'telefono')) {
            Schema::table('pventageneral', function (Blueprint $table) {
                $table->string('telefono')->nullable()->after('nombre');
            });
        }
        if (!Schema::hasColumn('pventageneralhisto', 'telefono')) {
            Schema::table('pventageneralhisto', function (Blueprint $table) {
                $table->string('telefono')->nullable()->after('nombre');
            });
        }
    }

    public function down(): void
    {
        Schema::table('pventageneral', function (Blueprint $table) {
            $table->dropColumn('telefono');
        });
        Schema::table('pventageneralhisto', function (Blueprint $table) {
            $table->dropColumn('telefono');
        });
    }
};
