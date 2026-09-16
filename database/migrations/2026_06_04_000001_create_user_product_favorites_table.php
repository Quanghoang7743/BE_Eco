<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_product_favorites')) {
            return;
        }

        Schema::create('user_product_favorites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamps();

            $table->unique(['user_id', 'product_id'], 'user_product_favorites_user_product_unique');
            $table->index('user_id', 'user_product_favorites_user_id_idx');
            $table->index('product_id', 'user_product_favorites_product_id_idx');

            $table->foreign('user_id', 'user_product_favorites_user_fk')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('product_id', 'user_product_favorites_product_fk')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_product_favorites');
    }
};
