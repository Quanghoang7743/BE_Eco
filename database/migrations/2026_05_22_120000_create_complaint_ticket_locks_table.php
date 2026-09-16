<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaint_ticket_locks', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('complaint_id', 36)->index();
            $table->string('ticket_id', 36)->index();
            $table->string('locked_from_status', 50)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 50)->nullable();

            $table->unique(['complaint_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_ticket_locks');
    }
};
