<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->json('landing_colors')->nullable()->after('login_colors');
            $table->longText('landing_custom_html')->nullable()->after('landing_colors');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['landing_colors', 'landing_custom_html']);
        });
    }
};
