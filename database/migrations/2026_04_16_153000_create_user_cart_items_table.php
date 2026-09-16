<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_cart_items')) {
            return;
        }

        Schema::create('user_cart_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
            // 0 means no variant selected.
            $table->unsignedBigInteger('variant_id')->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'product_id', 'variant_id'], 'user_cart_items_user_product_variant_unique');
            $table->index('user_id', 'user_cart_items_user_id_idx');
            $table->index('product_id', 'user_cart_items_product_id_idx');
            $table->index('variant_id', 'user_cart_items_variant_id_idx');

            $table->foreign('user_id', 'user_cart_items_user_fk')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('product_id', 'user_cart_items_product_fk')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_cart_items')) {
            return;
        }

        Schema::table('user_cart_items', function (Blueprint $table) {
            $table->dropForeign('user_cart_items_user_fk');
            $table->dropForeign('user_cart_items_product_fk');
            $table->dropUnique('user_cart_items_user_product_variant_unique');
            $table->dropIndex('user_cart_items_user_id_idx');
            $table->dropIndex('user_cart_items_product_id_idx');
            $table->dropIndex('user_cart_items_variant_id_idx');
        });

        Schema::dropIfExists('user_cart_items');
    }
};
