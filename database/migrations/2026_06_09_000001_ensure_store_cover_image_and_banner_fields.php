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

        if (!Schema::hasColumn('stores', 'cover_image')) {
            Schema::table('stores', function (Blueprint $table): void {
                if (Schema::hasColumn('stores', 'logo_url')) {
                    $table->text('cover_image')->nullable()->after('logo_url');
                    return;
                }

                $table->text('cover_image')->nullable();
            });
        }

        if (!Schema::hasColumn('stores', 'banner_url')) {
            Schema::table('stores', function (Blueprint $table): void {
                if (Schema::hasColumn('stores', 'cover_image')) {
                    $table->text('banner_url')->nullable()->after('cover_image');
                    return;
                }

                $table->text('banner_url')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally keep these media columns and their data on rollback.
    }
};
