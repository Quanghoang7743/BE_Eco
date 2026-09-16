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
        Schema::create('categories', function (Blueprint $table) {
            $table->id(); // ID chính của bảng
            $table->string('title'); // Tiêu đề chuyên mục
            $table->string('slug')->unique();
            $table->foreignId('parent_category_id')->nullable()->constrained('categories')->onDelete('cascade'); // Danh mục cha
            $table->text('description')->nullable(); // Mô tả chuyên mục
            $table->timestamps(); // Created at và Updated at
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
