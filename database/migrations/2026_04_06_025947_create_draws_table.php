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
        Schema::create('draws', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('room_id')->nullable();
            $table->string('draw_code', 50)->unique();

            $table->string('status', 50)->default('pending');

            $table->string('random_seed')->nullable();
            $table->string('algorithm', 50)->default('seed_hash');

            $table->string('block_hash')->nullable();
            $table->unsignedBigInteger('block_number')->nullable();
            $table->timestamp('block_timestamp')->nullable();

            $table->uuid('winner_ticket_id')->nullable();
            $table->uuid('winner_user_id')->nullable();
            $table->unsignedInteger('winner_index')->nullable();

            $table->string('tx_hash')->nullable();
            $table->string('tx_status', 50)->nullable();
            $table->decimal('tx_gas_fee', 15, 2)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();

            $table->index('room_id');
            $table->index('status');
            $table->index('draw_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('draws');
    }
};
