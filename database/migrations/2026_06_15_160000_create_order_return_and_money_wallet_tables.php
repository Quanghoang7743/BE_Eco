<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('money_wallet_accounts')) {
            Schema::create('money_wallet_accounts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('owner_type', 20)->index();
                $table->string('owner_id', 64)->index();
                $table->string('currency', 10)->default('VND');
                $table->decimal('available_balance', 15, 2)->default(0);
                $table->decimal('locked_balance', 15, 2)->default(0);
                $table->unsignedBigInteger('version')->default(0);
                $table->timestamps();

                $table->unique(['owner_type', 'owner_id', 'currency'], 'uniq_money_wallet_owner_currency');
            });
        }

        if (! Schema::hasTable('money_wallet_ledger_entries')) {
            Schema::create('money_wallet_ledger_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('wallet_account_id')->index();
                $table->string('direction', 10);
                $table->decimal('amount', 15, 2);
                $table->decimal('balance_before', 15, 2);
                $table->decimal('balance_after', 15, 2);
                $table->string('entry_type', 50)->index();
                $table->string('source_type', 50)->index();
                $table->string('source_id', 64)->index();
                $table->string('idempotency_key', 160)->unique();
                $table->string('created_by', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['wallet_account_id', 'created_at', 'id'], 'idx_mw_ledger_account_created');
                $table->index(['source_type', 'source_id', 'entry_type'], 'idx_mw_ledger_source_entry');
                $table->foreign('wallet_account_id', 'fk_money_wallet_ledger_account')
                    ->references('id')
                    ->on('money_wallet_accounts')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('store_liability_ledger_entries')) {
            Schema::create('store_liability_ledger_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('store_id')->index();
                $table->string('direction', 20);
                $table->decimal('amount', 15, 2);
                $table->decimal('balance_before', 15, 2)->nullable();
                $table->decimal('balance_after', 15, 2)->nullable();
                $table->string('entry_type', 50)->index();
                $table->string('funding_source', 30);
                $table->string('source_type', 50)->index();
                $table->string('source_id', 64)->index();
                $table->string('idempotency_key', 160)->unique();
                $table->string('created_by', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['store_id', 'created_at', 'id'], 'idx_store_liability_store_created');
                $table->index(['source_type', 'source_id', 'entry_type'], 'idx_store_liability_source_entry');
                $table->foreign('store_id', 'fk_store_liability_store')
                    ->references('id')
                    ->on('stores')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('order_returns')) {
            Schema::create('order_returns', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('return_code', 50)->unique();
                $table->uuid('order_id')->index();
                $table->string('user_id', 64)->index();
                $table->uuid('store_id')->index();
                $table->string('status', 50)->index();
                $table->string('return_type', 20);
                $table->string('refund_method', 30)->nullable();
                $table->string('reason_code', 50)->nullable();
                $table->text('reason');
                $table->json('images')->nullable();
                $table->string('video', 255)->nullable();
                $table->decimal('refund_amount_estimated', 15, 2)->default(0);
                $table->decimal('refund_amount_final', 15, 2)->default(0);
                $table->string('refund_currency', 10)->default('VND');
                $table->string('refund_funding_source', 30)->nullable();
                $table->json('funding_breakdown')->nullable();
                $table->decimal('platform_advanced_amount', 15, 2)->default(0);
                $table->string('refund_idempotency_key', 160)->nullable()->unique();
                $table->uuid('customer_wallet_ledger_id')->nullable();
                $table->uuid('store_liability_ledger_id')->nullable();
                $table->timestamp('refund_processed_at')->nullable();
                $table->string('refund_processed_by', 64)->nullable();
                $table->text('rejection_reason')->nullable();
                $table->string('tracking_code', 100)->nullable();
                $table->string('carrier_name', 100)->nullable();
                $table->json('shipping_receipt_images')->nullable();
                $table->json('shop_received_images')->nullable();
                $table->string('shop_received_video', 255)->nullable();
                $table->text('shop_received_note')->nullable();
                $table->text('dispute_reason')->nullable();
                $table->json('dispute_evidence_images')->nullable();
                $table->string('admin_resolution', 30)->nullable();
                $table->text('admin_resolution_note')->nullable();
                $table->timestamp('auto_approve_at')->nullable();
                $table->timestamps();

                $table->index(['order_id', 'status'], 'idx_order_returns_order_status');
                $table->index(['user_id', 'created_at'], 'idx_order_returns_user_created');
                $table->index(['store_id', 'status', 'created_at'], 'idx_order_returns_store_status_created');

                $table->foreign('order_id', 'fk_order_returns_order')
                    ->references('id')
                    ->on('orders')
                    ->restrictOnDelete();
                $table->foreign('store_id', 'fk_order_returns_store')
                    ->references('id')
                    ->on('stores')
                    ->restrictOnDelete();
                $table->foreign('customer_wallet_ledger_id', 'fk_order_returns_customer_wallet_ledger')
                    ->references('id')
                    ->on('money_wallet_ledger_entries')
                    ->restrictOnDelete();
                $table->foreign('store_liability_ledger_id', 'fk_order_returns_store_liability_ledger')
                    ->references('id')
                    ->on('store_liability_ledger_entries')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('order_return_items')) {
            Schema::create('order_return_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('order_return_id')->index();
                $table->uuid('order_item_id')->index();
                $table->unsignedInteger('quantity');
                $table->decimal('unit_price_snapshot', 15, 2);
                $table->decimal('gross_amount', 15, 2);
                $table->decimal('discount_allocated', 15, 2)->default(0);
                $table->decimal('refund_amount', 15, 2);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('order_return_id', 'fk_order_return_items_return')
                    ->references('id')
                    ->on('order_returns')
                    ->restrictOnDelete();
                $table->foreign('order_item_id', 'fk_order_return_items_order_item')
                    ->references('id')
                    ->on('order_items')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('money_wallet_withdrawal_requests')) {
            Schema::create('money_wallet_withdrawal_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('user_id', 64)->index();
                $table->uuid('wallet_account_id')->index();
                $table->decimal('amount', 15, 2);
                $table->string('status', 30)->index();
                $table->string('bank_id', 64)->nullable();
                $table->string('bank_code', 30)->nullable();
                $table->string('bank_name', 255)->nullable();
                $table->string('account_number', 50);
                $table->string('account_name', 255);
                $table->string('idempotency_key', 160)->nullable()->unique('uniq_money_wallet_withdrawal_idempotency');
                $table->uuid('locked_ledger_id')->nullable();
                $table->uuid('paid_ledger_id')->nullable();
                $table->text('admin_note')->nullable();
                $table->timestamps();

                $table->foreign('wallet_account_id', 'fk_money_wallet_withdrawals_account')
                    ->references('id')
                    ->on('money_wallet_accounts')
                    ->restrictOnDelete();
                $table->foreign('locked_ledger_id', 'fk_money_wallet_withdrawals_locked_ledger')
                    ->references('id')
                    ->on('money_wallet_ledger_entries')
                    ->restrictOnDelete();
                $table->foreign('paid_ledger_id', 'fk_money_wallet_withdrawals_paid_ledger')
                    ->references('id')
                    ->on('money_wallet_ledger_entries')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('financial_reconciliation_offsets')) {
            Schema::create('financial_reconciliation_offsets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('financial_reconciliation_id');
                $table->uuid('store_id');
                $table->uuid('order_return_id');
                $table->decimal('amount', 15, 2);
                $table->string('source_type', 50)->default('order_return');
                $table->string('source_id', 64)->index();
                $table->string('idempotency_key', 180)->unique();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('financial_reconciliation_id', 'idx_fin_recon_offsets_recon_id');
                $table->index('store_id', 'idx_fin_recon_offsets_store_id');
                $table->index('order_return_id', 'idx_fin_recon_offsets_return_id');
                $table->foreign('financial_reconciliation_id', 'fk_fin_recon_offsets_recon')
                    ->references('id')
                    ->on('financial_reconciliations')
                    ->restrictOnDelete();
                $table->foreign('store_id', 'fk_fin_recon_offsets_store')
                    ->references('id')
                    ->on('stores')
                    ->restrictOnDelete();
                $table->foreign('order_return_id', 'fk_fin_recon_offsets_order_return')
                    ->references('id')
                    ->on('order_returns')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_reconciliation_offsets');
        Schema::dropIfExists('money_wallet_withdrawal_requests');
        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
        Schema::dropIfExists('store_liability_ledger_entries');
        Schema::dropIfExists('money_wallet_ledger_entries');
        Schema::dropIfExists('money_wallet_accounts');
    }
};
