<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            if (!Schema::hasColumn('stores', 'banner_url')) {
                $table->text('banner_url')->nullable()->after('cover_image');
            }
            if (!Schema::hasColumn('stores', 'brand_color')) {
                $table->string('brand_color', 10)->nullable()->after('banner_url');
            }
            if (!Schema::hasColumn('stores', 'slogan')) {
                $table->string('slogan', 255)->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'banner_url')) {
                $table->dropColumn('banner_url');
            }
            if (Schema::hasColumn('stores', 'brand_color')) {
                $table->dropColumn('brand_color');
            }
            if (Schema::hasColumn('stores', 'slogan')) {
                $table->dropColumn('slogan');
            }
        });
    }
};
