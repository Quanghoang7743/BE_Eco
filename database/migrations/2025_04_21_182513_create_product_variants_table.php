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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->integer('stock')->default(0); // Số lượng tồn kho cho biến thể này
            $table->decimal('price', 15, 2)->nullable(); // Giá niêm yết cho biến thể này
            $table->decimal('sale_price', 15, 2)->nullable(); // Giá bán cho biến thể này

            $table->string('image')->nullable(); // Hình ảnh riêng cho biến thể này
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
