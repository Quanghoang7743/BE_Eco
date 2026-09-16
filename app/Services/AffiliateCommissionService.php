<?php

namespace App\Services;

use App\Events\AffiliateWalletUpdated;
use App\Models\AffiliateCommission;
use App\Models\AffiliateCustomer;
use App\Models\AffiliateWalletLog;
use App\Models\CommissionUser;
use App\Models\AffiliateProfile;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SystemConfig;
use App\Models\User;
use App\Models\WithdrawRequest;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AffiliateCommissionService
{
    public const CONFIG_HOLD_DAYS = 'affiliate_commission_hold_days';
    public const CONFIG_MINIMUM_PAYOUT = 'affiliate_minimum_payout_amount';
    public const CONFIG_TAX_WITHHOLDING_RATE = 'affiliate_tax_withholding_rate';
    public const CONFIG_PAYOUT_CYCLE = 'affiliate_payout_cycle';
    public const CONFIG_MANUAL_APPROVAL = 'affiliate_manual_approval_required';

    public function hasCommissionTable(): bool
    {
        return Schema::hasTable('affiliate_commissions');
    }

    public function getHoldDays(): int
    {
        return max(0, (int) $this->getConfigValue(self::CONFIG_HOLD_DAYS, 7));
    }

    public function getMinimumPayoutAmount(): float
    {
        return max(0, (float) $this->getConfigValue(self::CONFIG_MINIMUM_PAYOUT, 100000));
    }

    public function getTaxWithholdingRate(): float
    {
        return max(0, (float) $this->getConfigValue(self::CONFIG_TAX_WITHHOLDING_RATE, 0));
    }

    public function isManualApprovalRequired(): bool
    {
        return filter_var($this->getConfigValue(self::CONFIG_MANUAL_APPROVAL, true), FILTER_VALIDATE_BOOL);
    }

    public function syncOrderCommissions(Order $order): void
    {
        if (!$this->hasCommissionTable()) {
            return;
        }

        $order->loadMissing(['items.product', 'items.variant']);
        if ($order->items->isEmpty()) {
            return;
        }

        $customerId = $order->user_id ? (int) $order->user_id : null;
        $orderItemIds = $order->items->pluck('id')->map(fn ($id) => (string) $id)->filter()->values()->all();

        foreach ($order->items as $item) {
            $recipient = $this->resolveCommissionRecipient($order, $item);
            $affiliateId = (int) ($recipient['affiliate_id'] ?? 0);

            if ($affiliateId <= 0) {
                AffiliateCommission::query()
                    ->where('order_item_id', (string) $item->id)
                    ->whereNotIn('status', [
                        AffiliateCommission::STATUS_PAID,
                        AffiliateCommission::STATUS_CLAWBACK,
                        AffiliateCommission::STATUS_PAYOUT_PENDING,
                    ])
                    ->delete();
                continue;
            }

            $amount = $this->resolveCommissionAmount($item);
            if ($amount <= 0) {
                continue;
            }

            $baseAmount = $this->resolveBaseAmount($item);
            $commissionType = (string) ($item->commission_rate_mode ?? 'percent');
            $commissionValue = $this->resolveCommissionValue($commissionType, $amount, $baseAmount, $item);
            $status = $this->determineStatusForOrder($order, $affiliateId, $item);
            $eligibleAt = $status === AffiliateCommission::STATUS_ELIGIBLE ? now() : null;

            AffiliateCommission::query()
                ->where('order_item_id', (string) $item->id)
                ->where('affiliate_id', '<>', $affiliateId)
                ->whereNotIn('status', [
                    AffiliateCommission::STATUS_PAID,
                    AffiliateCommission::STATUS_CLAWBACK,
                    AffiliateCommission::STATUS_PAYOUT_PENDING,
                ])
                ->delete();

            $existingCommission = AffiliateCommission::query()
                ->where('order_item_id', (string) $item->id)
                ->where('affiliate_id', $affiliateId)
                ->first();

            if ($existingCommission && in_array($existingCommission->status, [
                AffiliateCommission::STATUS_PAID,
                AffiliateCommission::STATUS_CLAWBACK,
            ], true)) {
                continue;
            }

            $assignmentId = null;
            if (($recipient['source_type'] ?? '') !== 'product_link') {
                $assignment = app(AffiliateCustomerService::class)->ensureOrderBackfillAssignment($order, $affiliateId);
                $assignmentId = $assignment?->id ?? $this->resolveSourceAssignmentId($order, $affiliateId);
            }

            $commissionPayload = [
                'customer_id' => $customerId,
                'order_id' => (string) $order->id,
                'product_id' => $item->product_id ? (int) $item->product_id : null,
                'seller_id' => $order->store_id ? (string) $order->store_id : null,
                'commission_type' => $commissionType,
                'commission_value' => $commissionValue,
                'commission_base_amount' => $baseAmount,
                'pending_amount' => round($amount, 2),
                'approved_amount' => in_array($status, [
                    AffiliateCommission::STATUS_APPROVED,
                    AffiliateCommission::STATUS_PAYOUT_PENDING,
                    AffiliateCommission::STATUS_PAID,
                ], true) ? round($amount, 2) : 0,
                'tax_withholding_amount' => 0,
                'payout_amount' => 0,
                'status' => $status,
                'reason_cancelled' => null,
                'held_reason' => null,
                'approved_by' => null,
                'approved_at' => null,
                'eligible_at' => $eligibleAt,
                'paid_at' => null,
                'payout_batch_id' => null,
                'source_assignment_id' => $assignmentId,
                'policy_snapshot_json' => $this->buildPolicySnapshot($order, $item, $affiliateId, $assignmentId, $amount, $baseAmount, $commissionType, $commissionValue, (string) ($recipient['source_type'] ?? 'unknown')),
            ];

            if ($status === AffiliateCommission::STATUS_CANCELLED) {
                $commissionPayload['approved_amount'] = 0;
                $commissionPayload['tax_withholding_amount'] = 0;
                $commissionPayload['payout_amount'] = 0;
                $commissionPayload['reason_cancelled'] = 'Đơn hàng không còn hợp lệ để trả hoa hồng.';
            }

            $commission = AffiliateCommission::query()->updateOrCreate(
                [
                    'order_item_id' => (string) $item->id,
                    'affiliate_id' => $affiliateId,
                ],
                $commissionPayload,
            );

            $this->syncLegacyCommissionRow($order, $item, $affiliateId, $amount, $status);

            if ($status !== AffiliateCommission::STATUS_CANCELLED) {
                app(StoreCreditService::class)->deductAffiliateCommissionForCommission(
                    $commission->fresh(['order', 'orderItem']) ?? $commission,
                    $amount,
                );
            }
        }

        if (!empty($orderItemIds)) {
            AffiliateCommission::query()
                ->where('order_id', (string) $order->id)
                ->whereNotIn('order_item_id', $orderItemIds)
                ->whereNotIn('status', [
                    AffiliateCommission::STATUS_PAID,
                    AffiliateCommission::STATUS_CLAWBACK,
                    AffiliateCommission::STATUS_PAYOUT_PENDING,
                ])
                ->delete();
        }

        $this->syncLegacyCommissionRows($order, $this->resolveOrderAffiliateRecipientId($order));
    }

    public function syncOrderLifecycle(Order $order, ?string $reason = null): void
    {
        $orderIsCancelledOrRefunded = $this->orderIsCancelledOrRefunded($order);
        if ($orderIsCancelledOrRefunded) {
            $this->cancelLegacyPendingCommissions($order);
        }

        if (!$this->hasCommissionTable()) {
            return;
        }

        $order->loadMissing('items');
        $commissions = AffiliateCommission::query()
            ->with('orderItem')
            ->where('order_id', (string) $order->id)
            ->get();
        $summaryUpdateAffiliateIds = [];

        foreach ($commissions as $commission) {
            if ($commission->status === AffiliateCommission::STATUS_CLAWBACK) {
                continue;
            }

            $orderItem = $commission->orderItem
                ?: $order->items->firstWhere('id', $commission->order_item_id);
            $nextStatus = $this->determineStatusForOrder($order, (int) $commission->affiliate_id, $orderItem);

            if ($commission->status === AffiliateCommission::STATUS_PAID) {
                if ($nextStatus === AffiliateCommission::STATUS_CANCELLED) {
                    $this->clawbackPaidCommission($commission, $reason ?: 'Đơn hàng không còn hợp lệ để trả hoa hồng.');
                }
                continue;
            }

            $payload = [
                'status' => $nextStatus,
                'reason_cancelled' => $nextStatus === AffiliateCommission::STATUS_CANCELLED ? ($reason ?: 'Đơn hàng không còn hợp lệ để trả hoa hồng.') : null,
            ];

            if ($nextStatus === AffiliateCommission::STATUS_CANCELLED) {
                $payload['approved_amount'] = 0;
                $payload['tax_withholding_amount'] = 0;
                $payload['payout_amount'] = 0;
                $payload['approved_at'] = null;
                $payload['paid_at'] = null;
                $payload['payout_batch_id'] = null;
            }

            if ($nextStatus === AffiliateCommission::STATUS_ELIGIBLE && !$commission->eligible_at) {
                $payload['eligible_at'] = now();
            }

            if ($commission->status === AffiliateCommission::STATUS_HELD && $nextStatus !== AffiliateCommission::STATUS_CANCELLED && !$orderIsCancelledOrRefunded) {
                continue;
            }

            $commission->fill($payload);
            $commission->save();

            if ($nextStatus === AffiliateCommission::STATUS_CANCELLED) {
                $this->syncLegacyCommissionStatus($commission, 'cancel');
                app(StoreCreditService::class)->refundAffiliateCommissionForCommission(
                    $commission->fresh(['order', 'orderItem']) ?? $commission,
                    null,
                    null,
                    null,
                    $reason ?: 'Đơn hàng không còn hợp lệ để trả hoa hồng.',
                );

                $summaryUpdateAffiliateIds[(int) $commission->affiliate_id] = true;
            }
        }

        $this->syncLegacyCommissionRows($order, $this->resolveAffiliateRecipientId($order));

        foreach (array_keys($summaryUpdateAffiliateIds) as $affiliateId) {
            $this->broadcastAffiliateSummaryUpdated(
                (int) $affiliateId,
                'affiliate_commission_cancelled',
                null,
                [
                    'order_id' => (string) $order->id,
                    'status' => AffiliateCommission::STATUS_CANCELLED,
                ],
            );
        }
    }

    public function settleLegacyPendingCommissions(Order $order): array
    {
        if ($this->hasCommissionTable() || !Schema::hasTable('commission_users')) {
            return [
                'settled_count' => 0,
                'settled_amount' => 0.0,
            ];
        }

        $order->loadMissing(['items.product', 'items.variant']);
        if ($order->items->isEmpty()) {
            return [
                'settled_count' => 0,
                'settled_amount' => 0.0,
            ];
        }

        $affiliateId = $this->resolveAffiliateRecipientId($order);
        if (!$affiliateId) {
            return [
                'settled_count' => 0,
                'settled_amount' => 0.0,
            ];
        }

        if (!in_array($this->determineStatusForOrder($order, $affiliateId), [
            AffiliateCommission::STATUS_ELIGIBLE,
            AffiliateCommission::STATUS_APPROVED,
        ], true)) {
            return [
                'settled_count' => 0,
                'settled_amount' => 0.0,
            ];
        }

        $pendingCommissions = CommissionUser::query()
            ->whereIn('order_item_id', $order->items->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->where('status', 'pending')
            ->get();

        $settledCount = 0;
        $settledAmount = 0.0;

        foreach ($pendingCommissions as $commission) {
            $recipientId = (int) ($commission->user_id ?? 0);
            if ($recipientId <= 0) {
                continue;
            }

            $amount = round((float) ($commission->amount ?? 0), 2);
            if ($amount <= 0) {
                $commission->status = 'paid';
                $commission->save();
                continue;
            }

            DB::transaction(function () use ($commission, $recipientId, $amount, &$settledCount, &$settledAmount): void {
                $referrer = User::query()->whereKey($recipientId)->lockForUpdate()->first();
                if (!$referrer) {
                    return;
                }

                $referrer->balance = round((float) ($referrer->balance ?? 0) + $amount, 2);
                $referrer->save();

                $commission->status = 'paid';
                $commission->save();

                $settledCount++;
                $settledAmount += $amount;
            });
        }

        return [
            'settled_count' => $settledCount,
            'settled_amount' => round($settledAmount, 2),
        ];
    }

    public function reverseLegacyPaidCommissions(Order $order, string $reason = 'Đơn hàng bị hủy/hoàn.'): array
    {
        if (!Schema::hasTable('commission_users')) {
            return [
                'reversed_count' => 0,
                'reversed_amount' => 0.0,
                'cancelled_count' => 0,
            ];
        }

        $order->loadMissing('items');
        $orderItemIds = $order->items->pluck('id')->map(fn ($id) => (string) $id)->filter()->values()->all();
        if (empty($orderItemIds)) {
            return [
                'reversed_count' => 0,
                'reversed_amount' => 0.0,
                'cancelled_count' => 0,
            ];
        }

        $commissions = CommissionUser::query()
            ->whereIn('order_item_id', $orderItemIds)
            ->whereIn('status', ['pending', 'paid'])
            ->get();

        $reversedCount = 0;
        $reversedAmount = 0.0;
        $cancelledCount = 0;

        foreach ($commissions as $commission) {
            $wasPaid = $commission->status === 'paid';
            $amount = round((float) ($commission->amount ?? 0), 2);
            $auditKey = 'legacy_commission_reverse:' . (string) $commission->id;

            DB::transaction(function () use ($commission, $wasPaid, $amount, $reason, $auditKey, &$reversedCount, &$reversedAmount, &$cancelledCount): void {
                $alreadyReversed = $this->affiliateAuditLogExists($auditKey);

                if ($wasPaid && $amount > 0 && !$alreadyReversed) {
                    $referrer = User::query()->whereKey((int) $commission->user_id)->lockForUpdate()->first();
                    if ($referrer) {
                        $before = (float) ($referrer->balance ?? 0);
                        $after = round($before - $amount, 2);
                        $referrer->balance = $after;
                        $referrer->save();

                        $this->writeAffiliateAuditLog(
                            $auditKey,
                            [
                                'commission_user_id' => (int) $commission->id,
                                'affiliate_id' => (int) $commission->user_id,
                                'amount' => $amount,
                                'balance_before' => $before,
                                'balance_after' => $after,
                            ],
                            $reason,
                        );

                        $reversedCount++;
                        $reversedAmount += $amount;
                    }
                }

                if ($commission->status !== 'cancel') {
                    $commission->status = 'cancel';
                    $commission->save();
                    $cancelledCount++;
                }
            });
        }

        return [
            'reversed_count' => $reversedCount,
            'reversed_amount' => round($reversedAmount, 2),
            'cancelled_count' => $cancelledCount,
        ];
    }

    public function restoreLegacyCancelledCommissions(Order $order, string $reason = 'Yêu cầu hoàn hàng bị từ chối, khôi phục hoa hồng.'): array
    {
        if (!Schema::hasTable('commission_users')) {
            return [
                'restored_count' => 0,
                'restored_amount' => 0.0,
            ];
        }

        $order->loadMissing('items');
        $orderItemIds = $order->items->pluck('id')->map(fn ($id) => (string) $id)->filter()->values()->all();
        if (empty($orderItemIds)) {
            return [
                'restored_count' => 0,
                'restored_amount' => 0.0,
            ];
        }

        $commissions = CommissionUser::query()
            ->whereIn('order_item_id', $orderItemIds)
            ->where('status', 'cancel')
            ->get();

        $restoredCount = 0;
        $restoredAmount = 0.0;

        foreach ($commissions as $commission) {
            $amount = round((float) ($commission->amount ?? 0), 2);
            $auditKey = 'legacy_commission_restore:' . (string) $commission->id . ':' . now()->timestamp;

            DB::transaction(function () use ($commission, $amount, $reason, $auditKey, &$restoredCount, &$restoredAmount): void {
                if ($amount > 0) {
                    $referrer = User::query()->whereKey((int) $commission->user_id)->lockForUpdate()->first();
                    if ($referrer) {
                        $before = (float) ($referrer->balance ?? 0);
                        $after = round($before + $amount, 2);
                        $referrer->balance = $after;
                        $referrer->save();

                        $this->writeAffiliateAuditLog(
                            $auditKey,
                            [
                                'commission_user_id' => (int) $commission->id,
                                'affiliate_id' => (int) $commission->user_id,
                                'amount' => $amount,
                                'balance_before' => $before,
                                'balance_after' => $after,
                            ],
                            $reason,
                        );

                        $restoredCount++;
                        $restoredAmount += $amount;
                    }
                }

                $commission->status = 'paid';
                $commission->save();
            });
        }

        return [
            'restored_count' => $restoredCount,
            'restored_amount' => round($restoredAmount, 2),
        ];
    }

    public function transitionWaitingReturnPeriodToEligible(): int
    {
        if (!$this->hasCommissionTable()) {
            return 0;
        }

        $released = 0;
        AffiliateCommission::query()
            ->with(['order.items', 'orderItem'])
            ->whereIn('status', [
                AffiliateCommission::STATUS_WAITING_RETURN_PERIOD,
                AffiliateCommission::STATUS_ELIGIBLE,
                AffiliateCommission::STATUS_APPROVED,
            ])
            ->whereNull('paid_at')
            ->chunkById(200, function ($rows) use (&$released): void {
                foreach ($rows as $commission) {
                    if ($this->releaseCommissionToWallet($commission)) {
                        $released++;
                    }
                }
            });

        return $released;
    }

    private function releaseCommissionToWallet(AffiliateCommission $candidate): bool
    {
        return (bool) DB::transaction(function () use ($candidate): bool {
            $commission = AffiliateCommission::query()
                ->with(['order.items', 'orderItem'])
                ->whereKey($candidate->getKey())
                ->lockForUpdate()
                ->first();

            if (!$commission || $commission->paid_at !== null || $commission->status === AffiliateCommission::STATUS_PAID) {
                return false;
            }

            if (in_array($commission->status, [
                AffiliateCommission::STATUS_CANCELLED,
                AffiliateCommission::STATUS_REJECTED,
                AffiliateCommission::STATUS_HELD,
                AffiliateCommission::STATUS_PAYOUT_PENDING,
                AffiliateCommission::STATUS_CLAWBACK,
            ], true)) {
                return false;
            }

            $order = $commission->order;
            if (!$order) {
                return false;
            }

            $orderItem = $commission->orderItem;
            $nextStatus = $this->determineStatusForOrder($order, (int) $commission->affiliate_id, $orderItem);

            if ($nextStatus === AffiliateCommission::STATUS_CANCELLED) {
                $commission->status = AffiliateCommission::STATUS_CANCELLED;
                $commission->reason_cancelled = $commission->reason_cancelled ?: 'Đơn hàng hoặc sản phẩm đã hủy/hoàn trong thời gian chờ.';
                $commission->approved_amount = 0;
                $commission->tax_withholding_amount = 0;
                $commission->payout_amount = 0;
                $commission->save();
                $this->syncLegacyCommissionStatus($commission, 'cancel');
                app(StoreCreditService::class)->refundAffiliateCommissionForCommission(
                    $commission->fresh(['order', 'orderItem']) ?? $commission,
                    null,
                    null,
                    null,
                    $commission->reason_cancelled,
                );
                return false;
            }

            if ($nextStatus === AffiliateCommission::STATUS_ELIGIBLE && $commission->status === AffiliateCommission::STATUS_APPROVED) {
                $nextStatus = AffiliateCommission::STATUS_APPROVED;
            }

            if (!in_array($nextStatus, [
                AffiliateCommission::STATUS_ELIGIBLE,
                AffiliateCommission::STATUS_APPROVED,
            ], true)) {
                if ($commission->status !== $nextStatus) {
                    $commission->status = $nextStatus;
                    $commission->save();
                }
                return false;
            }

            if ($nextStatus === AffiliateCommission::STATUS_ELIGIBLE) {
                if ($commission->status !== $nextStatus) {
                    $commission->status = $nextStatus;
                    $commission->eligible_at = $commission->eligible_at ?: now();
                    $commission->save();
                }
                return false;
            }

            $amount = round((float) ($commission->approved_amount > 0 ? $commission->approved_amount : $commission->pending_amount), 2);
            if ($amount <= 0) {
                $commission->status = AffiliateCommission::STATUS_PAID;
                $commission->approved_amount = 0;
                $commission->payout_amount = 0;
                $commission->paid_at = now();
                $commission->save();
                $this->syncLegacyCommissionStatus($commission, 'paid');
                return false;
            }

            $affiliate = User::query()
                ->whereKey((int) $commission->affiliate_id)
                ->lockForUpdate()
                ->first();

            if (!$affiliate) {
                return false;
            }

            $before = round((float) ($affiliate->balance ?? 0), 2);
            $after = round($before + $amount, 2);
            $affiliate->balance = $after;
            $affiliate->save();

            $now = now();
            $commission->status = AffiliateCommission::STATUS_PAID;
            $commission->approved_amount = $amount;
            $commission->payout_amount = $amount;
            $commission->eligible_at = $commission->eligible_at ?: $now;
            $commission->approved_at = $commission->approved_at ?: $now;
            $commission->paid_at = $now;
            $commission->save();

            $walletLog = $this->writeCommissionCreditWalletLog($commission, $before, $after, $amount, $now);
            $this->syncLegacyCommissionStatus($commission, 'paid');

            $this->broadcastAffiliateSummaryUpdated(
                (int) $commission->affiliate_id,
                'affiliate_commission_credit',
                $walletLog,
            );

            return true;
        });
    }

    public function paginateCommissions(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->buildCommissionQuery($filters);
        return $query->paginate($perPage)->through(fn (AffiliateCommission $commission) => $this->formatCommission($commission));
    }

    public function getCommission(int $commissionId): array
    {
        $commission = AffiliateCommission::query()
            ->with(['affiliate', 'customer', 'order', 'orderItem', 'product', 'seller', 'sourceAssignment', 'payoutBatch'])
            ->findOrFail($commissionId);

        return $this->formatCommission($commission, true);
    }

    public function approveCommission(int $commissionId, ?User $actor = null): array
    {
        $commission = AffiliateCommission::query()->findOrFail($commissionId);

        if ($commission->status !== AffiliateCommission::STATUS_ELIGIBLE) {
            throw ValidationException::withMessages([
                'status' => 'Chỉ hoa hồng ELIGIBLE mới có thể duyệt.',
            ]);
        }

        $this->assertAffiliateEligibleForPayout((int) $commission->affiliate_id);

        $commission->status = AffiliateCommission::STATUS_APPROVED;
        $commission->approved_amount = $commission->pending_amount;
        $commission->approved_by = $actor?->id;
        $commission->approved_at = now();
        $commission->save();

        return $this->formatCommission($commission->fresh(['affiliate', 'customer', 'order', 'orderItem', 'product', 'seller', 'sourceAssignment', 'payoutBatch']), true);
    }

    public function rejectCommission(int $commissionId, string $reason): array
    {
        return $this->cancelCommission($commissionId, $reason, AffiliateCommission::STATUS_REJECTED);
    }

    public function holdCommission(int $commissionId, string $reason): array
    {
        $commission = AffiliateCommission::query()->findOrFail($commissionId);
        if (!in_array($commission->status, [AffiliateCommission::STATUS_ELIGIBLE, AffiliateCommission::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Chỉ có thể giữ hoa hồng đang ELIGIBLE hoặc APPROVED.',
            ]);
        }

        $commission->status = AffiliateCommission::STATUS_HELD;
        $commission->held_reason = trim($reason) !== '' ? trim($reason) : 'Held by admin.';
        $commission->save();

        return $this->formatCommission($commission->fresh(['affiliate', 'customer', 'order', 'orderItem', 'product', 'seller', 'sourceAssignment', 'payoutBatch']), true);
    }

    public function releaseCommission(int $commissionId): array
    {
        $commission = AffiliateCommission::query()->findOrFail($commissionId);
        if ($commission->status !== AffiliateCommission::STATUS_HELD) {
            throw ValidationException::withMessages([
                'status' => 'Chỉ có thể mở giữ hoa hồng đang HELD.',
            ]);
        }

        $commission->status = AffiliateCommission::STATUS_ELIGIBLE;
        $commission->held_reason = null;
        $commission->eligible_at = $commission->eligible_at ?: now();
        $commission->save();

        return $this->formatCommission($commission->fresh(['affiliate', 'customer', 'order', 'orderItem', 'product', 'seller', 'sourceAssignment', 'payoutBatch']), true);
    }

    public function cancelCommission(int $commissionId, string $reason, string $targetStatus = AffiliateCommission::STATUS_CANCELLED): array
    {
        $commission = AffiliateCommission::query()->findOrFail($commissionId);
        if (in_array($commission->status, [AffiliateCommission::STATUS_PAID, AffiliateCommission::STATUS_PAYOUT_PENDING], true)) {
            throw ValidationException::withMessages([
                'status' => 'Không thể hủy/từ chối hoa hồng đã vào kỳ chi trả hoặc đã thanh toán.',
            ]);
        }

        $commission->status = $targetStatus;
        $commission->reason_cancelled = trim($reason) !== '' ? trim($reason) : 'Cancelled by admin.';
        if ($targetStatus === AffiliateCommission::STATUS_REJECTED) {
            $commission->approved_amount = 0;
        }
        $commission->save();

        if (in_array($targetStatus, [AffiliateCommission::STATUS_CANCELLED, AffiliateCommission::STATUS_REJECTED], true)) {
            app(StoreCreditService::class)->refundAffiliateCommissionForCommission(
                $commission->fresh(['order', 'orderItem']) ?? $commission,
                null,
                null,
                null,
                $commission->reason_cancelled,
            );
        }

        return $this->formatCommission($commission->fresh(['affiliate', 'customer', 'order', 'orderItem', 'product', 'seller', 'sourceAssignment', 'payoutBatch']), true);
    }

    public function clawbackCommission(int $commissionId, string $reason): array
    {
        $commission = AffiliateCommission::query()->findOrFail($commissionId);
        if ($commission->status !== AffiliateCommission::STATUS_PAID) {
            throw ValidationException::withMessages([
                'status' => 'Chỉ có thể tạo clawback cho hoa hồng đã PAID.',
            ]);
        }

        return $this->formatCommission($this->createClawback($commission, $reason)->fresh([
            'affiliate', 'customer', 'order', 'orderItem', 'product', 'seller', 'sourceAssignment', 'payoutBatch',
        ]), true);
    }

    public function getDashboardSummary(int $affiliateId): array
    {
        $base = AffiliateCommission::query()->where('affiliate_id', $affiliateId);

        $commissionPending = (float) (clone $base)
            ->whereIn('status', [
                AffiliateCommission::STATUS_PENDING,
                AffiliateCommission::STATUS_WAITING_ORDER_COMPLETE,
                AffiliateCommission::STATUS_WAITING_RETURN_PERIOD,
                AffiliateCommission::STATUS_ELIGIBLE,
                AffiliateCommission::STATUS_APPROVED,
                AffiliateCommission::STATUS_HELD,
            ])
            ->sum('pending_amount');

        $totalIncome = (float) (clone $base)
            ->where('status', AffiliateCommission::STATUS_PAID)
            ->sum(DB::raw('COALESCE(NULLIF(payout_amount, 0), approved_amount, pending_amount, 0)'));

        $legacyWithdrawn = $this->sumLegacyWithdrawRequests($affiliateId);
        $payoutBatchWithdrawn = $this->sumPaidPayoutBatchItems($affiliateId);
        $commissionWithdrawn = round($legacyWithdrawn + $payoutBatchWithdrawn, 2);

        $availableBalance = (float) (User::query()->whereKey($affiliateId)->value('balance') ?? 0);

        $referralCount = $this->resolveReferralCount($affiliateId);
        $referralOrders = $this->directReferralOrdersQuery($affiliateId);
        $referralOrderCount = (clone $referralOrders)->count();
        $referralSales = (float) (clone $referralOrders)
            ->sum(DB::raw('COALESCE(final_amount, total_amount, total, 0)'));

        return [
            'commission_pending' => round($commissionPending, 2),
            'pending_balance' => round($commissionPending, 2),
            'commission_withdrawn' => $commissionWithdrawn,
            'total_withdrawn' => $commissionWithdrawn,
            'available_balance' => round($availableBalance, 2),
            'commission_receivable' => round($availableBalance, 2),
            'total_income' => round($totalIncome, 2),
            'wallet_updated_at' => now()->toIso8601String(),
            'referral_count' => $referralCount,
            'referral_order_count' => $referralOrderCount,
            'referral_sales' => round($referralSales, 2),
            'manual_withdraw_enabled' => false,
        ];
    }

    public function paginateAffiliateCommissionHistory(int $affiliateId, string $status = 'all', int $page = 1, int $perPage = 20): array
    {
        $query = AffiliateCommission::query()
            ->with(['order', 'orderItem', 'customer'])
            ->where('affiliate_id', $affiliateId)
            ->when($status !== 'all', function (Builder $builder) use ($status): void {
                $builder->where('status', strtoupper($status));
            })
            ->orderByDesc('created_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $historyItems = collect($paginator->items())->map(fn (AffiliateCommission $commission) => $this->formatHistoryRow($commission));
        $grouped = $historyItems->groupBy('order_id')->map(function (Collection $rows, $orderId) {
            return [
                'order_id' => $orderId,
                'order_code' => $rows->first()['order_code'] ?? null,
                'order_status' => $rows->first()['order_status'] ?? null,
                'order_created_at' => $rows->first()['order_created_at'] ?? null,
                'buyer_id' => $rows->first()['buyer_id'] ?? null,
                'buyer_name' => $rows->first()['buyer_name'] ?? null,
                'commissions' => $rows->map(function (array $row) {
                    unset($row['order_code'], $row['order_status'], $row['order_created_at'], $row['buyer_id'], $row['buyer_name']);
                    return $row;
                })->values(),
            ];
        })->values();

        return [
            'history' => $grouped,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    public function commissionChart(int $affiliateId, Carbon $start, Carbon $end): array
    {
        $rows = AffiliateCommission::query()
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('SUM(COALESCE(approved_amount, pending_amount, 0)) as commission')
            ->selectRaw("SUM(CASE WHEN status IN ('PENDING', 'WAITING_ORDER_COMPLETE', 'WAITING_RETURN_PERIOD', 'ELIGIBLE', 'HELD') THEN COALESCE(pending_amount, 0) ELSE 0 END) as pending_commission")
            ->selectRaw("SUM(CASE WHEN status = 'PAID' THEN COALESCE(NULLIF(payout_amount, 0), approved_amount, 0) ELSE 0 END) as available_commission")
            ->selectRaw("SUM(CASE WHEN status = 'PAID' THEN COALESCE(NULLIF(payout_amount, 0), approved_amount, 0) ELSE 0 END) as paid_commission")
            ->selectRaw('SUM(COALESCE(commission_base_amount, 0)) as revenue')
            ->selectRaw('COUNT(DISTINCT order_id) as total_orders')
            ->selectRaw('COUNT(DISTINCT customer_id) as buyer_count')
            ->where('affiliate_id', $affiliateId)
            ->whereBetween('created_at', [$start, $end])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'revenue' => (float) $row->revenue,
                'total_orders' => (int) $row->total_orders,
                'commission' => (float) $row->commission,
                'pending_commission' => (float) $row->pending_commission,
                'available_commission' => (float) $row->available_commission,
                'paid_commission' => (float) $row->paid_commission,
                'buyer_count' => (int) $row->buyer_count,
            ])
            ->values()
            ->all();

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'commission_rows' => $rows,
        ];
    }

    public function manualWithdrawEnabled(): bool
    {
        return false;
    }

    public function assertAffiliateEligibleForPayout(int $affiliateId): void
    {
        $profile = AffiliateProfile::query()->where('user_id', $affiliateId)->first();
        $bankAccount = BankAccount::query()->where('user_id', $affiliateId)->latest('id')->first();
        $user = User::query()->find($affiliateId);

        $messages = [];
        if (!$profile || !in_array((string) $profile->status, AffiliateProfile::ACTIVE_STATUSES, true)) {
            $messages[] = 'Affiliate chưa có hồ sơ hợp lệ.';
        }
        if (!$profile || empty($profile->personal_tax_code)) {
            $messages[] = 'Affiliate thiếu mã số thuế.';
        }
        if (!$bankAccount) {
            $messages[] = 'Affiliate chưa liên kết tài khoản ngân hàng.';
        }
        if (!$user) {
            $messages[] = 'Affiliate không tồn tại.';
        }

        if (!empty($messages)) {
            throw ValidationException::withMessages([
                'affiliate' => implode(' ', $messages),
            ]);
        }
    }

    public function assertAffiliatesEligibleForPayout(array $affiliateIds): void
    {
        $affiliateIds = array_values(array_unique(array_filter(array_map(
            static fn ($affiliateId): int => (int) $affiliateId,
            $affiliateIds
        ), static fn (int $affiliateId): bool => $affiliateId > 0)));

        if (empty($affiliateIds)) {
            return;
        }

        $profilesByUserId = AffiliateProfile::query()
            ->whereIn('user_id', $affiliateIds)
            ->get()
            ->keyBy(static fn (AffiliateProfile $profile): int => (int) $profile->user_id);
        $bankAccountsByUserId = BankAccount::query()
            ->whereIn('user_id', $affiliateIds)
            ->orderByDesc('id')
            ->get()
            ->unique('user_id')
            ->keyBy(static fn (BankAccount $bankAccount): int => (int) $bankAccount->user_id);
        $usersById = User::query()
            ->whereIn('id', $affiliateIds)
            ->get()
            ->keyBy(static fn (User $user): int => (int) $user->id);

        $validationMessages = [];
        foreach ($affiliateIds as $affiliateId) {
            $messages = $this->payoutEligibilityMessages(
                $profilesByUserId->get($affiliateId),
                $bankAccountsByUserId->get($affiliateId),
                $usersById->get($affiliateId)
            );

            if (empty($messages)) {
                continue;
            }

            $validationMessages[] = 'Affiliate #' . $affiliateId . ': ' . implode(' ', $messages);
        }

        if (!empty($validationMessages)) {
            throw ValidationException::withMessages([
                'affiliate' => implode(' ', $validationMessages),
            ]);
        }
    }

    private function payoutEligibilityMessages(?AffiliateProfile $profile, ?BankAccount $bankAccount, ?User $user): array
    {
        $messages = [];
        if (!$profile || !in_array((string) $profile->status, AffiliateProfile::ACTIVE_STATUSES, true)) {
            $messages[] = 'Affiliate chua co ho so hop le.';
        }
        if (!$profile || empty($profile->personal_tax_code)) {
            $messages[] = 'Affiliate thieu ma so thue.';
        }
        if (!$bankAccount) {
            $messages[] = 'Affiliate chua lien ket tai khoan ngan hang.';
        }
        if (!$user) {
            $messages[] = 'Affiliate khong ton tai.';
        }

        return $messages;
    }

    private function buildCommissionQuery(array $filters): Builder
    {
        return AffiliateCommission::query()
            ->with(['affiliate', 'customer', 'order', 'orderItem', 'product', 'seller', 'sourceAssignment', 'payoutBatch'])
            ->when(!empty($filters['affiliate_id']), fn (Builder $q) => $q->where('affiliate_id', (int) $filters['affiliate_id']))
            ->when(!empty($filters['customer_id']), fn (Builder $q) => $q->where('customer_id', (int) $filters['customer_id']))
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', strtoupper((string) $filters['status'])))
            ->when(!empty($filters['payout_batch_id']), fn (Builder $q) => $q->where('payout_batch_id', (int) $filters['payout_batch_id']))
            ->when(!empty($filters['order_keyword']), function (Builder $q) use ($filters): void {
                $keyword = trim((string) $filters['order_keyword']);
                $q->where(function (Builder $nested) use ($keyword): void {
                    $nested->where('order_id', 'like', '%' . $keyword . '%')
                        ->orWhereHas('order', fn (Builder $orderQuery) => $orderQuery->where('order_code', 'like', '%' . $keyword . '%'));
                });
            })
            ->when(!empty($filters['from']), fn (Builder $q) => $q->whereDate('created_at', '>=', Carbon::parse((string) $filters['from'])->toDateString()))
            ->when(!empty($filters['to']), fn (Builder $q) => $q->whereDate('created_at', '<=', Carbon::parse((string) $filters['to'])->toDateString()))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function formatCommission(AffiliateCommission $commission, bool $includePolicy = false): array
    {
        return [
            'id' => (int) $commission->id,
            'affiliate_id' => (int) $commission->affiliate_id,
            'affiliate_name' => $commission->affiliate?->personal_full_name ?? $commission->affiliate?->full_name ?? $commission->affiliate?->name,
            'customer_id' => $commission->customer_id ? (int) $commission->customer_id : null,
            'customer_name' => $commission->customer?->personal_full_name ?? $commission->customer?->full_name ?? $commission->customer?->name,
            'order_id' => $commission->order_id,
            'order_code' => $commission->order?->order_code,
            'order_status' => $commission->order?->status,
            'order_item_id' => $commission->order_item_id,
            'product_id' => $commission->product_id ? (int) $commission->product_id : null,
            'product_name' => $commission->orderItem?->item_name ?? $commission->orderItem?->product_name ?? $commission->product?->name,
            'seller_id' => $commission->seller_id,
            'seller_name' => $commission->seller?->name,
            'commission_type' => $commission->commission_type,
            'commission_value' => (float) $commission->commission_value,
            'commission_base_amount' => (float) $commission->commission_base_amount,
            'pending_amount' => (float) $commission->pending_amount,
            'approved_amount' => (float) $commission->approved_amount,
            'tax_withholding_amount' => (float) $commission->tax_withholding_amount,
            'payout_amount' => (float) $commission->payout_amount,
            'status' => $commission->status,
            'reason_cancelled' => $commission->reason_cancelled,
            'held_reason' => $commission->held_reason,
            'approved_by' => $commission->approved_by,
            'approved_at' => optional($commission->approved_at)->toIso8601String(),
            'eligible_at' => optional($commission->eligible_at)->toIso8601String(),
            'paid_at' => optional($commission->paid_at)->toIso8601String(),
            'payout_batch_id' => $commission->payout_batch_id ? (int) $commission->payout_batch_id : null,
            'payout_batch_code' => $commission->payoutBatch?->batch_code,
            'source_assignment_id' => $commission->source_assignment_id ? (int) $commission->source_assignment_id : null,
            'created_at' => optional($commission->created_at)->toIso8601String(),
            'updated_at' => optional($commission->updated_at)->toIso8601String(),
            'policy_snapshot_json' => $includePolicy ? ($commission->policy_snapshot_json ?? null) : null,
        ];
    }

    private function formatHistoryRow(AffiliateCommission $commission): array
    {
        return [
            'commission_id' => (int) $commission->id,
            'amount' => (float) ($commission->approved_amount > 0 ? $commission->approved_amount : $commission->pending_amount),
            'level' => 1,
            'status' => strtolower($commission->status),
            'created_at' => optional($commission->created_at)->toDateTimeString(),
            'order_item_id' => $commission->order_item_id,
            'product_id' => $commission->product_id,
            'product_name' => $commission->orderItem?->item_name ?? $commission->orderItem?->product_name ?? $commission->product?->name,
            'quantity' => (int) ($commission->orderItem?->quantity ?? 1),
            'unit_price' => (float) ($commission->orderItem?->unit_price ?? $commission->orderItem?->price ?? 0),
            'total_price' => (float) ($commission->orderItem?->total_price ?? $commission->commission_base_amount),
            'order_id' => $commission->order_id,
            'order_code' => $commission->order?->order_code,
            'order_status' => $commission->order?->status,
            'order_created_at' => optional($commission->order?->created_at)->toDateTimeString(),
            'buyer_id' => $commission->customer_id,
            'buyer_name' => $commission->customer?->personal_full_name ?? $commission->customer?->full_name ?? $commission->customer?->name,
        ];
    }

    /**
     * @return array{affiliate_id:int|null, source_type:string}
     */
    private function resolveCommissionRecipient(Order $order, ?OrderItem $item = null): array
    {
        $orderRecipientId = $this->resolveOrderAffiliateRecipientId($order);
        if ($orderRecipientId) {
            return [
                'affiliate_id' => $orderRecipientId,
                'source_type' => !empty($order->user_id) ? 'customer_upline' : 'order_referrer',
            ];
        }

        $itemReferrerId = $item && !empty($item->referrer_id)
            ? (int) $item->referrer_id
            : 0;

        if ($itemReferrerId <= 0) {
            return [
                'affiliate_id' => null,
                'source_type' => 'none',
            ];
        }

        if ($order->user_id && $itemReferrerId === (int) $order->user_id) {
            return [
                'affiliate_id' => null,
                'source_type' => 'self_referral',
            ];
        }

        if (!User::query()->whereKey($itemReferrerId)->exists()) {
            return [
                'affiliate_id' => null,
                'source_type' => 'missing_referrer',
            ];
        }

        if ($this->affiliateOwnsOrderItemStore($order, $item, $itemReferrerId)) {
            return [
                'affiliate_id' => null,
                'source_type' => 'own_store',
            ];
        }

        return [
            'affiliate_id' => $itemReferrerId,
            'source_type' => 'product_link',
        ];
    }

    private function resolveOrderAffiliateRecipientId(Order $order): ?int
    {
        if ($order->user_id) {
            $userReferrerId = User::query()
                ->whereKey((int) $order->user_id)
                ->value('referrer_id');

            if ($userReferrerId) {
                return (int) $userReferrerId;
            }
        }

        if ($order->user_id && Schema::hasTable('affiliate_customers')) {
            $affiliateId = AffiliateCustomer::query()
                ->where('customer_id', (int) $order->user_id)
                ->where('status', AffiliateCustomer::STATUS_ACTIVE)
                ->where(function (Builder $query) use ($order): void {
                    $createdAt = $order->created_at ?: now();
                    $query->whereNull('assigned_at')->orWhere('assigned_at', '<=', $createdAt);
                })
                ->orderByDesc('assigned_at')
                ->value('affiliate_id');

            if ($affiliateId) {
                return (int) $affiliateId;
            }
        }

        if (!empty($order->referrer_id)) {
            return (int) $order->referrer_id;
        }

        return null;
    }

    private function resolveAffiliateRecipientId(Order $order): ?int
    {
        return $this->resolveOrderAffiliateRecipientId($order);
    }

    private function resolveSourceAssignmentId(Order $order, int $affiliateId): ?int
    {
        if (!Schema::hasTable('affiliate_customers') || !$order->user_id) {
            return null;
        }

        $createdAt = $order->created_at ?: now();

        $assignmentId = AffiliateCustomer::query()
            ->where('affiliate_id', $affiliateId)
            ->where('customer_id', (int) $order->user_id)
            ->whereIn('status', AffiliateCustomer::CURRENT_STATUSES)
            ->where(function (Builder $query) use ($createdAt): void {
                $query->whereNull('assigned_at')->orWhere('assigned_at', '<=', $createdAt);
            })
            ->orderByDesc('assigned_at')
            ->value('id');

        return $assignmentId ? (int) $assignmentId : null;
    }

    private function determineStatusForOrder(Order $order, int $affiliateId, ?OrderItem $item = null): string
    {
        if ($this->orderIsCancelledOrRefunded($order) || $this->orderItemIsRefunded($order, $item)) {
            return AffiliateCommission::STATUS_CANCELLED;
        }

        $paymentStatus = strtolower(trim((string) ($order->status_payment ?? '')));
        $orderStatus = strtolower(trim((string) ($order->status ?? '')));

        $isPaid = in_array($paymentStatus, ['paid', 'success', 'successful', 'completed'], true);

        if (!$isPaid) {
            return AffiliateCommission::STATUS_PENDING;
        }

        $isCompleted = in_array($orderStatus, ['completed', 'delivered', 'call_completed'], true) || !empty($order->delivered_at);
        if (!$isCompleted) {
            return AffiliateCommission::STATUS_WAITING_ORDER_COMPLETE;
        }

        if ($this->orderHasActiveReturn($order, $item)) {
            return AffiliateCommission::STATUS_CANCELLED;
        }

        if ($this->orderHasActiveComplaint($order)) {
            return AffiliateCommission::STATUS_HELD;
        }

        if (!$this->affiliateCanAccrueCommission($affiliateId)) {
            return AffiliateCommission::STATUS_HELD;
        }

        $holdDays = $this->getHoldDays();
        $completedAt = $this->resolveOrderCompletedAt($order);
        if ($holdDays > 0 && $completedAt->copy()->addDays($holdDays)->isFuture()) {
            return AffiliateCommission::STATUS_WAITING_RETURN_PERIOD;
        }

        return $this->isManualApprovalRequired()
            ? AffiliateCommission::STATUS_ELIGIBLE
            : AffiliateCommission::STATUS_APPROVED;
    }

    private function resolveOrderCompletedAt(Order $order): Carbon
    {
        $completedAt = $order->delivered_at ?? $order->confirmed_at ?? $order->updated_at ?? now();

        if ($completedAt instanceof Carbon) {
            return $completedAt->copy();
        }

        return Carbon::parse((string) $completedAt);
    }

    private function orderItemIsRefunded(Order $order, ?OrderItem $item = null): bool
    {
        if (!$item || !Schema::hasTable('order_returns') || !Schema::hasTable('order_return_items')) {
            return false;
        }

        $query = DB::table('order_returns')
            ->join('order_return_items', 'order_return_items.order_return_id', '=', 'order_returns.id')
            ->where('order_returns.order_id', (string) $order->id)
            ->where('order_return_items.order_item_id', (string) $item->id)
            ->where(function ($builder): void {
                $builder->whereIn(DB::raw('LOWER(order_returns.status)'), ['refunded'])
                    ->orWhereNotNull('order_returns.refund_processed_at');
            });

        return $query->exists();
    }

    private function orderHasActiveReturn(Order $order, ?OrderItem $item = null): bool
    {
        if (!Schema::hasTable('order_returns')) {
            return false;
        }

        $query = DB::table('order_returns')
            ->where('order_returns.order_id', (string) $order->id)
            ->whereIn(DB::raw('LOWER(order_returns.status)'), [
                'pending',
                'approved',
                'returning',
                'received',
                'disputed',
                'platform_reviewing',
                'refund_processing',
            ]);

        if ($item && Schema::hasTable('order_return_items')) {
            $query->join('order_return_items', 'order_return_items.order_return_id', '=', 'order_returns.id')
                ->where('order_return_items.order_item_id', (string) $item->id);
        }

        return $query->exists();
    }

    private function orderHasActiveComplaint(Order $order): bool
    {
        if (!Schema::hasTable('complaints')) {
            return false;
        }

        return DB::table('complaints')
            ->where('order_id', (string) $order->id)
            ->whereNotIn(DB::raw("LOWER(COALESCE(status, ''))"), [
                'resolved',
                'closed',
                'rejected',
                'cancelled',
                'canceled',
                'done',
            ])
            ->exists();
    }

    private function affiliateOwnsOrderItemStore(Order $order, ?OrderItem $item, int $affiliateId): bool
    {
        if (!Schema::hasTable('stores')) {
            return false;
        }

        $storeId = $order->store_id ?: ($item?->product?->store_id ?? null);
        if (!$storeId || !Schema::hasColumn('stores', 'owner_id')) {
            return false;
        }

        $ownerId = DB::table('stores')
            ->where('id', (string) $storeId)
            ->value('owner_id');

        return $ownerId !== null && (int) $ownerId === $affiliateId;
    }

    private function affiliateCanAccrueCommission(int $affiliateId): bool
    {
        $profile = AffiliateProfile::query()->where('user_id', $affiliateId)->first();
        if (!$profile || !in_array((string) $profile->status, AffiliateProfile::ACTIVE_STATUSES, true)) {
            return false;
        }

        return !empty($profile->personal_tax_code) && BankAccount::query()->where('user_id', $affiliateId)->exists();
    }

    private function resolveCommissionAmount(OrderItem $item): float
    {
        if (!empty($item->commission_amount)) {
            return round((float) $item->commission_amount, 2);
        }

        $baseAmount = $this->resolveBaseAmount($item);
        $rate = 0.0;

        if ($item->variant && $item->variant->commission_rate !== null) {
            $rate = (float) $item->variant->commission_rate;
        } elseif ($item->product && $item->product->commission_rate !== null) {
            $rate = (float) $item->product->commission_rate;
        }

        $mode = (string) ($item->commission_rate_mode ?? 'percent');
        if ($mode === 'fixed') {
            return round($rate, 2);
        }

        return round($baseAmount * ($rate / 100), 2);
    }

    private function resolveBaseAmount(OrderItem $item): float
    {
        return round((float) ($item->total_price ?? (($item->unit_price ?? $item->price ?? 0) * ($item->quantity ?? 1))), 2);
    }

    private function resolveCommissionValue(string $commissionType, float $amount, float $baseAmount, OrderItem $item): float
    {
        if ($commissionType === 'fixed') {
            return round((float) (($item->variant?->commission_rate ?? $item->product?->commission_rate ?? $amount)), 2);
        }

        if ($baseAmount <= 0) {
            return 0;
        }

        return round(($amount / $baseAmount) * 100, 2);
    }

    private function buildPolicySnapshot(Order $order, OrderItem $item, int $affiliateId, ?int $assignmentId, float $amount, float $baseAmount, string $commissionType, float $commissionValue, string $sourceType = 'unknown'): array
    {
        return [
            'affiliate_id' => $affiliateId,
            'source_type' => $sourceType,
            'source_assignment_id' => $assignmentId,
            'order_status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->status_payment,
            'product_id' => $item->product_id,
            'variant_id' => $item->variant_id,
            'commission_type' => $commissionType,
            'commission_value' => $commissionValue,
            'commission_amount' => round($amount, 2),
            'commission_base_amount' => round($baseAmount, 2),
            'captured_at' => now()->toIso8601String(),
        ];
    }

    private function createClawback(AffiliateCommission $commission, string $reason): AffiliateCommission
    {
        $this->clawbackPaidCommission($commission, $reason);

        return AffiliateCommission::query()->findOrFail($commission->id);
    }

    private function clawbackPaidCommission(AffiliateCommission $candidate, string $reason): ?AffiliateWalletLog
    {
        return DB::transaction(function () use ($candidate, $reason): ?AffiliateWalletLog {
            $commission = AffiliateCommission::query()
                ->whereKey($candidate->getKey())
                ->lockForUpdate()
                ->first();

            if (!$commission || $commission->status === AffiliateCommission::STATUS_CLAWBACK) {
                return null;
            }

            if ($commission->status !== AffiliateCommission::STATUS_PAID) {
                return null;
            }

            $amount = round((float) (
                abs((float) $commission->payout_amount) > 0
                    ? abs((float) $commission->payout_amount)
                    : (abs((float) $commission->approved_amount) > 0
                        ? abs((float) $commission->approved_amount)
                        : abs((float) $commission->pending_amount))
            ), 2);

            $walletLog = null;
            $now = now();

            if ($amount > 0) {
                $existingLog = $this->findCommissionDeductionWalletLog($commission);
                if ($existingLog) {
                    $walletLog = $existingLog;
                } elseif ($legacyReversal = $this->resolveLegacyCommissionReversal($commission)) {
                    $legacyAmount = round((float) ($legacyReversal['amount'] ?? $amount), 2);
                    $balanceAfter = $legacyReversal['balance_after'] ?? User::query()
                        ->whereKey((int) $commission->affiliate_id)
                        ->value('balance');
                    $balanceAfter = round((float) ($balanceAfter ?? 0), 2);
                    $balanceBefore = round((float) ($legacyReversal['balance_before'] ?? ($balanceAfter + $legacyAmount)), 2);
                    $walletLog = $this->writeCommissionDeductionWalletLog($commission, $balanceBefore, $balanceAfter, $legacyAmount, $now, $reason);
                } else {
                    $affiliate = User::query()
                        ->whereKey((int) $commission->affiliate_id)
                        ->lockForUpdate()
                        ->first();

                    if ($affiliate) {
                        $before = round((float) ($affiliate->balance ?? 0), 2);
                        $after = round($before - $amount, 2);
                        $affiliate->balance = $after;
                        $affiliate->save();

                        $walletLog = $this->writeCommissionDeductionWalletLog($commission, $before, $after, $amount, $now, $reason);
                    }
                }
            }

            $policySnapshot = is_array($commission->policy_snapshot_json) ? $commission->policy_snapshot_json : [];
            $commission->status = AffiliateCommission::STATUS_CLAWBACK;
            $commission->reason_cancelled = trim($reason) !== '' ? trim($reason) : 'Clawback generated automatically.';
            $commission->held_reason = null;
            $commission->commission_base_amount = -1 * abs((float) $commission->commission_base_amount);
            $commission->pending_amount = -1 * $amount;
            $commission->approved_amount = -1 * $amount;
            $commission->tax_withholding_amount = -1 * abs((float) $commission->tax_withholding_amount);
            $commission->payout_amount = -1 * $amount;
            $commission->paid_at = $commission->paid_at ?: $now;
            $commission->policy_snapshot_json = array_merge($policySnapshot, [
                'clawback_for_commission_id' => $commission->id,
                'clawback_processed_at' => $now->toIso8601String(),
            ]);
            $commission->save();

            $this->syncLegacyCommissionStatus($commission, 'cancel');
            app(StoreCreditService::class)->refundAffiliateCommissionForCommission(
                $commission->fresh(['order', 'orderItem']) ?? $commission,
                $amount,
                null,
                null,
                $reason,
            );

            if ($walletLog) {
                $this->broadcastAffiliateSummaryUpdated(
                    (int) $commission->affiliate_id,
                    'affiliate_commission_deduction',
                    $walletLog,
                );
            }

            return $walletLog;
        });
    }

    private function orderIsCancelledOrRefunded(Order $order): bool
    {
        $status = strtolower(trim((string) ($order->status ?? '')));
        $paymentStatus = strtolower(trim((string) ($order->status_payment ?? '')));

        return in_array($status, ['cancelled', 'canceled', 'returned', 'refunded'], true)
            || in_array($paymentStatus, ['refunded', 'chargeback'], true)
            || !empty($order->returned_at)
            || !empty($order->cancelled_at);
    }

    private function syncLegacyCommissionRow(Order $order, OrderItem $item, int $affiliateId, float $amount, string $status): void
    {
        if (!Schema::hasTable('commission_users')) {
            return;
        }

        $legacyStatus = $this->legacyStatusForCommissionStatus($status);
        $now = now();

        $existing = DB::table('commission_users')
            ->where('order_item_id', (string) $item->id)
            ->first();

        if ($existing) {
            if (($existing->status ?? null) === 'paid') {
                return;
            }

            DB::table('commission_users')
                ->where('id', $existing->id)
                ->update([
                    'user_id' => $affiliateId,
                    'amount' => round($amount, 2),
                    'status' => $legacyStatus,
                    'updated_at' => $now,
                ]);
            return;
        }

        DB::table('commission_users')->insert([
            'user_id' => $affiliateId,
            'order_item_id' => (string) $item->id,
            'level' => 1,
            'amount' => round($amount, 2),
            'status' => $legacyStatus,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function syncLegacyCommissionStatus(AffiliateCommission $commission, string $status): void
    {
        if (!Schema::hasTable('commission_users')) {
            return;
        }

        DB::table('commission_users')
            ->where('order_item_id', (string) $commission->order_item_id)
            ->where('user_id', (int) $commission->affiliate_id)
            ->update([
                'status' => $status,
                'updated_at' => now(),
            ]);
    }

    private function legacyStatusForCommissionStatus(string $status): string
    {
        return match ($status) {
            AffiliateCommission::STATUS_CANCELLED,
            AffiliateCommission::STATUS_REJECTED,
            AffiliateCommission::STATUS_CLAWBACK => 'cancel',
            AffiliateCommission::STATUS_PAID => 'paid',
            default => 'pending',
        };
    }

    private function cancelLegacyPendingCommissions(Order $order): void
    {
        if (!Schema::hasTable('commission_users')) {
            return;
        }

        $order->loadMissing('items');
        $orderItemIds = $order->items->pluck('id')->map(fn ($id) => (string) $id)->filter()->values()->all();
        if (empty($orderItemIds)) {
            return;
        }

        DB::table('commission_users')
            ->whereIn('order_item_id', $orderItemIds)
            ->where('status', 'pending')
            ->update([
                'status' => 'cancel',
                'updated_at' => now(),
            ]);
    }

    private function writeCommissionCreditWalletLog(AffiliateCommission $commission, float $before, float $after, float $amount, Carbon $createdAt): ?AffiliateWalletLog
    {
        if (!Schema::hasTable('affiliate_wallet_logs')) {
            return null;
        }

        $referenceId = (string) $commission->id;
        $exists = AffiliateWalletLog::query()
            ->where('action_type', 'affiliate_commission_credit')
            ->where('reference_type', AffiliateCommission::class)
            ->where('reference_id', $referenceId)
            ->exists();

        if ($exists) {
            return null;
        }

        $policySnapshot = is_array($commission->policy_snapshot_json) ? $commission->policy_snapshot_json : [];
        $sourceType = (string) ($policySnapshot['source_type'] ?? 'affiliate_commission');

        return AffiliateWalletLog::query()->create([
            'user_id' => (string) $commission->affiliate_id,
            'action_type' => 'affiliate_commission_credit',
            'amount_change' => (int) round($amount),
            'balance_before' => (int) round($before),
            'balance_after' => (int) round($after),
            'reference_id' => $referenceId,
            'reference_type' => AffiliateCommission::class,
            'metadata' => [
                'order_id' => $commission->order_id,
                'order_item_id' => $commission->order_item_id,
                'source_assignment_id' => $commission->source_assignment_id,
                'source_type' => $sourceType,
                'source_label' => $this->walletSourceLabel($sourceType),
            ],
            'created_at' => $createdAt,
        ]);
    }

    private function broadcastAffiliateSummaryUpdated(int $affiliateId, string $action, ?AffiliateWalletLog $walletLog = null, array $extra = []): void
    {
        if ($affiliateId <= 0) {
            return;
        }

        $callback = function () use ($affiliateId, $action, $walletLog, $extra): void {
            \Illuminate\Support\Facades\Event::dispatch(AffiliateWalletUpdated::fromLog(
                $affiliateId,
                $action,
                $this->getDashboardSummary($affiliateId),
                $walletLog,
                $extra,
            ));
        };

        $callback();
    }

    private function findCommissionDeductionWalletLog(AffiliateCommission $commission): ?AffiliateWalletLog
    {
        if (!Schema::hasTable('affiliate_wallet_logs')) {
            return null;
        }

        return AffiliateWalletLog::query()
            ->where('action_type', 'affiliate_commission_deduction')
            ->where('reference_type', AffiliateCommission::class)
            ->where('reference_id', (string) $commission->id)
            ->first();
    }

    private function writeCommissionDeductionWalletLog(AffiliateCommission $commission, float $before, float $after, float $amount, Carbon $createdAt, string $reason): ?AffiliateWalletLog
    {
        if (!Schema::hasTable('affiliate_wallet_logs')) {
            return null;
        }

        $existing = $this->findCommissionDeductionWalletLog($commission);
        if ($existing) {
            return $existing;
        }

        $policySnapshot = is_array($commission->policy_snapshot_json) ? $commission->policy_snapshot_json : [];
        $sourceType = (string) ($policySnapshot['source_type'] ?? 'affiliate_commission');

        return AffiliateWalletLog::query()->create([
            'user_id' => (string) $commission->affiliate_id,
            'action_type' => 'affiliate_commission_deduction',
            'amount_change' => -1 * (int) round($amount),
            'balance_before' => (int) round($before),
            'balance_after' => (int) round($after),
            'reference_id' => (string) $commission->id,
            'reference_type' => AffiliateCommission::class,
            'metadata' => [
                'order_id' => $commission->order_id,
                'order_item_id' => $commission->order_item_id,
                'source_assignment_id' => $commission->source_assignment_id,
                'source_type' => $sourceType,
                'source_label' => $this->walletSourceLabel($sourceType),
                'reason' => trim($reason) !== '' ? trim($reason) : null,
            ],
            'created_at' => $createdAt,
        ]);
    }

    private function resolveLegacyCommissionReversal(AffiliateCommission $commission): ?array
    {
        if (!Schema::hasTable('commission_users')) {
            return null;
        }

        $legacy = DB::table('commission_users')
            ->where('order_item_id', (string) $commission->order_item_id)
            ->where('user_id', (int) $commission->affiliate_id)
            ->first();

        if (!$legacy || strtolower((string) ($legacy->status ?? '')) !== 'cancel') {
            return null;
        }

        $amount = round((float) ($legacy->amount ?? 0), 2);
        if ($amount <= 0) {
            return null;
        }

        $payload = [];
        if (Schema::hasTable('affiliate_audit_logs')) {
            $audit = DB::table('affiliate_audit_logs')
                ->where('action', 'legacy_commission.reverse')
                ->where('object_type', 'CommissionUser')
                ->where('object_id', 'legacy_commission_reverse:' . (string) $legacy->id)
                ->orderByDesc('id')
                ->first();

            if ($audit && !empty($audit->new_value_json)) {
                $decoded = json_decode((string) $audit->new_value_json, true);
                $payload = is_array($decoded) ? $decoded : [];
            }
        }

        return [
            'amount' => round((float) ($payload['amount'] ?? $amount), 2),
            'balance_before' => array_key_exists('balance_before', $payload) ? round((float) $payload['balance_before'], 2) : null,
            'balance_after' => array_key_exists('balance_after', $payload) ? round((float) $payload['balance_after'], 2) : null,
        ];
    }

    private function sumPaidPayoutBatchItems(int $affiliateId): float
    {
        if (!Schema::hasTable('affiliate_payout_batch_items')) {
            return 0.0;
        }

        return (float) DB::table('affiliate_payout_batch_items')
            ->where('affiliate_id', $affiliateId)
            ->where('status', 'PAID')
            ->sum('net_payout_amount');
    }

    private function sumLegacyWithdrawRequests(int $affiliateId): float
    {
        if (!Schema::hasTable('withdraw_requests')) {
            return 0.0;
        }

        $query = WithdrawRequest::query()
            ->where('user_id', $affiliateId)
            ->whereIn('status', ['paid', 'completed']);

        if (Schema::hasTable('affiliate_payout_batches')) {
            $backfilledIds = DB::table('affiliate_payout_batches')
                ->where('batch_code', 'like', 'LEGACY-WR-%')
                ->pluck('batch_code')
                ->map(static fn ($code): int => (int) str_replace('LEGACY-WR-', '', (string) $code))
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();

            if (!empty($backfilledIds)) {
                $query->whereNotIn('id', $backfilledIds);
            }
        }

        return (float) $query->sum('amount');
    }

    private function walletSourceLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'product_link' => 'Link sản phẩm Affiliate',
            'customer_upline' => 'Khách hàng tuyến dưới',
            'order_referrer' => 'Đơn hàng giới thiệu',
            'affiliate_commission' => 'Hoa hồng Affiliate',
            default => 'Ví Affiliate',
        };
    }

    private function syncLegacyCommissionRows(Order $order, ?int $affiliateId): void
    {
        if (!Schema::hasTable('commission_users') || !$affiliateId) {
            return;
        }

        $legacyStatus = $this->orderIsCancelledOrRefunded($order)
            ? 'cancel'
            : 'pending';

        $query = CommissionUser::query()
            ->whereIn('order_item_id', $order->items->pluck('id')->map(fn ($id) => (string) $id)->all());

        if ($legacyStatus === 'cancel') {
            $query->where('status', 'pending')->update(['status' => 'cancel']);
        }
    }

    private function affiliateAuditLogExists(string $objectId): bool
    {
        if (!Schema::hasTable('affiliate_audit_logs')) {
            return false;
        }

        return DB::table('affiliate_audit_logs')
            ->where('action', 'legacy_commission.reverse')
            ->where('object_type', 'CommissionUser')
            ->where('object_id', $objectId)
            ->exists();
    }

    private function writeAffiliateAuditLog(string $objectId, array $payload, string $reason): void
    {
        if (!Schema::hasTable('affiliate_audit_logs')) {
            return;
        }

        DB::table('affiliate_audit_logs')->insert([
            'actor_id' => null,
            'actor_role' => 'system',
            'action' => 'legacy_commission.reverse',
            'object_type' => 'CommissionUser',
            'object_id' => $objectId,
            'old_value_json' => null,
            'new_value_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'reason' => $reason,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resolveReferralCount(int $affiliateId): int
    {
        return User::query()->where('referrer_id', $affiliateId)->count();
    }

    private function directReferralOrdersQuery(int $affiliateId): Builder
    {
        return Order::query()
            ->where(function (Builder $query) use ($affiliateId): void {
                $query
                    ->whereExists(function ($buyerQuery) use ($affiliateId): void {
                        $buyerQuery
                            ->selectRaw('1')
                            ->from('users as direct_referral_users')
                            ->whereColumn('direct_referral_users.id', 'orders.user_id')
                            ->where('direct_referral_users.referrer_id', $affiliateId);
                    })
                    ->orWhere(function (Builder $guestQuery) use ($affiliateId): void {
                        $guestQuery
                            ->whereNull('orders.user_id')
                            ->where('orders.referrer_id', $affiliateId);
                    });
            });
    }

    private function getConfigValue(string $key, mixed $default = null): mixed
    {
        if (!Schema::hasTable('system_configs')) {
            return $default;
        }

        $record = SystemConfig::query()->where('config_key', $key)->first();
        if (!$record) {
            return $default;
        }

        $value = $record->config_value;
        return $value !== null && $value !== '' ? $value : $default;
    }
}
