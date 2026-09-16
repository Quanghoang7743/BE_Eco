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
        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'store_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->uuid('store_id')->nullable()->after('group_id');
                $table->index('store_id', 'products_store_id_index');
            });

            if (Schema::hasTable('stores')) {
                Schema::table('products', function (Blueprint $table) {
                    $table->foreign('store_id', 'products_store_id_foreign')
                        ->references('id')
                        ->on('stores')
                        ->onDelete('cascade');
                });
            }
        }

        if (Schema::hasTable('attributes') && !Schema::hasColumn('attributes', 'store_id')) {
            Schema::table('attributes', function (Blueprint $table) {
                $table->uuid('store_id')->nullable()->after('id');
                $table->index('store_id', 'attributes_store_id_index');
            });

            if (Schema::hasTable('stores')) {
                Schema::table('attributes', function (Blueprint $table) {
                    $table->foreign('store_id', 'attributes_store_id_foreign')
                        ->references('id')
                        ->on('stores')
                        ->onDelete('cascade');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'store_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropForeign('products_store_id_foreign');
                $table->dropIndex('products_store_id_index');
                $table->dropColumn('store_id');
            });
        }

        if (Schema::hasTable('attributes') && Schema::hasColumn('attributes', 'store_id')) {
            Schema::table('attributes', function (Blueprint $table) {
                $table->dropForeign('attributes_store_id_foreign');
                $table->dropIndex('attributes_store_id_index');
                $table->dropColumn('store_id');
            });
        }
    }
};
