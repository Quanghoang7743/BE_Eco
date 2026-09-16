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
        if (!Schema::hasTable('banners') || Schema::hasColumn('banners', 'room_selection_mode')) {
            return;
        }

        Schema::table('banners', function (Blueprint $table) {
            $table->string('room_selection_mode', 32)
                ->nullable()
                ->after('room_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('banners') || !Schema::hasColumn('banners', 'room_selection_mode')) {
            return;
        }

        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn('room_selection_mode');
        });
    }
};
