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
        Schema::create('reward_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // liên kết tới bảng users
            $table->foreignId('reward_id')->nullable()->constrained('rewards')->nullOnDelete(); // phần thưởng có thể null nếu không trúng
            $table->string('reward_name'); // lưu tên phần thưởng trúng tại thời điểm đó
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reward_logs');
    }
};
