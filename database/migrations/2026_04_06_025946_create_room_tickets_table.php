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
        Schema::create('room_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('room_id')->nullable();
            $table->uuid('ticket_id')->nullable();
            $table->uuid('user_id')->nullable();

            $table->timestamp('joined_at')->useCurrent();
            $table->boolean('is_winner')->default(false);
            $table->boolean('is_refunded')->default(false);

            $table->index('room_id');
            $table->index('user_id');
            $table->unique(['room_id', 'ticket_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_tickets');
    }
};
