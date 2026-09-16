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
            if (!Schema::hasColumn('stores', 'slug')) {
                $table->string('slug', 255)->nullable()->unique()->after('name');
            }
            if (!Schema::hasColumn('stores', 'public_url')) {
                $table->string('public_url', 2000)->nullable()->after('slug');
            }
            if (!Schema::hasColumn('stores', 'qr_code_custom')) {
                $table->text('qr_code_custom')->nullable()->after('public_url');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'qr_code_custom')) {
                $table->dropColumn('qr_code_custom');
            }
            if (Schema::hasColumn('stores', 'public_url')) {
                $table->dropColumn('public_url');
            }
            if (Schema::hasColumn('stores', 'slug')) {
                $table->dropUnique(['slug']);
                $table->dropColumn('slug');
            }
        });
    }
};
