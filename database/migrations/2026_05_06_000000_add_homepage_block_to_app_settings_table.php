<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('app_settings') && !Schema::hasColumn('app_settings', 'homepage_block')) {
            Schema::table('app_settings', function (Blueprint $table) {
                $table->json('homepage_block')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('app_settings') && Schema::hasColumn('app_settings', 'homepage_block')) {
            Schema::table('app_settings', function (Blueprint $table) {
                $table->dropColumn('homepage_block');
            });
        }
    }
};
