<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\AffiliateCommissionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SyncAffiliateCommissionsCommand extends Command
{
    protected $signature = 'ecoluck:sync-affiliate-commissions {--order-id=} {--limit=500}';

    protected $description = 'Đồng bộ source-of-truth hoa hồng Affiliate từ dữ liệu đơn hàng.';

    public function handle(AffiliateCommissionService $commissionService): int
    {
        if (! $commissionService->hasCommissionTable()) {
            $this->warn('Chưa có bảng affiliate_commissions.');
            return self::FAILURE;
        }

        $orderId = $this->option('order-id');
        if ($orderId) {
            $order = Order::query()->with(['items.product', 'items.variant'])->find($orderId);
            if (! $order) {
                $this->error('Không tìm thấy đơn hàng: ' . $orderId);
                return self::FAILURE;
            }

            $commissionService->syncOrderCommissions($order);
            $commissionService->syncOrderLifecycle($order);
            $this->info('Đã đồng bộ commission cho đơn hàng ' . $orderId);
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $processed = 0;

        Order::query()
            ->with(['items.product', 'items.variant'])
            ->where(function (Builder $query): void {
                $query->whereNotNull('referrer_id');
            })
            ->orWhere(function (Builder $query): void {
                $query->whereNotNull('user_id');
            })
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->each(function (Order $order) use ($commissionService, &$processed): void {
                $commissionService->syncOrderCommissions($order);
                $commissionService->syncOrderLifecycle($order);
                $processed++;
            });

        $this->info('Đã đồng bộ ' . $processed . ' đơn hàng.');
        return self::SUCCESS;
    }
}
