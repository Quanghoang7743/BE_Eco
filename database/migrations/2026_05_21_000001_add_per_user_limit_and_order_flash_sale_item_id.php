<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPerUserLimitAndOrderFlashSaleItemId extends Migration
{
    public function up()
    {
        if (Schema::hasTable('flash_sale_items')) {
            Schema::table('flash_sale_items', function (Blueprint $table) {
                if (! Schema::hasColumn('flash_sale_items', 'per_user_limit')) {
                    $table->integer('per_user_limit')->unsigned()->nullable()->after('sale_quantity');
                    $table->index('per_user_limit');
                }
            });

            // Backfill: set per_user_limit = sale_quantity where null
            \Illuminate\Support\Facades\DB::table('flash_sale_items')
                ->whereNull('per_user_limit')
                ->update(['per_user_limit' => \Illuminate\Support\Facades\DB::raw('sale_quantity')]);
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (! Schema::hasColumn('order_items', 'flash_sale_item_id')) {
                    $table->unsignedBigInteger('flash_sale_item_id')->nullable()->after('variant_id');
                    $table->index('flash_sale_item_id');
                }
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('flash_sale_items')) {
            Schema::table('flash_sale_items', function (Blueprint $table) {
                if (Schema::hasColumn('flash_sale_items', 'per_user_limit')) {
                    $table->dropIndex(['per_user_limit']);
                    $table->dropColumn('per_user_limit');
                }
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (Schema::hasColumn('order_items', 'flash_sale_item_id')) {
                    $table->dropIndex(['flash_sale_item_id']);
                    $table->dropColumn('flash_sale_item_id');
                }
            });
        }
    }
}
