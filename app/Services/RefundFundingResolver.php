<?php

namespace App\Services;

use App\Models\OrderReturn;
use App\Models\Store;
use App\Models\StoreLiabilityLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class RefundFundingResolver
{
    public function __construct(private readonly StoreLiabilityService $liabilityService)
    {
    }

    public function applyFundingDecision(OrderReturn $return, float $amount, mixed $actor = null): array
    {
        if (DB::connection()->transactionLevel() === 0) {
            return DB::transaction(fn (): array => $this->applyFundingDecision($return, $amount, $actor));
        }

        $amount = $this->money($amount);
        if ($amount <= 0) {
            throw new RuntimeException('Refund funding amount must be greater than zero.');
        }

        $existingEntries = StoreLiabilityLedgerEntry::query()
            ->where('source_type', 'order_return')
            ->where('source_id', (string) $return->id)
            ->get();

        if ($existingEntries->isNotEmpty()) {
            $walletDebit = (float) $existingEntries->where('funding_source', 'store_wallet_balance')->sum('amount');
            $pendingSettlementOffset = (float) $existingEntries->where('funding_source', 'pending_settlement_receivable')->sum('amount');
            $platformAdvance = (float) $existingEntries->where('funding_source', 'platform_advance')->sum('amount');

            $fundingSource = $platformAdvance > 0
                ? 'platform_advance'
                : ($pendingSettlementOffset > 0 ? 'pending_settlement_receivable' : 'store_wallet_balance');

            $lastEntry = $existingEntries->last();

            return [
                'funding_source' => $fundingSource,
                'wallet_debit' => $walletDebit,
                'pending_settlement_offset' => $pendingSettlementOffset,
                'platform_advance' => $platformAdvance,
                'liability_ledger_id' => $lastEntry ? (string) $lastEntry->id : null,
                'funding_breakdown' => [
                    'store_wallet_balance' => $walletDebit,
                    'pending_settlement_offset' => $pendingSettlementOffset,
                    'platform_advance' => $platformAdvance,
                    'pending_settlement_offsets' => [],
                    'liability_ledger_id' => $lastEntry ? (string) $lastEntry->id : null,
                ],
            ];
        }

        $store = Store::query()->whereKey((string) $return->store_id)->lockForUpdate()->first();
        if (! $store) {
            throw new RuntimeException('Store not found for refund funding.');
        }

        $walletBalance = $this->money($store->wallet_balance ?? 0);
        $walletDebit = min($walletBalance, $amount);
        $remainingAfterWallet = $this->money($amount - $walletDebit);
        $pendingSettlementOffsets = $this->applyPendingSettlementOffset((string) $store->id, $remainingAfterWallet, $return, $actor);
        $pendingSettlementOffset = $this->money(collect($pendingSettlementOffsets)->sum('amount'));
        $platformAdvance = $this->money($amount - $walletDebit - $pendingSettlementOffset);

        $storeUpdates = [];
        if ($walletDebit > 0 && Schema::hasColumn('stores', 'wallet_balance')) {
            $storeUpdates['wallet_balance'] = $this->money($walletBalance - $walletDebit);
        }

        if ($platformAdvance > 0 && Schema::hasColumn('stores', 'ticket_debt')) {
            $newDebt = $this->money(($store->ticket_debt ?? 0) + $platformAdvance);
            $storeUpdates['ticket_debt'] = $newDebt;
            $limit = (float) ($store->credit_limit ?? 200000.0);
            
            if ($newDebt > $limit) {
                if (Schema::hasColumn('stores', 'is_debt_locked')) {
                    $storeUpdates['is_debt_locked'] = true;
                }
                if (Schema::hasColumn('stores', 'debt_locked_at')) {
                    $storeUpdates['debt_locked_at'] = now();
                }
                if (Schema::hasColumn('stores', 'debt_lock_reason')) {
                    $storeUpdates['debt_lock_reason'] = 'return_platform_advance';
                }
            }
        }

        if ($storeUpdates !== []) {
            if (Schema::hasColumn('stores', 'credit_version')) {
                $storeUpdates['credit_version'] = ((int) ($store->credit_version ?? 0)) + 1;
            }
            $store->forceFill($storeUpdates)->save();
        }

        $liability = null;
        if ($walletDebit > 0) {
            $liability = $this->liabilityService->record(
                (string) $store->id,
                'wallet_debit',
                $walletDebit,
                'store_wallet_balance',
                'order_return',
                (string) $return->id,
                'refund:order_return:' . (string) $return->id . ':store_wallet_balance',
                $actor,
                ['return_code' => $return->return_code]
            );
        }

        if ($pendingSettlementOffset > 0) {
            $liability = $this->liabilityService->record(
                (string) $store->id,
                'wallet_debit',
                $pendingSettlementOffset,
                'pending_settlement_receivable',
                'order_return',
                (string) $return->id,
                'refund:order_return:' . (string) $return->id . ':pending_settlement',
                $actor,
                ['return_code' => $return->return_code]
            );
        }

        if ($platformAdvance > 0) {
            $liability = $this->liabilityService->record(
                (string) $store->id,
                'debt_increase',
                $platformAdvance,
                'platform_advance',
                'order_return',
                (string) $return->id,
                'refund:order_return:' . (string) $return->id . ':store_liability',
                $actor,
                ['return_code' => $return->return_code]
            );
        }

        $fundingSource = $platformAdvance > 0
            ? 'platform_advance'
            : ($pendingSettlementOffset > 0 ? 'pending_settlement_receivable' : 'store_wallet_balance');

        return [
            'funding_source' => $fundingSource,
            'wallet_debit' => $walletDebit,
            'pending_settlement_offset' => $pendingSettlementOffset,
            'platform_advance' => $platformAdvance,
            'liability_ledger_id' => $liability instanceof StoreLiabilityLedgerEntry ? (string) $liability->id : null,
            'funding_breakdown' => [
                'store_wallet_balance' => $walletDebit,
                'pending_settlement_offset' => $pendingSettlementOffset,
                'platform_advance' => $platformAdvance,
                'pending_settlement_offsets' => $pendingSettlementOffsets,
                'liability_ledger_id' => $liability instanceof StoreLiabilityLedgerEntry ? (string) $liability->id : null,
            ],
        ];
    }

    private function applyPendingSettlementOffset(string $storeId, float $amount, OrderReturn $return, mixed $actor = null): array
    {
        if (
            $amount <= 0
            || ! Schema::hasTable('financial_reconciliations')
            || ! Schema::hasColumn('financial_reconciliations', 'net_amount')
            || ! Schema::hasColumn('financial_reconciliations', 'status')
        ) {
            return [];
        }

        $remaining = $this->money($amount);
        $offsets = [];
        $rows = DB::table('financial_reconciliations')
            ->where('store_id', $storeId)
            ->when(Schema::hasColumn('financial_reconciliations', 'paid_at'), fn ($query) => $query->whereNull('paid_at'))
            ->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), $this->pendingSettlementStatuses())
            ->where('net_amount', '>', 0)
            ->orderBy('period_date')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get(['id', 'net_amount']);

        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }

            $current = $this->money($row->net_amount ?? 0);
            $debit = min($current, $remaining);
            DB::table('financial_reconciliations')
                ->where('id', $row->id)
                ->update([
                    'net_amount' => $this->money($current - $debit),
                    'updated_at' => now(),
                ]);
            $remaining = $this->money($remaining - $debit);
            $this->recordPendingSettlementOffset((string) $row->id, $storeId, $return, $debit, $actor);
            $offsets[] = [
                'financial_reconciliation_id' => (string) $row->id,
                'amount' => $debit,
            ];
        }

        return $offsets;
    }

    private function recordPendingSettlementOffset(string $reconciliationId, string $storeId, OrderReturn $return, float $amount, mixed $actor = null): void
    {
        if (
            $amount <= 0
            || ! Schema::hasTable('financial_reconciliation_offsets')
            || ! Schema::hasColumn('financial_reconciliation_offsets', 'idempotency_key')
        ) {
            return;
        }

        $idempotencyKey = 'refund:order_return:' . (string) $return->id . ':pending_settlement:' . $reconciliationId;
        if (DB::table('financial_reconciliation_offsets')->where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        $payload = [
            'id' => (string) Str::uuid(),
            'financial_reconciliation_id' => $reconciliationId,
            'store_id' => $storeId,
            'order_return_id' => (string) $return->id,
            'amount' => $this->money($amount),
            'source_type' => 'order_return',
            'source_id' => (string) $return->id,
            'idempotency_key' => $idempotencyKey,
            'metadata' => json_encode([
                'return_code' => $return->return_code,
                'created_by' => $this->actorId($actor),
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $columns = array_keys($payload);
        foreach ($columns as $column) {
            if (! Schema::hasColumn('financial_reconciliation_offsets', $column)) {
                unset($payload[$column]);
            }
        }

        if ($payload !== []) {
            DB::table('financial_reconciliation_offsets')->insert($payload);
        }
    }

    private function pendingSettlementStatuses(): array
    {
        return [
            'pending_shop',
            'waiting_payment',
            'pending',
            'pending_settlement',
            'draft',
            'confirmed',
        ];
    }

    private function money(mixed $value): float
    {
        return round(max(0.0, (float) $value), 2);
    }

    private function actorId(mixed $actor): ?string
    {
        if (is_object($actor) && isset($actor->id)) {
            return (string) $actor->id;
        }

        if (is_scalar($actor) && trim((string) $actor) !== '') {
            return (string) $actor;
        }

        return null;
    }
}
