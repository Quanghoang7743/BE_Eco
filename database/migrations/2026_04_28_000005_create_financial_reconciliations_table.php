<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financial_reconciliations')) {
            return;
        }

        Schema::create('financial_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id')->index();
            $table->string('period', 10)->index();
            $table->date('period_date')->index();
            $table->string('status', 30)->default('pending_shop');

            $table->decimal('revenue_total', 15, 2)->default(0);
            $table->decimal('revenue_cash', 15, 2)->default(0);
            $table->decimal('revenue_transfer', 15, 2)->default(0);
            $table->decimal('revenue_cod', 15, 2)->default(0);
            $table->decimal('affiliate_commission', 15, 2)->default(0);
            $table->decimal('ticket_reward', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'period', 'period_date'], 'uniq_store_period_date');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('financial_reconciliations')) {
            return;
        }

        Schema::dropIfExists('financial_reconciliations');
    }
};
