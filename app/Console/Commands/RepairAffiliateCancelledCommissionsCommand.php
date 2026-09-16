<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\AffiliateCommissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RepairAffiliateCancelledCommissionsCommand extends Command
{
    protected $signature = 'ecoluck:repair-affiliate-cancelled-commissions {--apply : Apply fixes instead of dry-run} {--limit=200 : Maximum orders to inspect}';

    protected $description = 'Cancel affiliate commissions and reverse legacy balances for cancelled/refunded orders.';

    public function handle(AffiliateCommissionService $commissionService): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));
        $orderIds = $this->candidateOrderIds($limit);

        if ($orderIds->isEmpty()) {
            $this->info('No cancelled/refunded affiliate orders found.');
            return self::SUCCESS;
        }

        $this->info($apply ? 'Applying affiliate commission repair...' : 'Dry-run only. Re-run with --apply to update data.');

        $rows = [];
        $totalLegacyReverse = 0.0;
        $totalLegacyPaidCount = 0;
        $totalAffiliateOpenCount = 0;

        foreach ($orderIds as $orderId) {
            $order = Order::query()->with(['items.product', 'items.variant'])->find($orderId);
            if (!$order) {
                continue;
            }

            $before = $this->summarizeOrder($order);
            $result = [
                'reversed_count' => 0,
                'reversed_amount' => 0.0,
                'cancelled_count' => 0,
            ];

            if ($apply) {
                DB::transaction(function () use ($commissionService, $order, &$result): void {
                    $lockedOrder = Order::query()
                        ->with(['items.product', 'items.variant'])
                        ->whereKey($order->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (!$lockedOrder) {
                        return;
                    }

                    $reason = 'Repair cancelled/refunded affiliate commission drift.';
                    $result = $commissionService->reverseLegacyPaidCommissions($lockedOrder, $reason);
                    $commissionService->syncOrderLifecycle($lockedOrder, $reason);
                });
            }

            $totalLegacyReverse += $apply ? (float) ($result['reversed_amount'] ?? 0) : (float) $before['legacy_paid_amount'];
            $totalLegacyPaidCount += (int) $before['legacy_paid_count'];
            $totalAffiliateOpenCount += (int) $before['affiliate_open_count'];

            $rows[] = [
                'order_id' => (string) $order->id,
                'status' => (string) $order->status,
                'payment' => (string) $order->status_payment,
                'open_affiliate' => (int) $before['affiliate_open_count'],
                'legacy_paid' => (int) $before['legacy_paid_count'],
                'legacy_amount' => number_format((float) $before['legacy_paid_amount'], 2, '.', ''),
                'applied_reverse' => number_format((float) ($result['reversed_amount'] ?? 0), 2, '.', ''),
            ];
        }

        $this->table([
            'order_id',
            'status',
            'payment',
            'open_affiliate',
            'legacy_paid',
            'legacy_amount',
            'applied_reverse',
        ], $rows);

        $this->info(sprintf(
            'Summary: orders=%d, open_affiliate=%d, legacy_paid=%d, %s=%.2f',
            count($rows),
            $totalAffiliateOpenCount,
            $totalLegacyPaidCount,
            $apply ? 'reversed' : 'would_reverse',
            $totalLegacyReverse,
        ));

        return self::SUCCESS;
    }

    private function candidateOrderIds(int $limit): Collection
    {
        $ids = collect();

        if (Schema::hasTable('affiliate_commissions') && Schema::hasTable('orders')) {
            $ids = $ids->merge(
                DB::table('affiliate_commissions')
                    ->join('orders', 'orders.id', '=', 'affiliate_commissions.order_id')
                    ->where(function ($query): void {
                        $this->cancelledOrderWhere($query);
                    })
                    ->whereNotIn('affiliate_commissions.status', ['CANCELLED', 'CLAWBACK'])
                    ->limit($limit)
                    ->pluck('orders.id'),
            );
        }

        if (Schema::hasTable('commission_users') && Schema::hasTable('order_items') && Schema::hasTable('orders')) {
            $ids = $ids->merge(
                DB::table('commission_users')
                    ->join('order_items', 'order_items.id', '=', 'commission_users.order_item_id')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where(function ($query): void {
                        $this->cancelledOrderWhere($query);
                    })
                    ->whereIn('commission_users.status', ['pending', 'paid'])
                    ->limit($limit)
                    ->pluck('orders.id'),
            );
        }

        return $ids
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->take($limit)
            ->values();
    }

    private function summarizeOrder(Order $order): array
    {
        $itemIds = $order->items->pluck('id')->map(fn ($id) => (string) $id)->all();

        $affiliateOpenCount = 0;
        if (Schema::hasTable('affiliate_commissions')) {
            $affiliateOpenCount = DB::table('affiliate_commissions')
                ->where('order_id', (string) $order->id)
                ->whereNotIn('status', ['CANCELLED', 'CLAWBACK'])
                ->count();
        }

        $legacyPaidCount = 0;
        $legacyPaidAmount = 0.0;
        if (!empty($itemIds) && Schema::hasTable('commission_users')) {
            $legacyPaidQuery = DB::table('commission_users')
                ->whereIn('order_item_id', $itemIds)
                ->where('status', 'paid');

            $legacyPaidCount = (int) (clone $legacyPaidQuery)->count();
            $legacyPaidAmount = (float) (clone $legacyPaidQuery)->sum('amount');
        }

        return [
            'affiliate_open_count' => $affiliateOpenCount,
            'legacy_paid_count' => $legacyPaidCount,
            'legacy_paid_amount' => $legacyPaidAmount,
        ];
    }

    private function cancelledOrderWhere($query): void
    {
        $query
            ->whereIn(DB::raw("LOWER(COALESCE(orders.status, ''))"), ['cancelled', 'canceled', 'returned', 'refunded'])
            ->orWhereIn(DB::raw("LOWER(COALESCE(orders.status_payment, ''))"), ['refunded', 'chargeback'])
            ->orWhereNotNull('orders.returned_at')
            ->orWhereNotNull('orders.cancelled_at');
    }
}
