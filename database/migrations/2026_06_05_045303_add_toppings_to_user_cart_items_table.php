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
        Schema::table('user_cart_items', function (Blueprint $table) {
            $table->dropUnique('user_cart_items_user_product_variant_unique');
            $table->text('toppings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_cart_items', function (Blueprint $table) {
            $table->dropColumn('toppings');
            $table->unique(['user_id', 'product_id', 'variant_id'], 'user_cart_items_user_product_variant_unique');
        });
    }
};
