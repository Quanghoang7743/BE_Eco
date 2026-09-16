<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_article_interactions')) {
            return;
        }

        Schema::create('user_article_interactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('article_id');
            $table->boolean('is_liked')->default(false);
            $table->timestamp('liked_at')->nullable();
            $table->boolean('is_saved')->default(false);
            $table->timestamp('saved_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'article_id'], 'user_article_interactions_user_article_unique');
            $table->index(['article_id', 'is_liked'], 'user_article_interactions_article_liked_idx');
            $table->index(['article_id', 'is_saved'], 'user_article_interactions_article_saved_idx');
            $table->index('user_id', 'user_article_interactions_user_id_idx');

            $table->foreign('user_id', 'user_article_interactions_user_fk')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('article_id', 'user_article_interactions_article_fk')
                ->references('id')
                ->on('articles')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_article_interactions');
    }
};
