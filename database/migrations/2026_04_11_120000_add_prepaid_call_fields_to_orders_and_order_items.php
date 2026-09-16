<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'parent_order_id')) {
                    $table->uuid('parent_order_id')->nullable()->after('store_id');
                    $table->index('parent_order_id', 'orders_parent_order_id_idx');
                }
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (!Schema::hasColumn('order_items', 'called_quantity')) {
                    $table->integer('called_quantity')->default(0)->after('quantity');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (Schema::hasColumn('orders', 'parent_order_id')) {
                    $table->dropIndex('orders_parent_order_id_idx');
                    $table->dropColumn('parent_order_id');
                }
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (Schema::hasColumn('order_items', 'called_quantity')) {
                    $table->dropColumn('called_quantity');
                }
            });
        }
    }
};
