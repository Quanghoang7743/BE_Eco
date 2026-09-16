<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\StoreCreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpgradeStoreCreditLevelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $storeId)
    {
    }

    public function handle(StoreCreditService $storeCreditService): void
    {
        if (
            ! Schema::hasTable('stores')
            || ! Schema::hasColumn('stores', 'credit_level')
            || ! Schema::hasColumn('stores', 'credit_limit')
            || ! Schema::hasTable('orders')
        ) {
            return;
        }

        DB::transaction(function () use ($storeCreditService): void {
            $store = Store::query()->whereKey($this->storeId)->lockForUpdate()->first();
            if (! $store) {
                return;
            }

            $baseQuery = DB::table('orders')->where('store_id', $this->storeId);
            $deliveredCount = (clone $baseQuery)
                ->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), ['delivered', 'completed'])
                ->count();
            $cancelledCount = (clone $baseQuery)
                ->whereIn(DB::raw('LOWER(COALESCE(status, ""))'), ['canceled', 'cancelled', 'timeout'])
                ->count();
            $totalTerminal = $deliveredCount + $cancelledCount;
            $cancelRate = $totalTerminal > 0 ? $cancelledCount / $totalTerminal : 0.0;

            $currentLevel = max(1, (int) ($store->credit_level ?? 1));
            $targetLevel = $storeCreditService->resolveCreditLevel($deliveredCount, $cancelRate, $currentLevel);

            if ($targetLevel <= $currentLevel) {
                return;
            }

            Store::query()->whereKey($this->storeId)->update([
                'credit_level' => $targetLevel,
                'credit_limit' => max(
                    (float) ($store->credit_limit ?? 0),
                    $storeCreditService->creditLimitForLevel($targetLevel)
                ),
                'credit_score' => max(0, min(100, (int) round(100 - ($cancelRate * 100)))),
                'credit_version' => ((int) ($store->credit_version ?? 0)) + 1,
                'updated_at' => now(),
            ]);
        });
    }
}
