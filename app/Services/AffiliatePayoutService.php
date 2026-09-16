<?php

namespace App\Services;

use App\Models\AffiliateCommission;
use App\Models\AffiliatePayoutBatch;
use App\Models\AffiliatePayoutBatchItem;
use App\Models\BankAccount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AffiliatePayoutService
{
    public function __construct(
        private readonly AffiliateCommissionService $commissionService,
    ) {
    }

    public function paginateBatches(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->buildBatchQuery($filters)
            ->paginate($perPage)
            ->through(fn (AffiliatePayoutBatch $batch) => $this->formatBatch($batch));
    }

    public function getBatch(int $batchId): array
    {
        $batch = AffiliatePayoutBatch::query()
            ->with(['items.affiliate', 'items.bankAccount.bank', 'commissions'])
            ->findOrFail($batchId);

        return $this->formatBatch($batch, true);
    }

    public function createBatch(array $payload, ?User $actor = null): array
    {
        $from = Carbon::parse((string) $payload['payout_period_from'])->startOfDay();
        $to = Carbon::parse((string) $payload['payout_period_to'])->endOfDay();
        $note = trim((string) ($payload['note'] ?? ''));

        $minimumPayout = $this->commissionService->getMinimumPayoutAmount();
        $taxRate = $this->commissionService->getTaxWithholdingRate();

        $candidateCommissions = AffiliateCommission::query()
            ->where('status', AffiliateCommission::STATUS_APPROVED)
            ->whereNull('payout_batch_id')
            ->whereBetween(DB::raw('COALESCE(approved_at, eligible_at, created_at)'), [$from, $to])
            ->orderBy('affiliate_id')
            ->get();

        if ($candidateCommissions->isEmpty()) {
            throw ValidationException::withMessages([
                'batch' => 'Không có hoa hồng APPROVED phù hợp để tạo kỳ chi trả.',
            ]);
        }

        $grouped = $candidateCommissions->groupBy('affiliate_id');
        $affiliateIds = $grouped->keys()
            ->map(static fn ($affiliateId): int => (int) $affiliateId)
            ->filter(static fn (int $affiliateId): bool => $affiliateId > 0)
            ->values()
            ->all();
        $this->commissionService->assertAffiliatesEligibleForPayout($affiliateIds);
        $bankAccountsByUserId = BankAccount::query()
            ->whereIn('user_id', $affiliateIds)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->unique('user_id')
            ->keyBy(static fn (BankAccount $bankAccount): int => (int) $bankAccount->user_id);
        $eligibleGroups = collect();

        foreach ($grouped as $affiliateId => $commissions) {
            $gross = (float) $commissions->sum(fn (AffiliateCommission $commission) => (float) ($commission->approved_amount > 0 ? $commission->approved_amount : $commission->pending_amount));
            if ($gross < $minimumPayout) {
                continue;
            }

            $tax = round($gross * ($taxRate / 100), 2);
            $net = round($gross - $tax, 2);
            $bankAccount = $bankAccountsByUserId->get((int) $affiliateId);

            $eligibleGroups->push([
                'affiliate_id' => (int) $affiliateId,
                'commissions' => $commissions,
                'commission_count' => $commissions->count(),
                'gross_amount' => round($gross, 2),
                'tax_withholding_amount' => $tax,
                'net_payout_amount' => $net,
                'bank_account_id' => $bankAccount?->id,
            ]);
        }

        if ($eligibleGroups->isEmpty()) {
            throw ValidationException::withMessages([
                'batch' => 'Không có Affiliate nào đạt điều kiện vào kỳ thanh toán.',
            ]);
        }

        $batchCode = 'APB-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));

        return DB::transaction(function () use ($eligibleGroups, $from, $to, $note, $actor, $batchCode) {
            $batch = AffiliatePayoutBatch::query()->create([
                'batch_code' => $batchCode,
                'payout_period_from' => $from->toDateString(),
                'payout_period_to' => $to->toDateString(),
                'total_affiliates' => $eligibleGroups->count(),
                'total_commission' => round((float) $eligibleGroups->sum('gross_amount'), 2),
                'total_tax_withholding' => round((float) $eligibleGroups->sum('tax_withholding_amount'), 2),
                'total_payout' => round((float) $eligibleGroups->sum('net_payout_amount'), 2),
                'status' => AffiliatePayoutBatch::STATUS_DRAFT,
                'created_by' => $actor?->id,
                'note' => $note !== '' ? $note : null,
            ]);

            foreach ($eligibleGroups as $group) {
                AffiliatePayoutBatchItem::query()->create([
                    'batch_id' => $batch->id,
                    'affiliate_id' => $group['affiliate_id'],
                    'commission_count' => $group['commission_count'],
                    'gross_amount' => $group['gross_amount'],
                    'tax_withholding_amount' => $group['tax_withholding_amount'],
                    'net_payout_amount' => $group['net_payout_amount'],
                    'bank_account_id' => $group['bank_account_id'],
                    'status' => AffiliatePayoutBatch::STATUS_DRAFT,
                ]);

                AffiliateCommission::query()
                    ->whereIn('id', $group['commissions']->pluck('id')->all())
                    ->update([
                        'payout_batch_id' => $batch->id,
                    ]);
            }

            return $this->getBatch((int) $batch->id);
        });
    }

    public function submitBatch(int $batchId): array
    {
        $batch = AffiliatePayoutBatch::query()->findOrFail($batchId);
        if ($batch->status !== AffiliatePayoutBatch::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Chỉ batch DRAFT mới được gửi duyệt.',
            ]);
        }

        $batch->status = AffiliatePayoutBatch::STATUS_WAITING_APPROVAL;
        $batch->save();

        AffiliatePayoutBatchItem::query()->where('batch_id', $batch->id)->update(['status' => AffiliatePayoutBatch::STATUS_WAITING_APPROVAL]);

        return $this->getBatch($batchId);
    }

    public function approveBatch(int $batchId, ?User $actor = null): array
    {
        $batch = AffiliatePayoutBatch::query()->findOrFail($batchId);
        if (!in_array($batch->status, [AffiliatePayoutBatch::STATUS_DRAFT, AffiliatePayoutBatch::STATUS_WAITING_APPROVAL], true)) {
            throw ValidationException::withMessages([
                'status' => 'Batch hiện không ở trạng thái có thể duyệt.',
            ]);
        }

        DB::transaction(function () use ($batch, $actor): void {
            $batch->status = AffiliatePayoutBatch::STATUS_APPROVED;
            $batch->approved_by = $actor?->id;
            $batch->approved_at = now();
            $batch->save();

            AffiliatePayoutBatchItem::query()->where('batch_id', $batch->id)->update(['status' => AffiliatePayoutBatch::STATUS_APPROVED]);

            AffiliateCommission::query()
                ->where('payout_batch_id', $batch->id)
                ->where('status', AffiliateCommission::STATUS_APPROVED)
                ->update(['status' => AffiliateCommission::STATUS_PAYOUT_PENDING]);
        });

        return $this->getBatch($batchId);
    }

    public function markBatchPaid(int $batchId, array $payload, ?User $actor = null): array
    {
        $batch = AffiliatePayoutBatch::query()->with('items')->findOrFail($batchId);
        if ($batch->status !== AffiliatePayoutBatch::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => 'Chỉ batch APPROVED mới được đánh dấu đã thanh toán.',
            ]);
        }

        $paymentReference = trim((string) ($payload['payment_reference'] ?? ''));
        $paymentProofUrl = trim((string) ($payload['payment_proof_url'] ?? ''));

        DB::transaction(function () use ($batch, $actor, $paymentReference, $paymentProofUrl): void {
            $paidAt = now();

            $batch->status = AffiliatePayoutBatch::STATUS_PAID;
            $batch->paid_by = $actor?->id;
            $batch->paid_at = $paidAt;
            $batch->save();

            AffiliatePayoutBatchItem::query()
                ->where('batch_id', $batch->id)
                ->update([
                    'status' => AffiliatePayoutBatch::STATUS_PAID,
                    'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
                    'payment_proof_url' => $paymentProofUrl !== '' ? $paymentProofUrl : null,
                    'paid_at' => $paidAt,
                ]);

            $commissions = AffiliateCommission::query()
                ->where('payout_batch_id', $batch->id)
                ->whereIn('status', [AffiliateCommission::STATUS_APPROVED, AffiliateCommission::STATUS_PAYOUT_PENDING])
                ->get();

            foreach ($commissions as $commission) {
                $gross = (float) ($commission->approved_amount > 0 ? $commission->approved_amount : $commission->pending_amount);
                $taxRate = $this->commissionService->getTaxWithholdingRate();
                $taxAmount = round($gross * ($taxRate / 100), 2);

                $commission->status = AffiliateCommission::STATUS_PAID;
                $commission->tax_withholding_amount = $taxAmount;
                $commission->payout_amount = round($gross - $taxAmount, 2);
                $commission->paid_at = $paidAt;
                $commission->save();
            }
        });

        return $this->getBatch($batchId);
    }

    public function cancelBatch(int $batchId, string $reason = ''): array
    {
        $batch = AffiliatePayoutBatch::query()->findOrFail($batchId);
        if ($batch->status === AffiliatePayoutBatch::STATUS_PAID) {
            throw ValidationException::withMessages([
                'status' => 'Không thể hủy batch đã thanh toán.',
            ]);
        }

        DB::transaction(function () use ($batch, $reason): void {
            $batch->status = AffiliatePayoutBatch::STATUS_CANCELLED;
            if (trim($reason) !== '') {
                $batch->note = trim($batch->note . "\n" . 'Cancel reason: ' . trim($reason));
            }
            $batch->save();

            AffiliatePayoutBatchItem::query()->where('batch_id', $batch->id)->update(['status' => AffiliatePayoutBatch::STATUS_CANCELLED]);

            AffiliateCommission::query()
                ->where('payout_batch_id', $batch->id)
                ->whereIn('status', [AffiliateCommission::STATUS_APPROVED, AffiliateCommission::STATUS_PAYOUT_PENDING])
                ->update([
                    'status' => AffiliateCommission::STATUS_APPROVED,
                    'payout_batch_id' => null,
                ]);
        });

        return $this->getBatch($batchId);
    }

    public function paginateAffiliatePayouts(int $affiliateId, int $page = 1, int $perPage = 20): array
    {
        $query = AffiliatePayoutBatchItem::query()
            ->with(['batch', 'bankAccount.bank'])
            ->where('affiliate_id', $affiliateId)
            ->orderByDesc('created_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => collect($paginator->items())->map(fn (AffiliatePayoutBatchItem $item) => $this->formatPayoutItem($item))->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    public function getAffiliatePayoutDetail(int $affiliateId, int $batchId): array
    {
        $item = AffiliatePayoutBatchItem::query()
            ->with(['batch', 'bankAccount.bank'])
            ->where('affiliate_id', $affiliateId)
            ->where('batch_id', $batchId)
            ->firstOrFail();

        return $this->formatPayoutItem($item, true);
    }

    private function buildBatchQuery(array $filters): Builder
    {
        return AffiliatePayoutBatch::query()
            ->with(['items'])
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', strtoupper((string) $filters['status'])))
            ->when(!empty($filters['from']), fn (Builder $q) => $q->whereDate('payout_period_from', '>=', Carbon::parse((string) $filters['from'])->toDateString()))
            ->when(!empty($filters['to']), fn (Builder $q) => $q->whereDate('payout_period_to', '<=', Carbon::parse((string) $filters['to'])->toDateString()))
            ->orderByDesc('payout_period_to')
            ->orderByDesc('id');
    }

    private function formatBatch(AffiliatePayoutBatch $batch, bool $includeItems = false): array
    {
        return [
            'id' => (int) $batch->id,
            'batch_code' => $batch->batch_code,
            'payout_period_from' => optional($batch->payout_period_from)->toDateString(),
            'payout_period_to' => optional($batch->payout_period_to)->toDateString(),
            'total_affiliates' => (int) $batch->total_affiliates,
            'total_commission' => (float) $batch->total_commission,
            'total_tax_withholding' => (float) $batch->total_tax_withholding,
            'total_payout' => (float) $batch->total_payout,
            'status' => $batch->status,
            'created_by' => $batch->created_by,
            'approved_by' => $batch->approved_by,
            'paid_by' => $batch->paid_by,
            'approved_at' => optional($batch->approved_at)->toIso8601String(),
            'paid_at' => optional($batch->paid_at)->toIso8601String(),
            'note' => $batch->note,
            'items' => $includeItems
                ? $batch->items->map(fn (AffiliatePayoutBatchItem $item) => $this->formatPayoutItem($item, true))->values()->all()
                : [],
        ];
    }

    private function formatPayoutItem(AffiliatePayoutBatchItem $item, bool $includeBatch = false): array
    {
        return [
            'id' => (int) $item->id,
            'batch_id' => (int) $item->batch_id,
            'batch_code' => $item->batch?->batch_code,
            'affiliate_id' => (int) $item->affiliate_id,
            'commission_count' => (int) $item->commission_count,
            'gross_amount' => (float) $item->gross_amount,
            'tax_withholding_amount' => (float) $item->tax_withholding_amount,
            'net_payout_amount' => (float) $item->net_payout_amount,
            'bank_account_id' => $item->bank_account_id ? (int) $item->bank_account_id : null,
            'status' => $item->status,
            'payment_reference' => $item->payment_reference,
            'payment_proof_url' => $item->payment_proof_url,
            'paid_at' => optional($item->paid_at)->toIso8601String(),
            'created_at' => optional($item->created_at)->toIso8601String(),
            'updated_at' => optional($item->updated_at)->toIso8601String(),
            'bank_account' => $item->bankAccount ? [
                'id' => (int) $item->bankAccount->id,
                'user_id' => (int) $item->bankAccount->user_id,
                'bank_id' => (int) $item->bankAccount->bank_id,
                'account_number' => (string) $item->bankAccount->account_number,
                'account_name' => (string) $item->bankAccount->account_name,
                'bank' => $item->bankAccount->bank ? [
                    'id' => (int) $item->bankAccount->bank->id,
                    'name' => (string) $item->bankAccount->bank->name,
                ] : null,
            ] : null,
            'batch' => $includeBatch && $item->batch ? [
                'id' => (int) $item->batch->id,
                'batch_code' => $item->batch->batch_code,
                'payout_period_from' => optional($item->batch->payout_period_from)->toDateString(),
                'payout_period_to' => optional($item->batch->payout_period_to)->toDateString(),
                'status' => $item->batch->status,
                'approved_at' => optional($item->batch->approved_at)->toIso8601String(),
                'paid_at' => optional($item->batch->paid_at)->toIso8601String(),
            ] : null,
        ];
    }
}
