<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_store_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('store_id');
            $table->boolean('is_following')->default(false);
            $table->timestamp('followed_at')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->timestamp('favorited_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'store_id']);
            $table->index(['store_id', 'is_following']);
            $table->index(['store_id', 'is_favorite']);
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_store_preferences');
    }
};
