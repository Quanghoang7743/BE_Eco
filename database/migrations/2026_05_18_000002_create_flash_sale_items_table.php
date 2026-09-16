<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sale_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('flash_sale_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->decimal('flash_sale_price', 12, 2);
            $table->unsignedInteger('sale_quantity');
            $table->unsignedInteger('sold_quantity')->default(0);
            $table->timestamps();

            $table->index(['flash_sale_id']);
            $table->index(['product_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sale_items');
    }
};
