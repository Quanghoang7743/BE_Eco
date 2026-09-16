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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();
            $table->string('thumbnail')->nullable(); // Ảnh đại diện
            $table->json('images')->nullable(); // Ảnh sản phẩm nhiều ảnh
            $table->decimal('price', 15, 2)->nullable(); // Giá niêm yết
            $table->decimal('sale_price', 15, 2)->nullable(); // Giá bán
            $table->string('slug')->unique();
            $table->string('sku')->nullable(); // Mã sản phẩm
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->integer('stock')->default(0)->comment('Số lượng tồn kho');
            $table->decimal('commission_rate', 5, 2)->default(0)->comment('% hoa hồng');
            $table->foreign('category_id')->references('id')->on('product_categories')->onDelete('set null');
            $table->foreign('group_id')->references('id')->on('product_groups')->onDelete('set null');
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
