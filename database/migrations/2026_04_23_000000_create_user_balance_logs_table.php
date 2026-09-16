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
        Schema::create('affiliate_wallet_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('action_type', 50);
            $table->integer('amount_change');
            $table->integer('balance_before')->nullable();
            $table->integer('balance_after')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('action_type');
            $table->index('created_at');
            $table->index(['user_id', 'action_type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_wallet_logs');
    }
};
