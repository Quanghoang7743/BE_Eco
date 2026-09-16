<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financial_reconciliations')) {
            Schema::table('financial_reconciliations', function (Blueprint $table): void {
                if (! Schema::hasColumn('financial_reconciliations', 'refund_amount')) {
                    $table->decimal('refund_amount', 15, 2)->default(0)->after('ticket_reward');
                }
                if (! Schema::hasColumn('financial_reconciliations', 'cash_collected_amount')) {
                    $table->decimal('cash_collected_amount', 15, 2)->default(0)->after('refund_amount');
                }
                if (! Schema::hasColumn('financial_reconciliations', 'withdrawable_amount')) {
                    $table->decimal('withdrawable_amount', 15, 2)->default(0)->after('cash_collected_amount');
                }
                if (! Schema::hasColumn('financial_reconciliations', 'approved_withdrawable_amount')) {
                    $table->decimal('approved_withdrawable_amount', 15, 2)->default(0)->after('withdrawable_amount');
                }
                if (! Schema::hasColumn('financial_reconciliations', 'source_hash')) {
                    $table->string('source_hash', 80)->nullable()->after('approved_withdrawable_amount');
                }
                if (! Schema::hasColumn('financial_reconciliations', 'submitted_source')) {
                    $table->string('submitted_source', 30)->nullable()->after('source_hash');
                }
                if (! Schema::hasColumn('financial_reconciliations', 'submitted_at')) {
                    $table->timestamp('submitted_at')->nullable()->after('submitted_source');
                }
                if (! Schema::hasColumn('financial_reconciliations', 'submitted_by')) {
                    $table->string('submitted_by', 64)->nullable()->after('submitted_at');
                }
            });
        }

        if (! Schema::hasTable('store_wallet_withdrawal_requests')) {
            Schema::create('store_wallet_withdrawal_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('store_id')->index();
                $table->uuid('financial_reconciliation_id')->index('idx_store_withdrawals_recon_id');
                $table->decimal('amount', 15, 2);
                $table->string('status', 30)->index();
                $table->string('bank_name', 255)->nullable();
                $table->string('account_number', 50);
                $table->string('account_name', 255);
                $table->string('idempotency_key', 160)->nullable()->unique('uniq_store_wallet_withdrawal_idempotency');
                $table->timestamp('paid_at')->nullable();
                $table->string('paid_by', 64)->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->string('rejected_by', 64)->nullable();
                $table->text('admin_note')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['store_id', 'status', 'created_at'], 'idx_store_withdrawals_store_status_created');
                $table->foreign('store_id', 'fk_store_withdrawals_store')
                    ->references('id')
                    ->on('stores')
                    ->restrictOnDelete();
                $table->foreign('financial_reconciliation_id', 'fk_store_withdrawals_financial_recon')
                    ->references('id')
                    ->on('financial_reconciliations')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_wallet_withdrawal_requests');

        if (! Schema::hasTable('financial_reconciliations')) {
            return;
        }

        $columns = array_values(array_filter([
            'refund_amount',
            'cash_collected_amount',
            'withdrawable_amount',
            'approved_withdrawable_amount',
            'source_hash',
            'submitted_source',
            'submitted_at',
            'submitted_by',
        ], static fn (string $column): bool => Schema::hasColumn('financial_reconciliations', $column)));

        if ($columns !== []) {
            Schema::table('financial_reconciliations', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
