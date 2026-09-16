<?php

namespace App\Console\Commands;

use App\Models\AffiliateCommission;
use App\Services\AffiliateCommissionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PreviewAffiliatePayoutCandidatesCommand extends Command
{
    protected $signature = 'ecoluck:preview-affiliate-payout-candidates {from?} {to?}';

    protected $description = 'Xem trước danh sách Affiliate đủ điều kiện vào kỳ thanh toán.';

    public function handle(AffiliateCommissionService $commissionService): int
    {
        if (! $commissionService->hasCommissionTable()) {
            $this->warn('Chưa có bảng affiliate_commissions.');
            return self::FAILURE;
        }

        $from = $this->argument('from')
            ? Carbon::parse((string) $this->argument('from'))->startOfDay()
            : now()->startOfMonth();
        $to = $this->argument('to')
            ? Carbon::parse((string) $this->argument('to'))->endOfDay()
            : now()->endOfMonth();

        $rows = AffiliateCommission::query()
            ->selectRaw('affiliate_id, COUNT(*) as commission_count, SUM(COALESCE(approved_amount, pending_amount, 0)) as gross_amount')
            ->where('status', AffiliateCommission::STATUS_APPROVED)
            ->whereNull('payout_batch_id')
            ->whereBetween(DB::raw('COALESCE(approved_at, eligible_at, created_at)'), [$from, $to])
            ->groupBy('affiliate_id')
            ->orderByDesc('gross_amount')
            ->get()
            ->filter(fn ($row) => (float) $row->gross_amount >= $commissionService->getMinimumPayoutAmount())
            ->values();

        if ($rows->isEmpty()) {
            $this->warn('Không có Affiliate nào đạt điều kiện trong kỳ này.');
            return self::SUCCESS;
        }

        $this->table(
            ['Affiliate ID', 'Commission count', 'Gross amount'],
            $rows->map(fn ($row) => [
                $row->affiliate_id,
                (int) $row->commission_count,
                number_format((float) $row->gross_amount, 0, ',', '.'),
            ])->all(),
        );

        $this->info('Tổng Affiliate đủ điều kiện: ' . $rows->count());
        return self::SUCCESS;
    }
}
