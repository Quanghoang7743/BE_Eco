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
        if (Schema::hasTable('product_drafts')) {
            return;
        }

        Schema::create('product_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index('store_id');
            $table->index('user_id');
            $table->index('updated_at');

            if (Schema::hasTable('stores')) {
                $table->foreign('store_id')
                    ->references('id')
                    ->on('stores')
                    ->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_drafts');
    }
};
