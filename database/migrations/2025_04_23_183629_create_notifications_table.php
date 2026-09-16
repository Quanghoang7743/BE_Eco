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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('message');
            $table->unsignedBigInteger('order_id')->nullable(); // Cột order_id liên kết với đơn hàng, có thể NULL
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('set null'); // Khóa ngoại liên kết với bảng orders
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
