<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_wallet_transactions')) {
            return;
        }

        Schema::create('store_wallet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->enum('type', ['topup', 'withdraw', 'adjustment']);
            $table->decimal('amount', 15, 2);
            $table->decimal('wallet_balance_before', 15, 2)->default(0);
            $table->decimal('wallet_balance_after', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->string('reference_code', 100)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['store_id', 'created_at'], 'idx_swt_store_created');
            $table->index(['type', 'created_at'], 'idx_swt_type_created');
            $table->index('reference_code', 'idx_swt_reference_code');

            // Foreign key
            $table->foreign('store_id')
                  ->references('id')
                  ->on('stores')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_wallet_transactions');
    }
};
