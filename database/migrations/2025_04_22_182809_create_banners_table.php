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
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->integer('order')->unsigned()->default(1);; // Thứ tự
            $table->string('image'); // Hình ảnh banner
            $table->string('title'); // Tiêu đề banner
            $table->string('link')->nullable(); // Đường dẫn đến app hoặc website
            $table->string('position'); // Vị trí hiển thị
            $table->timestamp('start_date')->nullable(); // Ngày bắt đầu
            $table->timestamp('end_date')->nullable(); // Ngày kết thúc
            $table->boolean('status')->default(true); // Trạng thái banner
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
