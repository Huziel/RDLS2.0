<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('log', 'active')) {
            Schema::table('log', fn(Blueprint $t) => $t->tinyInteger('active')->default(1)->after('type'));
        }
    }
    public function down(): void {
        Schema::table('log', fn(Blueprint $t) => $t->dropColumn('active'));
    }
};
