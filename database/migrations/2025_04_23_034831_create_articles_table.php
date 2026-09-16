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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();$table->string('title');                         // Tiêu đề
            $table->string('slug')->unique();                // Đường dẫn
            $table->text('summary')->nullable();             // Nội dung tóm tắt
            $table->longText('content')->nullable();         // Nội dung chi tiết
            $table->unsignedBigInteger('category_id')->nullable(); // Chuyên mục
            $table->integer('sort_order')->default(0);       // Thứ tự sắp xếp
            $table->string('image')->nullable();             // Ảnh minh họa
            $table->timestamp('published_at')->nullable();   // Thời gian đăng tin
            $table->timestamps();
            
            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
