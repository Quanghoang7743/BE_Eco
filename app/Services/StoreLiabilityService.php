<?php

namespace App\Services;

use App\Models\StoreLiabilityLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class StoreLiabilityService
{
    public function record(
        string $storeId,
        string $direction,
        float $amount,
        string $fundingSource,
        string $sourceType,
        string $sourceId,
        string $idempotencyKey,
        mixed $actor = null,
        array $metadata = []
    ): StoreLiabilityLedgerEntry {
        return DB::transaction(function () use ($storeId, $direction, $amount, $fundingSource, $sourceType, $sourceId, $idempotencyKey, $actor, $metadata): StoreLiabilityLedgerEntry {
            $existing = StoreLiabilityLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }

            $amount = $this->money($amount);
            if ($amount <= 0) {
                throw new RuntimeException('Store liability amount must be greater than zero.');
            }

            $currentDebt = $this->currentDebt($storeId);
            $nextDebt = match ($direction) {
                'debt_increase' => $this->money($currentDebt + $amount),
                'debt_decrease' => $this->money(max(0, $currentDebt - $amount)),
                default => $currentDebt,
            };

            return StoreLiabilityLedgerEntry::query()->create([
                'id' => (string) Str::uuid(),
                'store_id' => $storeId,
                'direction' => $direction,
                'amount' => $amount,
                'balance_before' => $currentDebt,
                'balance_after' => $nextDebt,
                'entry_type' => $this->entryTypeFor($direction, $fundingSource),
                'funding_source' => $fundingSource,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $this->actorId($actor),
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        });
    }

    private function currentDebt(string $storeId): float
    {
        $increase = (float) DB::table('store_liability_ledger_entries')
            ->where('store_id', $storeId)
            ->where('direction', 'debt_increase')
            ->sum('amount');
        $decrease = (float) DB::table('store_liability_ledger_entries')
            ->where('store_id', $storeId)
            ->where('direction', 'debt_decrease')
            ->sum('amount');

        return $this->money(max(0, $increase - $decrease));
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

    private function money(mixed $value): float
    {
        return round(max(0.0, (float) $value), 2);
    }

    private function entryTypeFor(string $direction, string $fundingSource): string
    {
        if ($direction === 'debt_decrease') {
            return $fundingSource === 'manual_collection' ? 'manual_collection' : 'future_revenue_offset';
        }

        return 'customer_refund';
    }
}
