<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('specialist_notifications')) {
            return;
        }

        Schema::create('specialist_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('specialist_id')->index();
            $table->string('type', 80)->index();
            $table->string('title', 191);
            $table->text('message');
            $table->json('data')->nullable();
            $table->string('action_url', 500)->nullable();
            $table->string('event_key', 191)->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['specialist_id', 'is_read', 'created_at'], 'idx_specialist_notifications_inbox');
            $table->unique(['specialist_id', 'event_key'], 'uniq_specialist_notification_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specialist_notifications');
    }
};
