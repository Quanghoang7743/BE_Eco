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
        if (!Schema::hasTable('banners')) {
            return;
        }

        Schema::table('banners', function (Blueprint $table) {
            if (!Schema::hasColumn('banners', 'banner_type')) {
                $table->string('banner_type', 32)->default('room_link')->after('position');
            }

            if (!Schema::hasColumn('banners', 'room_id')) {
                $table->string('room_id')->nullable()->after('banner_type');
            }

            if (!Schema::hasColumn('banners', 'room_selection_mode')) {
                $table->string('room_selection_mode', 32)->nullable()->after('room_id');
            }

            if (!Schema::hasColumn('banners', 'headline')) {
                $table->string('headline')->nullable()->after('title');
            }

            if (!Schema::hasColumn('banners', 'description')) {
                $table->text('description')->nullable()->after('headline');
            }

            if (!Schema::hasColumn('banners', 'cta_label')) {
                $table->string('cta_label')->nullable()->after('description');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('banners')) {
            return;
        }

        Schema::table('banners', function (Blueprint $table) {
            if (Schema::hasColumn('banners', 'cta_label')) {
                $table->dropColumn('cta_label');
            }

            if (Schema::hasColumn('banners', 'description')) {
                $table->dropColumn('description');
            }

            if (Schema::hasColumn('banners', 'headline')) {
                $table->dropColumn('headline');
            }

            if (Schema::hasColumn('banners', 'room_id')) {
                $table->dropColumn('room_id');
            }

            if (Schema::hasColumn('banners', 'room_selection_mode')) {
                $table->dropColumn('room_selection_mode');
            }

            if (Schema::hasColumn('banners', 'banner_type')) {
                $table->dropColumn('banner_type');
            }
        });
    }
};
