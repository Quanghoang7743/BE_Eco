<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stores') || Schema::hasColumn('stores', 'banner_urls')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->json('banner_urls')->nullable()->after('banner_url');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stores') || ! Schema::hasColumn('stores', 'banner_urls')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('banner_urls');
        });
    }
};
