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
            if (!Schema::hasColumn('stores', 'sale_id')) {
                $table->unsignedBigInteger('sale_id')->nullable()->after('owner_id');
            }
        });

        if (Schema::hasTable('users')) {
            Schema::table('stores', function (Blueprint $table) {
                // Use default Laravel naming: stores_sale_id_foreign
                $table->foreign('sale_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'sale_id')) {
                try {
                    $table->dropForeign(['sale_id']);
                } catch (\Throwable $e) {
                    // Ignore if foreign key does not exist in a drifted environment.
                }

                $table->dropColumn('sale_id');
            }
        });
    }
};
