<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addFundingBreakdownColumn();
        $this->createFinancialReconciliationOffsetsTable();
        $this->seedReturnPermissions();
        $this->addLedgerIndexes();
        $this->replaceCascadeForeignKeys();
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_reconciliation_offsets');

        if (Schema::hasTable('order_returns') && Schema::hasColumn('order_returns', 'funding_breakdown')) {
            Schema::table('order_returns', function (Blueprint $table): void {
                $table->dropColumn('funding_breakdown');
            });
        }
    }

    private function addFundingBreakdownColumn(): void
    {
        if (! Schema::hasTable('order_returns') || Schema::hasColumn('order_returns', 'funding_breakdown')) {
            return;
        }

        Schema::table('order_returns', function (Blueprint $table): void {
            $table->json('funding_breakdown')->nullable()->after('refund_funding_source');
        });
    }

    private function createFinancialReconciliationOffsetsTable(): void
    {
        if (Schema::hasTable('financial_reconciliation_offsets')) {
            return;
        }

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

    private function seedReturnPermissions(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasColumn('permissions', 'code')) {
            return;
        }

        $now = now();
        foreach ([
            ['code' => 'VIEW_RETURN', 'name' => 'Xem yeu cau tra hang'],
            ['code' => 'PROCESS_RETURN', 'name' => 'Xu ly yeu cau tra hang'],
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $permission['code']],
                [
                    'name' => $permission['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function addLedgerIndexes(): void
    {
        $this->tryAddIndex('money_wallet_ledger_entries', ['wallet_account_id', 'created_at', 'id'], 'idx_mw_ledger_account_created');
        $this->tryAddIndex('money_wallet_ledger_entries', ['source_type', 'source_id', 'entry_type'], 'idx_mw_ledger_source_entry');
        $this->tryAddIndex('store_liability_ledger_entries', ['store_id', 'created_at', 'id'], 'idx_store_liability_store_created');
        $this->tryAddIndex('store_liability_ledger_entries', ['source_type', 'source_id', 'entry_type'], 'idx_store_liability_source_entry');
    }

    private function tryAddIndex(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        try {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
                $table->index($columns, $indexName);
            });
        } catch (Throwable) {
            // Existing installs may already have this index from a manual hardening pass.
        }
    }

    private function replaceCascadeForeignKeys(): void
    {
        $this->replaceForeign('money_wallet_ledger_entries', 'fk_money_wallet_ledger_account', function (Blueprint $table): void {
            $table->foreign('wallet_account_id', 'fk_money_wallet_ledger_account')
                ->references('id')
                ->on('money_wallet_accounts')
                ->restrictOnDelete();
        });

        $this->replaceForeign('store_liability_ledger_entries', 'fk_store_liability_store', function (Blueprint $table): void {
            $table->foreign('store_id', 'fk_store_liability_store')
                ->references('id')
                ->on('stores')
                ->restrictOnDelete();
        });

        $this->replaceForeign('order_returns', 'fk_order_returns_order', function (Blueprint $table): void {
            $table->foreign('order_id', 'fk_order_returns_order')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete();
        });

        $this->replaceForeign('order_returns', 'fk_order_returns_store', function (Blueprint $table): void {
            $table->foreign('store_id', 'fk_order_returns_store')
                ->references('id')
                ->on('stores')
                ->restrictOnDelete();
        });

        $this->replaceForeign('order_returns', 'fk_order_returns_customer_wallet_ledger', function (Blueprint $table): void {
            $table->foreign('customer_wallet_ledger_id', 'fk_order_returns_customer_wallet_ledger')
                ->references('id')
                ->on('money_wallet_ledger_entries')
                ->restrictOnDelete();
        });

        $this->replaceForeign('order_returns', 'fk_order_returns_store_liability_ledger', function (Blueprint $table): void {
            $table->foreign('store_liability_ledger_id', 'fk_order_returns_store_liability_ledger')
                ->references('id')
                ->on('store_liability_ledger_entries')
                ->restrictOnDelete();
        });

        $this->replaceForeign('order_return_items', 'fk_order_return_items_return', function (Blueprint $table): void {
            $table->foreign('order_return_id', 'fk_order_return_items_return')
                ->references('id')
                ->on('order_returns')
                ->restrictOnDelete();
        });

        $this->replaceForeign('order_return_items', 'fk_order_return_items_order_item', function (Blueprint $table): void {
            $table->foreign('order_item_id', 'fk_order_return_items_order_item')
                ->references('id')
                ->on('order_items')
                ->restrictOnDelete();
        });

        $this->replaceForeign('money_wallet_withdrawal_requests', 'fk_money_wallet_withdrawals_account', function (Blueprint $table): void {
            $table->foreign('wallet_account_id', 'fk_money_wallet_withdrawals_account')
                ->references('id')
                ->on('money_wallet_accounts')
                ->restrictOnDelete();
        });

        $this->replaceForeign('money_wallet_withdrawal_requests', 'fk_money_wallet_withdrawals_locked_ledger', function (Blueprint $table): void {
            $table->foreign('locked_ledger_id', 'fk_money_wallet_withdrawals_locked_ledger')
                ->references('id')
                ->on('money_wallet_ledger_entries')
                ->restrictOnDelete();
        });

        $this->replaceForeign('money_wallet_withdrawal_requests', 'fk_money_wallet_withdrawals_paid_ledger', function (Blueprint $table): void {
            $table->foreign('paid_ledger_id', 'fk_money_wallet_withdrawals_paid_ledger')
                ->references('id')
                ->on('money_wallet_ledger_entries')
                ->restrictOnDelete();
        });
    }

    private function replaceForeign(string $tableName, string $constraintName, Closure $definition): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        try {
            Schema::table($tableName, function (Blueprint $table) use ($constraintName): void {
                $table->dropForeign($constraintName);
            });
        } catch (Throwable) {
            return;
        }

        try {
            Schema::table($tableName, $definition);
        } catch (Throwable) {
            // Keep migration non-blocking on databases where the constraint was already hardened manually.
        }
    }
};
