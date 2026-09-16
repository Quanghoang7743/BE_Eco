<?php

namespace App\Console\Commands;

use App\Models\MoneyWalletAccount;
use App\Models\MoneyWalletLedgerEntry;
use App\Models\MoneyWalletWithdrawalRequest;
use App\Models\OrderReturn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReconcileMoneyWalletsCommand extends Command
{
    protected $signature = 'ecoluck:reconcile-money-wallets {--fail-on-drift}';

    protected $description = 'Check money wallet balances, refund ledgers, and store liability ledgers for drift.';

    public function handle(): int
    {
        if (! $this->schemaReady()) {
            $this->warn('Money wallet schema is not ready.');

            return self::SUCCESS;
        }

        $issues = array_merge(
            $this->walletBalanceIssues(),
            $this->withdrawalLockIssues(),
            $this->refundedReturnIssues(),
            $this->platformAdvanceIssues(),
            $this->duplicateRefundSourceIssues(),
            $this->duplicateIdempotencyIssues()
        );

        if ($issues === []) {
            $this->info('Money wallet reconciliation passed.');

            return self::SUCCESS;
        }

        foreach ($issues as $issue) {
            $this->error(json_encode($issue, JSON_UNESCAPED_UNICODE));
        }

        return $this->option('fail-on-drift') ? self::FAILURE : self::SUCCESS;
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('money_wallet_accounts')
            && Schema::hasTable('money_wallet_ledger_entries')
            && Schema::hasTable('money_wallet_withdrawal_requests')
            && Schema::hasTable('order_returns')
            && Schema::hasTable('store_liability_ledger_entries');
    }

    private function walletBalanceIssues(): array
    {
        $issues = [];

        MoneyWalletAccount::query()
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use (&$issues): void {
                foreach ($accounts as $account) {
                    $ledgers = MoneyWalletLedgerEntry::query()
                        ->where('wallet_account_id', (string) $account->id)
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->get();

                    if ($ledgers->isEmpty()) {
                        if ($this->money($account->available_balance) !== 0.0 || $this->money($account->locked_balance) !== 0.0) {
                            $issues[] = [
                                'type' => 'wallet_without_ledger_has_balance',
                                'wallet_account_id' => (string) $account->id,
                                'available_balance' => $this->money($account->available_balance),
                                'locked_balance' => $this->money($account->locked_balance),
                            ];
                        }

                        continue;
                    }

                    $expectedAvailable = 0.0;
                    foreach ($ledgers as $ledger) {
                        $expectedBefore = $expectedAvailable;
                        $expectedAfter = $this->expectedAvailableAfterLedger($expectedBefore, $ledger);
                        $actualBefore = $this->money($ledger->balance_before);
                        $actualAfter = $this->money($ledger->balance_after);

                        if ($actualBefore !== $expectedBefore || $actualAfter !== $expectedAfter) {
                            $issues[] = [
                                'type' => 'wallet_ledger_chain_drift',
                                'wallet_account_id' => (string) $account->id,
                                'ledger_id' => (string) $ledger->id,
                                'entry_type' => $ledger->entry_type,
                                'direction' => $ledger->direction,
                                'amount' => $this->money($ledger->amount),
                                'expected_balance_before' => $expectedBefore,
                                'actual_balance_before' => $actualBefore,
                                'expected_balance_after' => $expectedAfter,
                                'actual_balance_after' => $actualAfter,
                            ];
                        }

                        $expectedAvailable = $expectedAfter;
                    }

                    if ($this->money($account->available_balance) !== $expectedAvailable) {
                        $issues[] = [
                            'type' => 'wallet_available_balance_drift',
                            'wallet_account_id' => (string) $account->id,
                            'available_balance' => $this->money($account->available_balance),
                            'expected_available_balance' => $expectedAvailable,
                        ];
                    }
                }
            });

        return $issues;
    }

    private function withdrawalLockIssues(): array
    {
        $issues = [];

        MoneyWalletAccount::query()
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use (&$issues): void {
                foreach ($accounts as $account) {
                    $lockedByRequests = $this->money(MoneyWalletWithdrawalRequest::query()
                        ->where('wallet_account_id', (string) $account->id)
                        ->whereIn('status', ['pending', 'approved'])
                        ->sum('amount'));

                    if ($this->money($account->locked_balance) !== $lockedByRequests) {
                        $issues[] = [
                            'type' => 'wallet_locked_balance_drift',
                            'wallet_account_id' => (string) $account->id,
                            'locked_balance' => $this->money($account->locked_balance),
                            'pending_withdrawal_amount' => $lockedByRequests,
                        ];
                    }
                }
            });

        return $issues;
    }

    private function refundedReturnIssues(): array
    {
        return OrderReturn::query()
            ->where('status', OrderReturn::STATUS_REFUNDED)
            ->where(function ($query): void {
                $query->whereNull('customer_wallet_ledger_id')
                    ->orWhereDoesntHave('customerWalletLedger');
            })
            ->limit(200)
            ->get(['id', 'return_code', 'customer_wallet_ledger_id'])
            ->map(fn (OrderReturn $return): array => [
                'type' => 'refunded_return_missing_customer_ledger',
                'order_return_id' => (string) $return->id,
                'return_code' => $return->return_code,
                'customer_wallet_ledger_id' => $return->customer_wallet_ledger_id,
            ])
            ->all();
    }

    private function platformAdvanceIssues(): array
    {
        return OrderReturn::query()
            ->where('status', OrderReturn::STATUS_REFUNDED)
            ->where('platform_advanced_amount', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('store_liability_ledger_id')
                    ->orWhereDoesntHave('storeLiabilityLedger');
            })
            ->limit(200)
            ->get(['id', 'return_code', 'store_liability_ledger_id', 'platform_advanced_amount'])
            ->map(fn (OrderReturn $return): array => [
                'type' => 'platform_advance_missing_store_liability_ledger',
                'order_return_id' => (string) $return->id,
                'return_code' => $return->return_code,
                'store_liability_ledger_id' => $return->store_liability_ledger_id,
                'platform_advanced_amount' => $this->money($return->platform_advanced_amount),
            ])
            ->all();
    }

    private function duplicateIdempotencyIssues(): array
    {
        $issues = [];

        foreach (['money_wallet_ledger_entries', 'store_liability_ledger_entries'] as $table) {
            $duplicates = DB::table($table)
                ->select('idempotency_key', DB::raw('COUNT(*) as duplicate_count'))
                ->groupBy('idempotency_key')
                ->having('duplicate_count', '>', 1)
                ->limit(200)
                ->get();

            foreach ($duplicates as $duplicate) {
                $issues[] = [
                    'type' => 'duplicate_idempotency_key',
                    'table' => $table,
                    'idempotency_key' => $duplicate->idempotency_key,
                    'duplicate_count' => (int) $duplicate->duplicate_count,
                ];
            }
        }

        return $issues;
    }

    private function duplicateRefundSourceIssues(): array
    {
        return MoneyWalletLedgerEntry::query()
            ->select(
                'source_id',
                DB::raw('COUNT(*) as duplicate_count'),
                DB::raw('SUM(amount) as total_amount')
            )
            ->where('entry_type', 'refund_credit')
            ->where('source_type', 'order_return')
            ->whereNotNull('source_id')
            ->groupBy('source_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(200)
            ->get()
            ->map(fn ($duplicate): array => [
                'type' => 'duplicate_refund_source',
                'source_type' => 'order_return',
                'source_id' => (string) $duplicate->source_id,
                'duplicate_count' => (int) $duplicate->duplicate_count,
                'total_amount' => $this->money($duplicate->total_amount),
            ])
            ->all();
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function expectedAvailableAfterLedger(float $availableBefore, MoneyWalletLedgerEntry $ledger): float
    {
        $amount = $this->money($ledger->amount);
        if ((string) $ledger->entry_type === 'withdrawal_paid') {
            return $availableBefore;
        }

        return match ((string) $ledger->direction) {
            'credit' => $this->money($availableBefore + $amount),
            'debit' => $this->money($availableBefore - $amount),
            default => $availableBefore,
        };
    }
}
