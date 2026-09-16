<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('room_winners')) {
            return;
        }

        Schema::create('room_winners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('room_id')->index();
            $table->uuid('draw_id')->nullable()->index();

            $table->string('prize_kind', 30)->default('secondary');
            $table->string('prize_type', 50)->nullable();
            $table->string('prize_name', 255)->nullable();
            $table->uuid('prize_source_id')->nullable();
            $table->string('prize_source_type', 50)->nullable();

            $table->uuid('winner_user_id')->nullable()->index();
            $table->uuid('winner_ticket_id')->nullable()->index();
            $table->unsignedInteger('winner_index')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['room_id', 'prize_kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_winners');
    }
};
