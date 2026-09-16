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
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('image')->nullable(); // ảnh minh họa phần thưởng
            $table->unsignedInteger('probability'); // xác suất trúng (tính theo % hoặc trọng số)
            $table->unsignedInteger('quantity')->nullable(); // số lượng quà còn lại (null = không giới hạn)
            $table->enum('type', ['point', 'voucher', 'product', 'extra_spin', 'none'])->default('none');
            $table->unsignedInteger('value')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
};
