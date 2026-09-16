<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rooms')) {
            return;
        }

        Schema::table('rooms', function (Blueprint $table) {
            if (!Schema::hasColumn('rooms', 'secondary_prizes')) {
                $table->json('secondary_prizes')->nullable()->after('prize_image_url');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('rooms')) {
            return;
        }

        Schema::table('rooms', function (Blueprint $table) {
            if (Schema::hasColumn('rooms', 'secondary_prizes')) {
                $table->dropColumn('secondary_prizes');
            }
        });
    }
};
