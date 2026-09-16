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
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('room_code', 20);
            $table->date('room_date');
            $table->unsignedInteger('room_number');

            $table->string('status', 50)->default('WAITING');

            $table->unsignedInteger('current_votes')->default(0);
            $table->unsignedInteger('required_votes')->default(1000);

            $table->string('prize_type', 100)->default('electric_bike');
            $table->decimal('prize_value', 15, 2)->nullable();
            $table->text('prize_description')->nullable();
            $table->text('prize_image_url')->nullable();

            $table->uuid('winner_id')->nullable();
            $table->uuid('winner_ticket_id')->nullable();
            $table->string('winner_name')->nullable();

            $table->timestamp('draw_started_at')->nullable();
            $table->timestamp('draw_completed_at')->nullable();

            $table->string('draw_seed')->nullable();
            $table->string('block_hash')->nullable();
            $table->string('tx_hash')->nullable();

            $table->timestamps();

            $table->index('room_date');
            $table->index('status');
            $table->index(['room_date', 'status']);
            $table->index('current_votes');
            $table->index('winner_id');
            $table->unique(['room_date', 'room_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
