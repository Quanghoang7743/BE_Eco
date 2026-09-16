<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stores')) {
            Schema::table('stores', function (Blueprint $table) {
                if (! Schema::hasColumn('stores', 'credit_limit')) {
                    $table->decimal('credit_limit', 15, 2)->default(200000)->after('custom_sla_minutes');
                }
                if (! Schema::hasColumn('stores', 'ticket_debt')) {
                    $table->decimal('ticket_debt', 15, 2)->default(0)->after('credit_limit');
                }
                if (! Schema::hasColumn('stores', 'wallet_balance')) {
                    $table->decimal('wallet_balance', 15, 2)->default(0)->after('ticket_debt');
                }
                if (! Schema::hasColumn('stores', 'credit_level')) {
                    $table->unsignedSmallInteger('credit_level')->default(1)->after('wallet_balance');
                }
                if (! Schema::hasColumn('stores', 'credit_score')) {
                    $table->unsignedSmallInteger('credit_score')->default(100)->after('credit_level');
                }
                if (! Schema::hasColumn('stores', 'is_debt_locked')) {
                    $table->boolean('is_debt_locked')->default(false)->after('credit_score');
                }
                if (! Schema::hasColumn('stores', 'credit_version')) {
                    $table->unsignedInteger('credit_version')->default(0)->after('is_debt_locked');
                }
                if (! Schema::hasColumn('stores', 'debt_locked_at')) {
                    $table->timestamp('debt_locked_at')->nullable()->after('credit_version');
                }
                if (! Schema::hasColumn('stores', 'debt_lock_reason')) {
                    $table->string('debt_lock_reason', 120)->nullable()->after('debt_locked_at');
                }

                if (! $this->hasIndex('stores', 'idx_stores_debt_visibility')) {
                    $table->index(['status', 'is_open', 'is_debt_locked'], 'idx_stores_debt_visibility');
                }
                if (! $this->hasIndex('stores', 'idx_stores_credit_level')) {
                    $table->index('credit_level', 'idx_stores_credit_level');
                }
                if (! $this->hasIndex('stores', 'idx_stores_debt_locked')) {
                    $table->index(['is_debt_locked', 'debt_locked_at'], 'idx_stores_debt_locked');
                }
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (! Schema::hasColumn('orders', 'required_topup')) {
                    $table->decimal('required_topup', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('orders', 'ticket_fee_vnd')) {
                    $table->decimal('ticket_fee_vnd', 15, 2)->default(0)->after('required_topup');
                }
                if (! Schema::hasColumn('orders', 'credit_checked_at')) {
                    $table->timestamp('credit_checked_at')->nullable()->after('ticket_fee_vnd');
                }
                if (! Schema::hasColumn('orders', 'credit_snapshot_version')) {
                    $table->unsignedInteger('credit_snapshot_version')->default(0)->after('credit_checked_at');
                }
                if (! Schema::hasColumn('orders', 'credit_status')) {
                    $table->string('credit_status', 32)->default('unchecked')->after('credit_snapshot_version');
                }

                if (! $this->hasIndex('orders', 'idx_orders_store_status_credit')) {
                    $table->index(['store_id', 'status', 'required_topup', 'timeout_at'], 'idx_orders_store_status_credit');
                }
                if (! $this->hasIndex('orders', 'idx_orders_credit_status')) {
                    $table->index(['credit_status', 'credit_checked_at'], 'idx_orders_credit_status');
                }
            });
        }

        if (Schema::hasTable('store_ticket_transactions')) {
            Schema::table('store_ticket_transactions', function (Blueprint $table) {
                if (! Schema::hasColumn('store_ticket_transactions', 'amount_vnd')) {
                    $table->decimal('amount_vnd', 15, 2)->default(0)->after('total_debit_amount');
                }
                if (! Schema::hasColumn('store_ticket_transactions', 'wallet_delta')) {
                    $table->decimal('wallet_delta', 15, 2)->default(0)->after('amount_vnd');
                }
                if (! Schema::hasColumn('store_ticket_transactions', 'debt_delta')) {
                    $table->decimal('debt_delta', 15, 2)->default(0)->after('wallet_delta');
                }
                if (! Schema::hasColumn('store_ticket_transactions', 'wallet_balance_after')) {
                    $table->decimal('wallet_balance_after', 15, 2)->nullable()->after('debt_delta');
                }
                if (! Schema::hasColumn('store_ticket_transactions', 'ticket_debt_after')) {
                    $table->decimal('ticket_debt_after', 15, 2)->nullable()->after('wallet_balance_after');
                }
                if (! Schema::hasColumn('store_ticket_transactions', 'idempotency_key')) {
                    $table->string('idempotency_key', 160)->nullable()->after('ticket_debt_after');
                }
                if (! $this->hasIndex('store_ticket_transactions', 'uniq_store_ticket_idempotency_key')) {
                    $table->unique('idempotency_key', 'uniq_store_ticket_idempotency_key');
                }
                if (! Schema::hasColumn('store_ticket_transactions', 'reversal_of_transaction_id')) {
                    $table->uuid('reversal_of_transaction_id')->nullable()->after('idempotency_key');
                }
                if (! Schema::hasColumn('store_ticket_transactions', 'source_type')) {
                    $table->string('source_type', 80)->nullable()->after('reversal_of_transaction_id');
                }
                if (! Schema::hasColumn('store_ticket_transactions', 'source_id')) {
                    $table->string('source_id', 160)->nullable()->after('source_type');
                }
                if (! Schema::hasColumn('store_ticket_transactions', 'ticket_type_code')) {
                    $table->string('ticket_type_code', 50)->nullable()->after('ticket_value_snapshot');
                }
                if (! Schema::hasColumn('store_ticket_transactions', 'ticket_type_name')) {
                    $table->string('ticket_type_name', 120)->nullable()->after('ticket_type_code');
                }
                if (! Schema::hasColumn('store_ticket_transactions', 'ticket_quantity')) {
                    $table->unsignedInteger('ticket_quantity')->default(0)->after('ticket_type_name');
                }

                if (! $this->hasIndex('store_ticket_transactions', 'idx_store_ticket_source')) {
                    $table->index(['source_type', 'source_id'], 'idx_store_ticket_source');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('store_ticket_transactions')) {
            Schema::table('store_ticket_transactions', function (Blueprint $table) {
                if ($this->hasIndex('store_ticket_transactions', 'idx_store_ticket_source')) {
                    $table->dropIndex('idx_store_ticket_source');
                }
                if ($this->hasIndex('store_ticket_transactions', 'uniq_store_ticket_idempotency_key')) {
                    $table->dropUnique('uniq_store_ticket_idempotency_key');
                }
            });

            $columns = $this->existingColumns('store_ticket_transactions', [
                'amount_vnd',
                'wallet_delta',
                'debt_delta',
                'wallet_balance_after',
                'ticket_debt_after',
                'idempotency_key',
                'reversal_of_transaction_id',
                'source_type',
                'source_id',
                'ticket_type_code',
                'ticket_type_name',
                'ticket_quantity',
            ]);
            if ($columns !== []) {
                Schema::table('store_ticket_transactions', function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                });
            }
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if ($this->hasIndex('orders', 'idx_orders_store_status_credit')) {
                    $table->dropIndex('idx_orders_store_status_credit');
                }
                if ($this->hasIndex('orders', 'idx_orders_credit_status')) {
                    $table->dropIndex('idx_orders_credit_status');
                }
            });

            $columns = $this->existingColumns('orders', [
                'required_topup',
                'ticket_fee_vnd',
                'credit_checked_at',
                'credit_snapshot_version',
                'credit_status',
            ]);
            if ($columns !== []) {
                Schema::table('orders', function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                });
            }
        }

        if (Schema::hasTable('stores')) {
            Schema::table('stores', function (Blueprint $table) {
                if ($this->hasIndex('stores', 'idx_stores_debt_visibility')) {
                    $table->dropIndex('idx_stores_debt_visibility');
                }
                if ($this->hasIndex('stores', 'idx_stores_credit_level')) {
                    $table->dropIndex('idx_stores_credit_level');
                }
                if ($this->hasIndex('stores', 'idx_stores_debt_locked')) {
                    $table->dropIndex('idx_stores_debt_locked');
                }
            });

            $columns = $this->existingColumns('stores', [
                'credit_limit',
                'ticket_debt',
                'wallet_balance',
                'credit_level',
                'credit_score',
                'is_debt_locked',
                'credit_version',
                'debt_locked_at',
                'debt_lock_reason',
            ]);
            if ($columns !== []) {
                Schema::table('stores', function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                });
            }
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        try {
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';

            return DB::select("SHOW INDEX FROM {$quotedTable} WHERE Key_name = ?", [$index]) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function existingColumns(string $table, array $columns): array
    {
        return array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($table, $column)
        ));
    }
};
