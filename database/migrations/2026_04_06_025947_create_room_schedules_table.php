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
        Schema::create('room_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('schedule_type', 50)->nullable();
            $table->boolean('is_active')->default(true);

            $table->string('cron_expression', 100)->nullable();
            $table->string('timezone', 50)->default('Asia/Ho_Chi_Minh');

            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->string('last_run_status', 50)->nullable();
            $table->text('last_run_message')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_schedules');
    }
};
