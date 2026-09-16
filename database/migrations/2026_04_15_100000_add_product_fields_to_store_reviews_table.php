<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('store_reviews')) {
            return;
        }

        Schema::table('store_reviews', function (Blueprint $table) {
            if (!Schema::hasColumn('store_reviews', 'product_name')) {
                $table->string('product_name', 255)->nullable()->after('order_id');
            }

            if (!Schema::hasColumn('store_reviews', 'menu_item_id')) {
                $table->uuid('menu_item_id')->nullable()->after('product_name');
            }

            if (!Schema::hasColumn('store_reviews', 'product_id')) {
                $table->uuid('product_id')->nullable()->after('menu_item_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('store_reviews')) {
            return;
        }

        Schema::table('store_reviews', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('store_reviews', 'product_id')) {
                $columns[] = 'product_id';
            }

            if (Schema::hasColumn('store_reviews', 'menu_item_id')) {
                $columns[] = 'menu_item_id';
            }

            if (Schema::hasColumn('store_reviews', 'product_name')) {
                $columns[] = 'product_name';
            }

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
