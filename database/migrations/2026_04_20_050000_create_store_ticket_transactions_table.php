<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_ticket_transactions')) {
            return;
        }

        Schema::create('store_ticket_transactions', function (Blueprint $table) {
            $table->uuid('transaction_id')->primary();
            $table->uuid('order_id')->nullable()->index();
            $table->uuid('store_id')->nullable()->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->integer('tickets_consumed')->default(0);
            $table->decimal('unit_price_at_time', 15, 2)->default(0);
            $table->decimal('total_debit_amount', 15, 2)->default(0);
            $table->string('event_type', 50)->default('ORDER_DELIVERED')->index();
            $table->string('ticket_calc_type', 30)->nullable();
            $table->decimal('ticket_value_snapshot', 15, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('timestamp')->useCurrent()->index();
            $table->timestamps();

            $table->index(['store_id', 'timestamp']);
            $table->index(['store_id', 'product_id', 'timestamp']);
            $table->unique(['order_id', 'product_id', 'event_type'], 'uniq_store_ticket_order_product_event');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('store_ticket_transactions')) {
            return;
        }

        Schema::dropIfExists('store_ticket_transactions');
    }
};
