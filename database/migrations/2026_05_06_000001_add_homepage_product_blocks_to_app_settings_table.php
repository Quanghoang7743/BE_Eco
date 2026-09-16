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
        if (Schema::hasTable('app_settings') && !Schema::hasColumn('app_settings', 'homepage_product_blocks')) {
            Schema::table('app_settings', function (Blueprint $table) {
                $table->json('homepage_product_blocks')->nullable()->after('homepage_block');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('app_settings') && Schema::hasColumn('app_settings', 'homepage_product_blocks')) {
            Schema::table('app_settings', function (Blueprint $table) {
                $table->dropColumn('homepage_product_blocks');
            });
        }
    }
};
