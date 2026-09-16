<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('specialist_tasks')) {
            Schema::create('specialist_tasks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('specialist_id')->index();
                $table->uuid('store_id')->nullable()->index();
                $table->string('title', 191);
                $table->text('description')->nullable();
                $table->string('status', 30)->default('pending')->index();
                $table->timestamp('due_at')->nullable()->index();
                $table->unsignedBigInteger('assigned_by')->nullable()->index();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['specialist_id', 'status', 'due_at'], 'idx_specialist_tasks_inbox');
            });
        }

        if (! Schema::hasTable('specialist_ratings')) {
            Schema::create('specialist_ratings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('specialist_id')->index();
                $table->uuid('store_id')->nullable()->index();
                $table->date('period_from')->nullable()->index();
                $table->date('period_to')->nullable()->index();
                $table->unsignedTinyInteger('stars')->default(0);
                $table->decimal('score', 8, 2)->default(0);
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable()->index();
                $table->timestamp('approved_at')->nullable()->index();
                $table->timestamps();

                $table->index(['specialist_id', 'approved_at'], 'idx_specialist_ratings_approved');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('specialist_ratings');
        Schema::dropIfExists('specialist_tasks');
    }
};
