<?php

namespace App\Services;

use App\Events\Customer\NotificationCreatedEvent;
use App\Events\Customer\OrderNotificationEvent;
use App\Events\OrderReturnUpdated;
use App\Models\Complaint;
use App\Models\MoneyWalletWithdrawalRequest;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Store;
use App\Models\StoreAdminNotification;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class OrderReturnService
{
    private const MAX_WITHDRAWAL_AMOUNT = 9999999999999.99;

    private const EVIDENCE_REQUIRED_REASON_CODES = [
        'DAMAGED_ON_DELIVERY',
        'WRONG_ITEM',
        'MISSING_ITEM',
        'EXPIRED_ITEM',
        'COUNTERFEIT_OR_UNSAFE',
    ];

    public function __construct(
        private readonly ProductReturnEligibilityService $eligibilityService,
        private readonly RefundAmountCalculator $amountCalculator,
        private readonly MoneyWalletService $moneyWalletService,
        private readonly RefundFundingResolver $fundingResolver
    ) {}

    public function createReturnRequest(User $user, Order $order, array $payload): OrderReturn
    {
        return DB::transaction(function () use ($user, $order, $payload): OrderReturn {
            $order = Order::query()->whereKey((string) $order->id)->lockForUpdate()->firstOrFail();
            $this->assertCustomerOwnsOrder($user, $order);
            $this->assertOrderReturnable($order);
            $this->assertSupportedRefundPayload($payload);
            $this->assertEvidenceForHighRiskReason($payload);

            $requestedItems = $this->normalizeRequestedItems($payload['items'] ?? []);

            if ($requestedItems === []) {
                throw new RuntimeException('RETURN_ITEMS_REQUIRED');
            }

            $orderItems = $order->items()->lockForUpdate()->get()->keyBy(fn (OrderItem $item): string => (string) $item->id);
            $this->assertItemsReturnable($order, $orderItems, $requestedItems);
            $calculation = $this->amountCalculator->calculate($order, $requestedItems, $orderItems->values());

            if ((float) $calculation['total'] <= 0) {
                throw new RuntimeException('REFUND_AMOUNT_NOT_POSITIVE');
            }

            $isPickup = in_array(strtolower((string)($order->shipping_method ?? '')), ['pickup', 'later'], true);
            $returnMethod = $payload['return_method'] ?? 'shipping';
            if ($returnMethod === 'at_store' && !$isPickup) {
                throw new RuntimeException('RETURN_METHOD_AT_STORE_NOT_ALLOWED');
            }
            $refundBankInfo = $this->normalizeRefundBankInfo($payload['refund_bank_info'] ?? null);
            if ($returnMethod === 'shipping' && $refundBankInfo === null) {
                throw new RuntimeException('REFUND_BANK_INFO_REQUIRED');
            }

            $return = OrderReturn::query()->create([
                'return_code' => $this->generateReturnCode(),
                'order_id' => (string) $order->id,
                'user_id' => (string) $user->id,
                'store_id' => (string) $order->store_id,
                'status' => OrderReturn::STATUS_PENDING,
                'return_type' => (string) ($payload['return_type'] ?? 'refund'),
                'refund_method' => (string) ($payload['refund_method'] ?? 'money_wallet'),
                'refund_bank_info' => $refundBankInfo,
                'return_method' => $returnMethod,
                'reason_code' => $payload['reason_code'] ?? null,
                'reason' => (string) ($payload['reason'] ?? ''),
                'images' => $payload['images'] ?? [],
                'video' => $payload['video'] ?? null,
                'refund_amount_estimated' => $calculation['total'],
                'refund_currency' => 'VND',
                'auto_approve_at' => now()->addHours(48),
            ]);

            foreach ($calculation['items'] as $row) {
                OrderReturnItem::query()->create(array_merge($row, [
                    'order_return_id' => (string) $return->id,
                ]));
            }

            // Lock tickets
            $this->lockTicketsForReturn($return);

            $commissionOrder = clone $order;
            $commissionService = app(\App\Services\AffiliateCommissionService::class);
            $commissionService->reverseLegacyPaidCommissions($commissionOrder, 'Khách hàng tạo yêu cầu hoàn hàng. Hoa hồng được thu hồi tạm thời.');
            $commissionService->syncOrderLifecycle($commissionOrder, 'Khách hàng tạo yêu cầu hoàn hàng.');

            $this->recordOrderHistory($order, 'return_requested', $user, [
                'order_return_id' => (string) $return->id,
                'return_code' => $return->return_code,
            ]);

            $this->createStoreAdminReturnRequestNotification($return);
            $this->broadcastReturnUpdated($return, 'created');

            return $return->load(['items.orderItem', 'order']);
        });
    }

    public function createComplaintReturn(
        Complaint $complaint,
        User $actor,
        string $status = OrderReturn::STATUS_PENDING
    ): OrderReturn {
        return DB::transaction(function () use ($complaint, $actor, $status): OrderReturn {
            $complaint = Complaint::query()->whereKey((string) $complaint->id)->lockForUpdate()->firstOrFail();
            $existing = Schema::hasColumn('order_returns', 'complaint_id')
                ? OrderReturn::query()->where('complaint_id', (string) $complaint->id)->lockForUpdate()->first()
                : null;
            if ($existing) {
                return $existing->load(['items.orderItem', 'order']);
            }

            $order = Order::query()->whereKey((string) $complaint->order_id)->lockForUpdate()->firstOrFail();
            $items = $order->items()->lockForUpdate()->get();
            $itemIds = $items->map(fn (OrderItem $item): string => (string) $item->id)->all();
            $heldQuantities = OrderReturnItem::query()
                ->select('order_item_id', DB::raw('SUM(quantity) as quantity'))
                ->whereIn('order_item_id', $itemIds)
                ->whereHas('orderReturn', fn ($query) => $query->whereIn('status', OrderReturn::OPEN_QUANTITY_STATUSES))
                ->groupBy('order_item_id')
                ->pluck('quantity', 'order_item_id');

            $requestedItems = $items->map(function (OrderItem $item) use ($heldQuantities): ?array {
                $remaining = max(0, (int) ($item->quantity ?? 0) - (int) ($heldQuantities[(string) $item->id] ?? 0));

                return $remaining > 0 ? [
                    'order_item_id' => (string) $item->id,
                    'quantity' => $remaining,
                ] : null;
            })->filter()->values()->all();

            if ($requestedItems === []) {
                throw new RuntimeException('COMPLAINT_ORDER_NOT_REFUNDABLE');
            }

            $calculation = $this->amountCalculator->calculate($order, $requestedItems, $items);
            if ((float) $calculation['total'] <= 0) {
                throw new RuntimeException('REFUND_AMOUNT_NOT_POSITIVE');
            }

            $payload = [
                'return_code' => $this->generateReturnCode(),
                'order_id' => (string) $order->id,
                'user_id' => (string) $complaint->user_id,
                'store_id' => (string) $order->store_id,
                'status' => $status,
                'return_type' => 'refund',
                'refund_method' => 'money_wallet',
                'return_method' => 'shipping',
                'reason_code' => 'COMPLAINT_RESOLUTION',
                'reason' => (string) ($complaint->description ?: 'Complaint resolution'),
                'images' => $complaint->images ?? [],
                'refund_amount_estimated' => $calculation['total'],
                'refund_currency' => 'VND',
                'auto_approve_at' => now()->addHours(48),
            ];
            $refundBankInfo = $this->normalizeRefundBankInfo($complaint->refund_bank_info ?? null);
            if ($refundBankInfo !== null) {
                $payload['refund_bank_info'] = $refundBankInfo;
            }
            if (Schema::hasColumn('order_returns', 'complaint_id')) {
                $payload['complaint_id'] = (string) $complaint->id;
            }

            $return = OrderReturn::query()->create($payload);
            foreach ($calculation['items'] as $row) {
                OrderReturnItem::query()->create(array_merge($row, [
                    'order_return_id' => (string) $return->id,
                ]));
            }

            $this->recordOrderHistory($order, 'complaint_return_requested', $actor, [
                'complaint_id' => (string) $complaint->id,
                'order_return_id' => (string) $return->id,
            ]);
            if ($status === OrderReturn::STATUS_PENDING) {
                $this->createStoreAdminReturnRequestNotification($return);
            }
            $this->broadcastReturnUpdated($return, 'complaint_created');

            return $return->load(['items.orderItem', 'order']);
        });
    }

    public function approveReturn(
        User $storeUser,
        OrderReturn $return,
        ?string $trackingCode = null,
        ?string $carrierName = null,
        ?string $storeId = null,
        ?string $returnMethod = null,
        ?string $refundSettlementMethod = null
    ): OrderReturn {
        return DB::transaction(function () use ($storeUser, $return, $trackingCode, $carrierName, $storeId, $returnMethod, $refundSettlementMethod): OrderReturn {
            $return = $this->lockReturn($return);
            $this->assertStoreOwnsReturn($storeId, $return);
            $this->assertStatus($return, [OrderReturn::STATUS_PENDING]);

            $customerReturnMethod = in_array((string) $return->return_method, ['at_store', 'shipping'], true)
                ? (string) $return->return_method
                : 'shipping';
            $settlementMethod = $refundSettlementMethod
                ?? ($returnMethod === 'at_store' ? 'manual_at_store' : 'wallet');

            if (! in_array($settlementMethod, ['wallet', 'manual_at_store'], true)) {
                throw new RuntimeException('INVALID_REFUND_SETTLEMENT_METHOD');
            }

            if ($settlementMethod === 'manual_at_store' && $customerReturnMethod !== 'at_store') {
                throw new RuntimeException('RETURN_SETTLEMENT_METHOD_NOT_ALLOWED');
            }

            $cleanTrackingCode = trim((string) ($trackingCode ?? ''));
            $cleanCarrierName = trim((string) ($carrierName ?? ''));

            $updates = [
                'status' => OrderReturn::STATUS_APPROVED,
                'return_method' => $customerReturnMethod,
                'refund_funding_source' => $settlementMethod === 'manual_at_store' ? 'manual_at_store' : null,
                'tracking_code' => $customerReturnMethod === 'shipping' && $cleanTrackingCode !== '' ? $cleanTrackingCode : null,
                'carrier_name' => $customerReturnMethod === 'shipping' && $cleanCarrierName !== '' ? $cleanCarrierName : null,
            ];

            $return->forceFill($updates)->save();

            $this->recordOrderHistory($return->order, 'return_approved', $storeUser, [
                'order_return_id' => (string) $return->id,
                'return_method' => $customerReturnMethod,
                'refund_settlement_method' => $settlementMethod,
                'tracking_code' => $updates['tracking_code'],
                'carrier_name' => $updates['carrier_name'],
            ]);

            $orderCode = $return->order?->order_code ?? '';
            $orderIdentifier = $orderCode !== '' ? '#' . $orderCode : 'của bạn';
            $this->sendCustomerReturnNotification(
                $return,
                'order',
                'Yêu cầu hoàn hàng được chấp nhận',
                "Yêu cầu hoàn hàng cho đơn hàng {$orderIdentifier} đã được chấp nhận."
            );

            $this->broadcastReturnUpdated($return, 'approved');
            $this->pushCustomerReturnNotification($return, 'return_approved');

            if ($return->complaint_id) {
                app(\App\Services\ComplaintResolutionService::class)->handleStoreReturnResponse($return, $storeUser, 'approved');
            }

            return $return->fresh(['items.orderItem', 'order']);
        });
    }

    public function rejectReturn(User $storeUser, OrderReturn $return, string $reason, ?string $storeId = null): OrderReturn
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('REJECTION_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($storeUser, $return, $reason, $storeId): OrderReturn {
            $return = $this->lockReturn($return);
            $this->assertStoreOwnsReturn($storeId, $return);
            $this->assertStatus($return, [OrderReturn::STATUS_PENDING]);
            $return->forceFill([
                'status' => OrderReturn::STATUS_REJECTED,
                'rejection_reason' => $reason,
            ])->save();

            // Release tickets
            $this->releaseTicketsForReturn($return);

            $commissionOrder = clone $return->order;
            $commissionService = app(\App\Services\AffiliateCommissionService::class);
            $commissionService->restoreLegacyCancelledCommissions($commissionOrder, 'Yêu cầu hoàn hàng bị từ chối, khôi phục hoa hồng F0.');
            $commissionService->syncOrderLifecycle($commissionOrder, 'Yêu cầu hoàn hàng bị từ chối.');

            $this->recordOrderHistory($return->order, 'return_rejected', $storeUser, ['order_return_id' => (string) $return->id]);

            $orderCode = $return->order?->order_code ?? '';
            $orderIdentifier = $orderCode !== '' ? '#' . $orderCode : 'của bạn';
            $this->sendCustomerReturnNotification(
                $return,
                'order',
                'Yêu cầu hoàn hàng bị từ chối',
                "Yêu cầu hoàn hàng cho đơn hàng {$orderIdentifier} đã bị từ chối. Lý do: {$reason}"
            );

            $this->broadcastReturnUpdated($return, 'rejected');
            $this->pushCustomerReturnNotification($return, 'return_rejected', $reason);

            if ($return->complaint_id) {
                app(\App\Services\ComplaintResolutionService::class)->handleStoreReturnResponse($return, $storeUser, 'rejected', $reason);
            }

            return $return->fresh(['items.orderItem', 'order']);
        });
    }

    public function shipReturn(User $user, OrderReturn $return, array $payload): OrderReturn
    {
        return DB::transaction(function () use ($user, $return, $payload): OrderReturn {
            $return = $this->lockReturn($return);
            $this->assertCustomerOwnsReturn($user, $return);
            $this->assertStatus($return, [OrderReturn::STATUS_APPROVED]);
            if (($return->return_method ?? 'shipping') !== 'shipping') {
                throw new RuntimeException('RETURN_METHOD_SHIPPING_NOT_ALLOWED');
            }
            $shippingReceiptImages = $payload['shipping_receipt_images'] ?? [];
            if (! is_array($shippingReceiptImages) || $shippingReceiptImages === []) {
                throw new RuntimeException('SHIPPING_RECEIPT_IMAGES_REQUIRED');
            }

            $return->forceFill([
                'status' => OrderReturn::STATUS_RETURNING,
                'tracking_code' => (string) ($payload['tracking_code'] ?? ''),
                'carrier_name' => $payload['carrier_name'] ?? null,
                'shipping_receipt_images' => array_values($shippingReceiptImages),
            ])->save();
            $this->recordOrderHistory($return->order, 'return_shipped', $user, ['order_return_id' => (string) $return->id]);
            $this->broadcastReturnUpdated($return, 'shipped');

            return $return->fresh(['items.orderItem', 'order']);
        });
    }

    public function markAtStoreReturnArrived(User $user, OrderReturn $return): OrderReturn
    {
        return DB::transaction(function () use ($user, $return): OrderReturn {
            $return = $this->lockReturn($return);
            $this->assertCustomerOwnsReturn($user, $return);

            if (($return->return_method ?? 'shipping') !== 'at_store') {
                throw new RuntimeException('RETURN_METHOD_AT_STORE_NOT_ALLOWED');
            }

            if ($return->status === OrderReturn::STATUS_RETURNING) {
                return $return->fresh(['items.orderItem', 'order']);
            }

            $this->assertStatus($return, [OrderReturn::STATUS_APPROVED]);

            $return->forceFill([
                'status' => OrderReturn::STATUS_RETURNING,
            ])->save();

            $this->recordOrderHistory($return->order, 'return_arrived_at_store', $user, [
                'order_return_id' => (string) $return->id,
            ]);
            $this->broadcastReturnUpdated($return, 'arrived_at_store');

            return $return->fresh(['items.orderItem', 'order']);
        });
    }

    public function receiveReturn(User $storeUser, OrderReturn $return, bool $isSatisfied, array $payload, ?string $storeId = null): OrderReturn
    {
        return DB::transaction(function () use ($storeUser, $return, $isSatisfied, $payload, $storeId): OrderReturn {
            $return = $this->lockReturn($return);
            $this->assertStoreOwnsReturn($storeId, $return);
            $this->assertStatus($return, [OrderReturn::STATUS_RETURNING]);
            if (! $isSatisfied && empty($payload['images']) && empty($payload['video'])) {
                throw new RuntimeException('SHOP_RECEIVE_DISPUTE_EVIDENCE_REQUIRED');
            }
            $return->forceFill([
                'status' => $isSatisfied ? OrderReturn::STATUS_RECEIVED : OrderReturn::STATUS_DISPUTED,
                'shop_received_images' => $payload['images'] ?? [],
                'shop_received_video' => $payload['video'] ?? null,
                'shop_received_note' => $payload['shop_received_note'] ?? null,
            ])->save();
            $this->recordOrderHistory($return->order, $isSatisfied ? 'return_received' : 'return_disputed', $storeUser, [
                'order_return_id' => (string) $return->id,
            ]);

            if ($isSatisfied) {
                return $this->processRefund($return->fresh(), $storeUser);
            }

            $orderCode = $return->order?->order_code ?? '';
            $orderIdentifier = $orderCode !== '' ? '#' . $orderCode : 'của bạn';
            $this->sendCustomerReturnNotification(
                $return,
                'order',
                'Yêu cầu hoàn hàng bị tranh chấp',
                "Yêu cầu hoàn hàng cho đơn hàng {$orderIdentifier} đang bị tranh chấp bởi cửa hàng."
            );

            $this->broadcastReturnUpdated($return, 'disputed');

            return $return->fresh(['items.orderItem', 'order']);
        });
    }

    public function disputeReturn(User $user, OrderReturn $return, array $payload): OrderReturn
    {
        return DB::transaction(function () use ($user, $return, $payload): OrderReturn {
            $return = $this->lockReturn($return);
            $this->assertCustomerOwnsReturn($user, $return);
            if ($return->status === OrderReturn::STATUS_PLATFORM_REVIEWING) {
                return $return->fresh(['items.orderItem', 'order']);
            }
            $this->assertStatus($return, [OrderReturn::STATUS_REJECTED, OrderReturn::STATUS_DISPUTED]);
            $this->assertReturnQuantitiesStillAvailable($return);
            $return->forceFill([
                'status' => OrderReturn::STATUS_PLATFORM_REVIEWING,
                'dispute_reason' => (string) ($payload['dispute_reason'] ?? ''),
                'dispute_evidence_images' => $payload['evidence_images'] ?? [],
            ])->save();

            // Re-lock tickets if dispute is filed
            $this->lockTicketsForReturn($return);

            $this->recordOrderHistory($return->order, 'return_platform_reviewing', $user, ['order_return_id' => (string) $return->id]);
            $this->broadcastReturnUpdated($return, 'platform_reviewing');

            return $return->fresh(['items.orderItem', 'order']);
        });
    }

    public function resolveDispute(User $admin, OrderReturn $return, array $payload): OrderReturn
    {
        return DB::transaction(function () use ($admin, $return, $payload): OrderReturn {
            $return = $this->lockReturn($return);
            $this->assertStatus($return, [OrderReturn::STATUS_PLATFORM_REVIEWING, OrderReturn::STATUS_DISPUTED]);
            $resolution = (string) ($payload['resolution'] ?? '');
            if (! in_array($resolution, ['approve_refund', 'reject_refund'], true)) {
                throw new RuntimeException('INVALID_ADMIN_RESOLUTION');
            }

            $return->forceFill([
                'admin_resolution' => $resolution,
                'admin_resolution_note' => $payload['admin_resolution_note'] ?? null,
                'status' => $resolution === 'approve_refund'
                    ? OrderReturn::STATUS_REFUND_PROCESSING
                    : OrderReturn::STATUS_CLOSED,
            ])->save();

            if ($resolution === 'approve_refund') {
                return $this->processRefund($return->fresh(), $admin);
            }

            // Release tickets if rejected/closed
            $this->releaseTicketsForReturn($return);

            $commissionOrder = clone $return->order;
            $commissionService = app(\App\Services\AffiliateCommissionService::class);
            $commissionService->restoreLegacyCancelledCommissions($commissionOrder, 'Yêu cầu hoàn hàng (tranh chấp) bị từ chối, khôi phục hoa hồng F0.');
            $commissionService->syncOrderLifecycle($commissionOrder, 'Yêu cầu hoàn hàng (tranh chấp) bị từ chối.');

            $this->recordOrderHistory($return->order, 'return_closed', $admin, ['order_return_id' => (string) $return->id]);

            if ($resolution === 'reject_refund') {
                $orderCode = $return->order?->order_code ?? '';
                $orderIdentifier = $orderCode !== '' ? '#' . $orderCode : 'của bạn';
                $this->sendCustomerReturnNotification(
                    $return,
                    'order',
                    'Khiếu nại hoàn hàng bị từ chối',
                    "Khiếu nại hoàn hàng cho đơn hàng {$orderIdentifier} đã bị từ chối bởi hệ thống."
                );
            }

            $this->broadcastReturnUpdated($return, 'closed');

            return $return->fresh(['items.orderItem', 'order']);
        });
    }

    public function processRefund(OrderReturn $return, mixed $actor = null, bool $allowComplaintOverride = false): OrderReturn
    {
        return DB::transaction(function () use ($return, $actor, $allowComplaintOverride): OrderReturn {
            $return = $this->lockReturn($return);
            if ($return->status === OrderReturn::STATUS_REFUNDED || $return->refund_processed_at !== null) {
                $refundedReturn = $return->fresh(['items.orderItem', 'order']);
                $this->syncAffiliateCommissionsAfterRefund($refundedReturn, $actor);
                $this->refundStoreTicketFeeOrFail($refundedReturn, $actor);
                return $return->fresh(['items.orderItem', 'order']);
            }

            $this->assertStatus($return, [
                OrderReturn::STATUS_RECEIVED,
                OrderReturn::STATUS_REFUND_PROCESSING,
            ]);

            $this->assertReturnQuantitiesStillAvailable($return, $allowComplaintOverride);
            $amount = $this->recalculateRefundAmount($return);
            if ($amount <= 0) {
                throw new RuntimeException('REFUND_AMOUNT_NOT_POSITIVE');
            }

            $isManualAtStore = $return->return_method === 'at_store'
                && $return->refund_funding_source === 'manual_at_store';

            if (! $isManualAtStore) {
                $idempotencyKey = 'refund:order_return:'.(string) $return->id.':customer';
                $ledger = $this->moneyWalletService->creditRefund($return->user, $return, $amount, $idempotencyKey, $actor);
                $funding = $this->fundingResolver->applyFundingDecision($return, $amount, $actor);

                $return->forceFill([
                    'status' => OrderReturn::STATUS_REFUNDED,
                    'refund_amount_final' => $amount,
                    'refund_funding_source' => $funding['funding_source'],
                    'funding_breakdown' => $funding['funding_breakdown'] ?? null,
                    'platform_advanced_amount' => $funding['platform_advance'],
                    'refund_idempotency_key' => 'refund:order_return:'.(string) $return->id,
                    'customer_wallet_ledger_id' => (string) $ledger->id,
                    'store_liability_ledger_id' => $funding['liability_ledger_id'],
                    'refund_processed_at' => now(),
                    'refund_processed_by' => $this->actorId($actor),
                ])->save();
            } else {
                $return->forceFill([
                    'status' => OrderReturn::STATUS_REFUNDED,
                    'refund_amount_final' => $amount,
                    'refund_funding_source' => 'manual_at_store',
                    'refund_processed_at' => now(),
                    'refund_processed_by' => $this->actorId($actor),
                ])->save();
            }

            $this->syncAffiliateCommissionsAfterRefund($return->fresh(['items.orderItem', 'order']), $actor);

            // Revoke tickets permanently
            $this->revokeCustomerTicketsForReturn($return, $actor);

            // Hoàn phí vé (ticket fee) lại cho cửa hàng (trừ nợ trước, cộng ví sau)
            $storeTicketFeeRefund = $this->refundStoreTicketFeeOrFail($return->fresh(['items.orderItem', 'order']), $actor);

            $this->markOrderRefundedIfFullyReturned($return->fresh(['items']), $actor);
            $this->recordOrderHistory($return->order, 'return_refunded', $actor, [
                'order_return_id' => (string) $return->id,
                'amount' => $amount,
                'funding_source' => $isManualAtStore ? 'manual_at_store' : $funding['funding_source'],
                'store_ticket_fee_refund' => $storeTicketFeeRefund,
            ]);

            $orderCode = $return->order?->order_code ?? '';
            $orderIdentifier = $orderCode !== '' ? '#' . $orderCode : 'của bạn';
            $this->sendCustomerReturnNotification(
                $return,
                'order',
                'Hoàn tiền thành công',
                "Yêu cầu hoàn hàng cho đơn hàng {$orderIdentifier} đã được hoàn tiền thành công."
            );

            $this->broadcastReturnUpdated($return, 'refunded');

            return $return->fresh(['items.orderItem', 'order']);
        });
    }

    private function syncAffiliateCommissionsAfterRefund(OrderReturn $return, mixed $actor = null): void
    {
        $order = $return->order;
        if (! $order) {
            return;
        }

        $reason = 'Đơn hoàn hàng đã hoàn tiền thành công.';
        $commissionService = app(\App\Services\AffiliateCommissionService::class);
        $commissionService->reverseLegacyPaidCommissions($order, $reason);
        $this->refundStoreAffiliateCommissionsForReturn($return, $actor, $reason);
        $commissionService->syncOrderLifecycle($order, $reason);
    }

    private function refundStoreAffiliateCommissionsForReturn(OrderReturn $return, mixed $actor = null, string $reason = ''): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('affiliate_commissions')) {
            return;
        }

        $return->loadMissing(['items', 'order']);
        $itemIds = $return->items
            ->pluck('order_item_id')
            ->map(fn ($id): string => (string) $id)
            ->filter()
            ->values()
            ->all();

        if (empty($itemIds)) {
            return;
        }

        $commissions = \App\Models\AffiliateCommission::query()
            ->where('order_id', (string) $return->order_id)
            ->whereIn('order_item_id', $itemIds)
            ->get();

        if ($commissions->isEmpty()) {
            return;
        }

        $storeCreditService = app(\App\Services\StoreCreditService::class);
        foreach ($commissions as $commission) {
            $storeCreditService->refundAffiliateCommissionForCommission(
                $commission->fresh(['order', 'orderItem']) ?? $commission,
                null,
                $actor,
                $return,
                $reason,
            );
        }
    }

    private function refundStoreTicketFeeOrFail(OrderReturn $return, mixed $actor = null): array
    {
        $storeCreditService = app(\App\Services\StoreCreditService::class);
        $ticketFeeRefund = $storeCreditService->calculateReturnTicketFee($return);
        if ($ticketFeeRefund <= 0) {
            return [
                'refunded' => false,
                'ticket_fee_refund' => 0.0,
                'debt_repayment' => 0.0,
                'wallet_credit' => 0.0,
                'reason' => 'no_ticket_fee',
            ];
        }

        $result = $storeCreditService->refundReturnTicketFee($return, $ticketFeeRefund, $actor);
        if (! (bool) ($result['refunded'] ?? false)) {
            throw new RuntimeException((string) ($result['reason'] ?? 'RETURN_TICKET_FEE_REFUND_FAILED'));
        }

        return [
            'refunded' => true,
            'idempotent' => (bool) ($result['idempotent'] ?? false),
            'ticket_fee_refund' => $ticketFeeRefund,
            'debt_repayment' => (float) ($result['debt_repayment'] ?? 0),
            'wallet_credit' => (float) ($result['wallet_credit'] ?? 0),
        ];
    }

    public function autoApprovePendingReturns(): int
    {
        $ids = OrderReturn::query()
            ->where('status', OrderReturn::STATUS_PENDING)
            ->whereNotNull('auto_approve_at')
            ->where('auto_approve_at', '<=', now())
            ->orderBy('auto_approve_at')
            ->limit(200)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $count = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count): void {
                $return = OrderReturn::query()->whereKey($id)->lockForUpdate()->first();
                if (! $return || $return->status !== OrderReturn::STATUS_PENDING) {
                    return;
                }

                $return->forceFill(['status' => OrderReturn::STATUS_APPROVED])->save();
                $this->recordOrderHistory($return->order, 'return_auto_approved', 'system', [
                    'order_return_id' => (string) $return->id,
                ]);

                $orderCode = $return->order?->order_code ?? '';
                $orderIdentifier = $orderCode !== '' ? '#' . $orderCode : 'của bạn';
                $this->sendCustomerReturnNotification(
                    $return,
                    'order',
                    'Yêu cầu hoàn hàng được chấp nhận',
                    "Yêu cầu hoàn hàng cho đơn hàng {$orderIdentifier} đã được tự động chấp nhận."
                );

                $this->broadcastReturnUpdated($return, 'auto_approved');
                $count++;
            });
        }

        return $count;
    }

    public function autoCloseExpiredCustomerDisputes(): int
    {
        $ids = OrderReturn::query()
            ->whereIn('status', [OrderReturn::STATUS_REJECTED, OrderReturn::STATUS_DISPUTED])
            ->where('updated_at', '<=', now()->subHours(72))
            ->orderBy('updated_at')
            ->limit(200)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $count = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count): void {
                $return = OrderReturn::query()->whereKey($id)->lockForUpdate()->first();
                if (! $return || ! in_array($return->status, [OrderReturn::STATUS_REJECTED, OrderReturn::STATUS_DISPUTED], true)) {
                    return;
                }

                $return->forceFill(['status' => OrderReturn::STATUS_CLOSED])->save();

                // Release tickets
                $this->releaseTicketsForReturn($return);

                $commissionOrder = clone $return->order;
                $commissionService = app(\App\Services\AffiliateCommissionService::class);
                $commissionService->restoreLegacyCancelledCommissions($commissionOrder, 'Yêu cầu hoàn hàng bị tự động đóng, khôi phục hoa hồng F0.');
                $commissionService->syncOrderLifecycle($commissionOrder, 'Yêu cầu hoàn hàng tự động đóng.');

                $this->recordOrderHistory($return->order, 'return_auto_closed', 'system', [
                    'order_return_id' => (string) $return->id,
                    'reason' => 'customer_dispute_sla_expired_72h',
                ]);
                $this->broadcastReturnUpdated($return, 'closed');
                $count++;
            });
        }

        return $count;
    }

    public function createWithdrawal(User $user, array $payload): MoneyWalletWithdrawalRequest
    {
        return DB::transaction(function () use ($user, $payload): MoneyWalletWithdrawalRequest {
            $rawAmount = $payload['amount'] ?? null;
            if (! is_int($rawAmount) && ! is_float($rawAmount) && ! is_string($rawAmount)) {
                throw new RuntimeException('WITHDRAWAL_AMOUNT_INVALID');
            }

            $amount = $this->money($rawAmount);
            if ($amount <= 0 || $amount > self::MAX_WITHDRAWAL_AMOUNT) {
                throw new RuntimeException('WITHDRAWAL_AMOUNT_INVALID');
            }

            $bankId = $this->normalizeWithdrawalField($payload['bank_id'] ?? null, 'WITHDRAWAL_BANK_ID_INVALID', 64, false, '/\A[A-Za-z0-9._-]+\z/');
            $bankCode = $this->normalizeWithdrawalField($payload['bank_code'] ?? null, 'WITHDRAWAL_BANK_CODE_INVALID', 30, false, '/\A[A-Za-z0-9._-]+\z/');
            $bankName = $this->normalizeWithdrawalField($payload['bank_name'] ?? null, 'WITHDRAWAL_BANK_NAME_INVALID', 255);
            $accountNumber = $this->normalizeWithdrawalField($payload['account_number'] ?? null, 'WITHDRAWAL_ACCOUNT_NUMBER_INVALID', 50, true, '/\A[A-Za-z0-9._-]+\z/');
            $accountName = $this->normalizeWithdrawalField($payload['account_name'] ?? null, 'WITHDRAWAL_ACCOUNT_NAME_INVALID', 255, true);
            $providedIdempotencyKey = $this->normalizeWithdrawalIdempotencyKey($payload['idempotency_key'] ?? null);

            if ($providedIdempotencyKey !== null) {
                $idempotencyKey = $providedIdempotencyKey;
                $existing = MoneyWalletWithdrawalRequest::query()
                    ->where('user_id', (string) $user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing->fresh() ?? $existing;
                }
            } else {
                $existing = MoneyWalletWithdrawalRequest::query()
                    ->where('user_id', (string) $user->id)
                    ->whereIn('status', ['pending', 'approved'])
                    ->where('amount', $amount)
                    ->where('account_number', $accountNumber)
                    ->where('account_name', $accountName)
                    ->where(function ($query) use ($bankId): void {
                        $bankId === null ? $query->whereNull('bank_id') : $query->where('bank_id', $bankId);
                    })
                    ->where(function ($query) use ($bankCode): void {
                        $bankCode === null ? $query->whereNull('bank_code') : $query->where('bank_code', $bankCode);
                    })
                    ->where(function ($query) use ($bankName): void {
                        $bankName === null ? $query->whereNull('bank_name') : $query->where('bank_name', $bankName);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing->fresh() ?? $existing;
                }

                $idempotencyKey = $this->autoWithdrawalIdempotencyKey(
                    $user,
                    $amount,
                    $bankId,
                    $bankCode,
                    $accountNumber,
                    $accountName
                );
            }

            $account = $this->moneyWalletService->accountFor('user', (string) $user->id, 'VND');
            try {
                $request = MoneyWalletWithdrawalRequest::query()->create([
                    'user_id' => (string) $user->id,
                    'wallet_account_id' => (string) $account->id,
                    'amount' => $amount,
                    'status' => 'pending',
                    'bank_id' => $bankId,
                    'bank_code' => $bankCode,
                    'bank_name' => $bankName,
                    'account_number' => $accountNumber,
                    'account_name' => $accountName,
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (QueryException $exception) {
                $request = MoneyWalletWithdrawalRequest::query()
                    ->where('user_id', (string) $user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if (! $request) {
                    throw $exception;
                }

                return $request->fresh() ?? $request;
            }

            $ledger = $this->moneyWalletService->lockWithdrawal($user, $request);
            $request->forceFill(['locked_ledger_id' => (string) $ledger->id])->save();

            return $request->fresh();
        });
    }

    public function markWithdrawalPaid(MoneyWalletWithdrawalRequest $request, User $admin): MoneyWalletWithdrawalRequest
    {
        $this->moneyWalletService->markWithdrawalPaid($request, $admin);

        return MoneyWalletWithdrawalRequest::query()
            ->whereKey((string) $request->id)
            ->firstOrFail();
    }

    public function cancelWithdrawal(MoneyWalletWithdrawalRequest $request, User $actor, string $status = 'cancelled', ?string $reason = null): MoneyWalletWithdrawalRequest
    {
        $this->moneyWalletService->cancelWithdrawal($request, $actor, $status, $reason);

        return MoneyWalletWithdrawalRequest::query()
            ->whereKey((string) $request->id)
            ->firstOrFail();
    }

    private function assertItemsReturnable(Order $order, Collection $orderItems, array $requestedItems): void
    {
        foreach ($requestedItems as $requestedItem) {
            $itemId = (string) $requestedItem['order_item_id'];
            $item = $orderItems->get($itemId);
            if (! $item instanceof OrderItem) {
                throw new RuntimeException('RETURN_ITEM_NOT_IN_ORDER');
            }

            if ($item->return_blocked_at !== null) {
                throw new RuntimeException('PRODUCT_RETURN_FORFEITED_BY_TICKET_USE');
            }

            $available = $this->availableQuantity($item);
            if ((int) $requestedItem['quantity'] > $available) {
                throw new RuntimeException('RETURN_QUANTITY_EXCEEDED');
            }
        }
    }

    private function normalizeRequestedItems(array $items): array
    {
        return collect($items)
            ->map(fn (array $item): array => [
                'order_item_id' => (string) ($item['order_item_id'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 0),
            ])
            ->filter(fn (array $item): bool => $item['order_item_id'] !== '' && $item['quantity'] > 0)
            ->groupBy('order_item_id')
            ->map(fn (Collection $rows, string $itemId): array => [
                'order_item_id' => $itemId,
                'quantity' => (int) $rows->sum('quantity'),
            ])
            ->values()
            ->all();
    }

    private function availableQuantity(OrderItem $item): int
    {
        $orderedQuantity = max(0, (int) ($item->quantity ?? 0));
        $alreadyOpen = (int) OrderReturnItem::query()
            ->where('order_item_id', (string) $item->id)
            ->whereHas('orderReturn', fn ($query) => $query->whereIn('status', OrderReturn::OPEN_QUANTITY_STATUSES))
            ->sum('quantity');

        return max(0, $orderedQuantity - $alreadyOpen);
    }

    private function assertReturnQuantitiesStillAvailable(OrderReturn $return, bool $allowComplaintOverride = false): void
    {
        $returnItems = $return->items()->lockForUpdate()->get();
        if ($returnItems->isEmpty()) {
            throw new RuntimeException('RETURN_ITEMS_REQUIRED');
        }

        $currentQuantities = $returnItems
            ->groupBy(fn (OrderReturnItem $item): string => (string) $item->order_item_id)
            ->map(fn (Collection $items): int => (int) $items->sum('quantity'));

        $itemIds = $currentQuantities->keys()->values()->all();
        $orderItems = OrderItem::query()
            ->where('order_id', (string) $return->order_id)
            ->whereIn('id', $itemIds)
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (OrderItem $item): string => (string) $item->id);

        if ($orderItems->count() !== count($itemIds)) {
            throw new RuntimeException('RETURN_ITEM_NOT_IN_ORDER');
        }

        $heldByOtherReturns = OrderReturnItem::query()
            ->select('order_item_id', DB::raw('SUM(quantity) as quantity'))
            ->whereIn('order_item_id', $itemIds)
            ->where('order_return_id', '<>', (string) $return->id)
            ->whereHas('orderReturn', fn ($query) => $query->whereIn('status', OrderReturn::OPEN_QUANTITY_STATUSES))
            ->groupBy('order_item_id')
            ->pluck('quantity', 'order_item_id');

        foreach ($currentQuantities as $itemId => $quantity) {
            $item = $orderItems->get((string) $itemId);
            if (! $allowComplaintOverride && $item instanceof OrderItem && $item->return_blocked_at !== null) {
                throw new RuntimeException('PRODUCT_RETURN_FORFEITED_BY_TICKET_USE');
            }

            $orderedQuantity = max(0, (int) ($item?->quantity ?? 0));
            $heldQuantity = (int) ($heldByOtherReturns[(string) $itemId] ?? 0);
            $available = max(0, $orderedQuantity - $heldQuantity);

            if ((int) $quantity > $available) {
                throw new RuntimeException('RETURN_QUANTITY_EXCEEDED');
            }
        }
    }

    private function markOrderRefundedIfFullyReturned(OrderReturn $return, mixed $actor = null): void
    {
        $order = Order::query()
            ->whereKey((string) $return->order_id)
            ->lockForUpdate()
            ->first();

        if (! $order || strtolower((string) $order->status) === 'refunded') {
            return;
        }

        if (! $this->isOrderFullyRefunded($order)) {
            return;
        }

        $this->recordOrderHistory($order, 'refunded', $actor, [
            'order_return_id' => (string) $return->id,
            'return_code' => $return->return_code,
            'reason' => 'full_order_return_refunded',
        ]);
        $order->forceFill(['status' => 'refunded'])->save();
    }

    private function isOrderFullyRefunded(Order $order): bool
    {
        $items = $order->items()->get(['id', 'quantity']);
        if ($items->isEmpty()) {
            return false;
        }

        $itemIds = $items->map(fn (OrderItem $item): string => (string) $item->id)->values()->all();
        $refundedQuantities = OrderReturnItem::query()
            ->select('order_item_id', DB::raw('SUM(quantity) as refunded_quantity'))
            ->whereIn('order_item_id', $itemIds)
            ->whereHas('orderReturn', function ($query) use ($order): void {
                $query->where('order_id', (string) $order->id)
                    ->where('status', OrderReturn::STATUS_REFUNDED);
            })
            ->groupBy('order_item_id')
            ->pluck('refunded_quantity', 'order_item_id');

        foreach ($items as $item) {
            $orderedQuantity = max(0, (int) ($item->quantity ?? 0));
            if ($orderedQuantity <= 0) {
                continue;
            }

            if ((int) ($refundedQuantities[(string) $item->id] ?? 0) < $orderedQuantity) {
                return false;
            }
        }

        return true;
    }

    private function lockReturn(OrderReturn $return): OrderReturn
    {
        return OrderReturn::query()
            ->with(['order', 'user', 'items'])
            ->whereKey((string) $return->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertCustomerOwnsOrder(User $user, Order $order): void
    {
        if ((string) ($order->user_id ?? '') !== (string) $user->id) {
            throw new RuntimeException('ORDER_NOT_OWNED_BY_USER');
        }
    }

    private function assertCustomerOwnsReturn(User $user, OrderReturn $return): void
    {
        if ((string) ($return->user_id ?? '') !== (string) $user->id) {
            throw new RuntimeException('RETURN_NOT_OWNED_BY_USER');
        }
    }

    private function assertStoreOwnsReturn(?string $storeId, OrderReturn $return): void
    {
        if ($storeId !== null && (string) $return->store_id !== (string) $storeId) {
            throw new RuntimeException('RETURN_STORE_FORBIDDEN');
        }
    }

    private function assertOrderReturnable(Order $order): void
    {
        $status = strtolower(trim((string) ($order->status ?? '')));
        if (! in_array($status, ['delivered', 'completed'], true)) {
            throw new RuntimeException('ORDER_STATUS_NOT_RETURNABLE');
        }

        if (! $this->eligibilityService->isWithinReturnWindow($order)) {
            throw new RuntimeException('RETURN_WINDOW_EXPIRED');
        }
    }

    private function assertSupportedRefundPayload(array $payload): void
    {
        $returnType = (string) ($payload['return_type'] ?? 'refund');
        $refundMethod = (string) ($payload['refund_method'] ?? 'money_wallet');

        if ($returnType !== 'refund') {
            throw new RuntimeException('UNSUPPORTED_RETURN_TYPE');
        }

        if ($refundMethod !== 'money_wallet') {
            throw new RuntimeException('UNSUPPORTED_REFUND_METHOD');
        }
    }

    private function normalizeRefundBankInfo(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $bankName = trim((string) ($value['bank_name'] ?? ''));
        $accountName = trim((string) ($value['account_name'] ?? ''));
        $accountNumber = trim((string) ($value['account_number'] ?? ''));

        if ($bankName === '' || $accountName === '' || $accountNumber === '') {
            return null;
        }

        return [
            'bank_name' => mb_substr($bankName, 0, 255),
            'account_name' => mb_substr($accountName, 0, 255),
            'account_number' => mb_substr($accountNumber, 0, 255),
        ];
    }

    private function assertEvidenceForHighRiskReason(array $payload): void
    {
        $reasonCode = strtoupper(trim((string) ($payload['reason_code'] ?? '')));
        if (! in_array($reasonCode, self::EVIDENCE_REQUIRED_REASON_CODES, true)) {
            return;
        }

        if (! empty($payload['images']) || ! empty($payload['video'])) {
            return;
        }

        throw new RuntimeException('RETURN_EVIDENCE_REQUIRED');
    }

    private function assertStatus(OrderReturn $return, array $allowedStatuses): void
    {
        if (! in_array((string) $return->status, $allowedStatuses, true)) {
            throw new RuntimeException('INVALID_RETURN_STATE');
        }
    }

    private function recalculateRefundAmount(OrderReturn $return): float
    {
        $order = Order::query()
            ->whereKey((string) $return->order_id)
            ->lockForUpdate()
            ->firstOrFail();
        $orderItems = $order->items()->lockForUpdate()->get();
        $returnItems = $return->items()->lockForUpdate()->get();
        $requestedItems = $returnItems
            ->map(fn (OrderReturnItem $item): array => [
                'order_item_id' => (string) $item->order_item_id,
                'quantity' => (int) $item->quantity,
            ])
            ->values()
            ->all();

        if ($requestedItems === []) {
            throw new RuntimeException('RETURN_ITEMS_REQUIRED');
        }

        $calculation = $this->amountCalculator->calculate($order, $requestedItems, $orderItems);
        if (count($calculation['items']) !== count($requestedItems)) {
            throw new RuntimeException('RETURN_ITEM_NOT_IN_ORDER');
        }

        $calculatedItems = collect($calculation['items'])
            ->keyBy(fn (array $item): string => (string) $item['order_item_id']);

        foreach ($returnItems as $returnItem) {
            $row = $calculatedItems->get((string) $returnItem->order_item_id);
            if (! is_array($row)) {
                throw new RuntimeException('RETURN_ITEM_NOT_IN_ORDER');
            }

            $returnItem->forceFill([
                'unit_price_snapshot' => $row['unit_price_snapshot'],
                'gross_amount' => $row['gross_amount'],
                'discount_allocated' => $row['discount_allocated'],
                'refund_amount' => $row['refund_amount'],
                'metadata' => $row['metadata'],
            ])->save();
        }

        return $this->money($calculation['total']);
    }

    private function generateReturnCode(): string
    {
        do {
            $code = 'RET'.now()->format('Ymd').strtoupper(Str::random(6));
        } while (OrderReturn::query()->where('return_code', $code)->exists());

        return $code;
    }

    private function recordOrderHistory(?Order $order, string $status, mixed $actor = null, array $metadata = []): void
    {
        if (! $order || ! Schema::hasTable('order_status_history')) {
            return;
        }

        $payload = [];

        foreach ([
            'id' => (string) Str::uuid(),
            'order_id' => (string) $order->id,
            'old_status' => $order->status,
            'new_status' => $status,
            'changed_by' => $this->actorId($actor),
            'changed_by_role' => is_string($actor) ? $actor : (is_object($actor) ? ($actor->role ?? null) : null),
            'reason' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ] as $column => $value) {
            if (Schema::hasColumn('order_status_history', $column)) {
                $payload[$column] = $value;
            }
        }

        if ($payload !== []) {
            DB::table('order_status_history')->insert($payload);
        }
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

    private function createUserBalance(string $userId, int $availableBalance, mixed $now): void
    {
        $payload = [
            'user_id' => $userId,
        ];

        if (Schema::hasColumn('user_balances', 'id')) {
            $payload['id'] = (string) Str::uuid();
        }

        $defaults = [
            'available_balance' => $availableBalance,
            'locked_balance' => 0,
            'used_balance' => 0,
            'expired_balance' => 0,
            'total_lifetime_purchased' => 0,
            'total_lifetime_used' => 0,
            'last_updated' => $now,
            'version' => 0,
        ];

        foreach ($defaults as $column => $value) {
            if (Schema::hasColumn('user_balances', $column)) {
                $payload[$column] = $value;
            }
        }

        DB::table('user_balances')->insert($payload);
    }

    private function autoWithdrawalIdempotencyKey(
        User $user,
        float $amount,
        ?string $bankId,
        ?string $bankCode,
        string $accountNumber,
        string $accountName
    ): string {
        $fingerprint = implode('|', [
            (string) $user->id,
            number_format($amount, 2, '.', ''),
            strtolower((string) $bankId),
            strtolower((string) $bankCode),
            $accountNumber,
            mb_strtoupper($accountName),
        ]);

        return 'withdrawal:auto:'.(string) $user->id.':'.substr(hash('sha256', $fingerprint), 0, 32).':'.strtolower(Str::random(12));
    }

    private function normalizeWithdrawalField(
        mixed $value,
        string $errorCode,
        int $maxLength,
        bool $required = false,
        ?string $pattern = null
    ): ?string {
        if ($value === null) {
            if ($required) {
                throw new RuntimeException($errorCode);
            }

            return null;
        }

        if (is_bool($value) || ! is_scalar($value)) {
            throw new RuntimeException($errorCode);
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            if ($required) {
                throw new RuntimeException($errorCode);
            }

            return null;
        }

        if (
            mb_strlen($normalized) > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/', $normalized)
            || ($pattern !== null && ! preg_match($pattern, $normalized))
        ) {
            throw new RuntimeException($errorCode);
        }

        return $normalized;
    }

    private function normalizeWithdrawalIdempotencyKey(mixed $key): ?string
    {
        if ($key === null) {
            return null;
        }

        if (is_bool($key) || ! is_scalar($key)) {
            throw new RuntimeException('INVALID_IDEMPOTENCY_KEY');
        }

        $normalized = trim((string) $key);
        if ($normalized === '') {
            return null;
        }

        if (
            strlen($normalized) > 160
            || preg_match('/[^A-Za-z0-9:._-]/', $normalized)
        ) {
            throw new RuntimeException('INVALID_IDEMPOTENCY_KEY');
        }

        return $normalized;
    }

    private function createStoreAdminReturnRequestNotification(OrderReturn $return): void
    {
        if (! Schema::hasTable('store_admin_notifications') || trim((string) $return->store_id) === '') {
            return;
        }

        $return->loadMissing(['order', 'items']);

        $orderCode = trim((string) ($return->order?->order_code ?? ''));
        $displayOrderCode = $orderCode !== '' ? $orderCode : strtoupper(substr((string) $return->order_id, 0, 8));
        $returnId = (string) $return->id;

        try {
            if (StoreAdminNotification::query()
                ->where('store_id', (string) $return->store_id)
                ->where('type', 'return_request')
                ->where('data->order_return_id', $returnId)
                ->exists()) {
                return;
            }

            StoreAdminNotification::query()->create([
                'id' => (string) Str::uuid(),
                'store_id' => (string) $return->store_id,
                'order_id' => null,
                'order_code' => null,
                'type' => 'return_request',
                'title' => 'Yeu cau hoan hang moi',
                'message' => 'Khach hang vua gui yeu cau hoan hang cho don #' . $displayOrderCode . '.',
                'data' => [
                    'order_return_id' => $returnId,
                    'return_id' => $returnId,
                    'return_code' => (string) ($return->return_code ?? ''),
                    'order_id' => (string) $return->order_id,
                    'order_code' => $orderCode !== '' ? $orderCode : null,
                    'status' => (string) $return->status,
                    'return_type' => (string) $return->return_type,
                    'return_method' => (string) ($return->return_method ?? ''),
                    'refund_amount_estimated' => (float) $return->refund_amount_estimated,
                    'amount' => (float) $return->refund_amount_estimated,
                    'item_count' => (int) $return->items->sum('quantity'),
                    'reason_code' => (string) ($return->reason_code ?? ''),
                    'reason' => (string) ($return->reason ?? ''),
                    'action_url' => '/admin_store/returns?id=' . rawurlencode($returnId),
                ],
                'is_read' => false,
                'read_at' => null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function broadcastReturnUpdated(OrderReturn $return, string $action): void
    {
        $event = OrderReturnUpdated::fromReturn($return, $action);

        if (DB::connection()->transactionLevel() > 0) {
            DB::afterCommit(fn () => event($event));

            return;
        }

        event($event);
    }

    private function pushCustomerReturnNotification(OrderReturn $return, string $notificationType, ?string $reason = null): void
    {
        $userId = trim((string) ($return->user_id ?? ''));
        if ($userId === '') {
            return;
        }

        if (!Schema::hasTable('notifications')) {
            return;
        }

        // Respect customer notification preference
        $notifOrderEnabled = true;
        if (Schema::hasTable('user_settings') && Schema::hasColumn('user_settings', 'notif_order')) {
            $setting = UserSetting::query()->where('user_id', $userId)->first();
            if ($setting) {
                $notifOrderEnabled = (bool) ($setting->notif_order ?? true);
            }
        }

        if (!$notifOrderEnabled) {
            return;
        }

        $return->loadMissing(['order']);

        $orderCode = trim((string) ($return->order?->order_code ?? ''));
        $displayOrderCode = $orderCode !== '' ? $orderCode : strtoupper(substr((string) $return->order_id, 0, 8));
        $returnCode = trim((string) ($return->return_code ?? ''));
        $orderId = trim((string) ($return->order_id ?? ''));

        $storeName = 'Cửa hàng';
        $storeId = trim((string) ($return->store_id ?? ''));
        $storeLogoUrl = null;
        if ($storeId !== '' && Schema::hasTable('stores')) {
            $store = Store::query()->find($storeId);
            if ($store) {
                $storeName = trim((string) ($store->name ?? '')) ?: 'Cửa hàng';
                $storeLogoUrl = $store->logo_url ?? null;
            }
        }

        $title = 'Cập nhật yêu cầu hoàn hàng';
        $body = 'Yêu cầu hoàn hàng của bạn đã được cập nhật.';

        if ($notificationType === 'return_approved') {
            $title = 'Yêu cầu hoàn hàng được chấp nhận';
            $body = sprintf('%s đã chấp nhận yêu cầu hoàn hàng cho đơn #%s của bạn.', $storeName, $displayOrderCode);
        } elseif ($notificationType === 'return_rejected') {
            $title = 'Yêu cầu hoàn hàng bị từ chối';
            $body = sprintf('%s đã từ chối yêu cầu hoàn hàng cho đơn #%s của bạn.', $storeName, $displayOrderCode);
            if ($reason !== null && trim($reason) !== '') {
                $body .= ' Lý do: ' . trim($reason);
            }
        }

        $deepLink = $orderId !== '' ? '/pages/order-detail?id=' . urlencode($orderId) : '/notifications';

        try {
            $notificationService = app(NotificationService::class);
            $notification = $notificationService->create([
                'audience_scope' => 'user_private',
                'user_id' => $userId,
                'store_id' => $storeId ?: null,
                'order_id' => $orderId ?: null,
                'type' => $notificationType,
                'title' => $title,
                'message' => $body,
                'body' => $body,
                'action_url' => $deepLink,
                'is_read' => false,
            ]);

            $payload = [
                'notification_id' => (string) ($notification->id ?? ''),
                'id' => (string) ($notification->id ?? ''),
                'user_id' => $userId,
                'type' => $notificationType,
                'title' => $title,
                'body' => $body,
                'message' => $body,
                'display' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'store' => [
                    'id' => $storeId,
                    'name' => $storeName,
                    'logo_url' => $storeLogoUrl,
                ],
                'order' => [
                    'id' => $orderId,
                    'code' => $orderCode,
                    'return_code' => $returnCode,
                    'return_id' => (string) $return->id,
                    'return_status' => (string) $return->status,
                ],
                'deep_link' => $deepLink,
                'action_url' => $deepLink,
            ];

            $broadcastFn = fn () => event(new OrderNotificationEvent($userId, $payload));
            if (DB::connection()->transactionLevel() > 0) {
                DB::afterCommit($broadcastFn);
            } else {
                $broadcastFn();
            }
        } catch (Throwable $e) {
            Log::warning('Failed to push customer return notification.', [
                'user_id' => $userId,
                'return_id' => (string) $return->id,
                'type' => $notificationType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function lockTicketsForReturn(OrderReturn $return): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        // Only lock if not a complaint-initiated return
        if (Schema::hasColumn('order_returns', 'complaint_id') && ! empty($return->complaint_id)) {
            return;
        }

        $now = now();
        $ticketsToLock = collect();

        // Load items of the return
        $return->loadMissing(['items.orderItem']);
        foreach ($return->items as $returnItem) {
            $orderItem = $returnItem->orderItem;
            if (!$orderItem) {
                continue;
            }

            $orderQty = max(1, (int) ($orderItem->quantity ?? 1));
            $rewardQty = max(0, (int) ($orderItem->ticket_reward_quantity ?? 0));
            if ($rewardQty <= 0) {
                continue;
            }

            $targetLockCount = (int) min($rewardQty, ceil(($returnItem->quantity / $orderQty) * $rewardQty));
            if ($targetLockCount <= 0) {
                continue;
            }

            // 1. Try specific order item tickets
            $itemTickets = DB::table('tickets')
                ->where('user_id', $return->user_id)
                ->where('source_id', $return->order_id)
                ->where('source_order_item_id', $returnItem->order_item_id)
                ->whereIn('status', ['AVAILABLE', 'available'])
                ->limit($targetLockCount)
                ->pluck('id');
            $ticketsToLock = $ticketsToLock->concat($itemTickets);

            // 2. Try any order tickets if still needed
            $needed = $targetLockCount - $ticketsToLock->count();
            if ($needed > 0) {
                $orderTickets = DB::table('tickets')
                    ->where('user_id', $return->user_id)
                    ->where('source_id', $return->order_id)
                    ->whereNotIn('id', $ticketsToLock->all())
                    ->whereIn('status', ['AVAILABLE', 'available'])
                    ->limit($needed)
                    ->pluck('id');
                $ticketsToLock = $ticketsToLock->concat($orderTickets);
            }
        }

        $ticketIds = $ticketsToLock->unique()->values()->all();
        if (empty($ticketIds)) {
            return;
        }

        $payload = ['status' => 'LOCKED'];
        if (Schema::hasColumn('tickets', 'locked_at')) {
            $payload['locked_at'] = $now;
        }
        if (Schema::hasColumn('tickets', 'locked_by')) {
            $payload['locked_by'] = 'return:' . $return->id;
        }
        if (Schema::hasColumn('tickets', 'lock_expires_at')) {
            $payload['lock_expires_at'] = null;
        }
        if (Schema::hasColumn('tickets', 'updated_at')) {
            $payload['updated_at'] = $now;
        }

        DB::table('tickets')
            ->whereIn('id', $ticketIds)
            ->whereIn('status', ['AVAILABLE', 'available'])
            ->update($payload);

        // Update user balance
        $balance = DB::table('user_balances')->where('user_id', $return->user_id)->lockForUpdate()->first();
        if (!$balance) {
            $this->createUserBalance((string) $return->user_id, 0, $now);
            $balanceBefore = 0;
        } else {
            $balanceBefore = (int) $balance->available_balance;
            DB::table('user_balances')
                ->where('user_id', $return->user_id)
                ->update([
                    'available_balance' => max(0, $balanceBefore - count($ticketIds)),
                    'last_updated' => $now,
                    'version' => DB::raw('version + 1'),
                ]);
        }

        // Record ledger entries
        $ledgerService = app(TicketLedgerService::class);
        $pricingService = app(TicketPricingService::class);
        $balanceCurrent = $balanceBefore;

        $orderCode = $return->order ? $return->order->order_code : null;

        foreach ($ticketIds as $ticketId) {
            $ticketObj = DB::table('tickets')->where('id', $ticketId)->first();
            $ticketTypeCode = $ticketObj ? strtoupper(trim((string) ($ticketObj->ticket_type_code ?? ''))) : '';
            $resolvedType = $pricingService->resolveTicketType($ticketTypeCode);
            $ticketTypeCode = strtoupper(trim((string) ($resolvedType['code'] ?? $pricingService->getDefaultTicketTypeCode())));
            $ticketTypeName = trim((string) ($resolvedType['name'] ?? $pricingService->getDefaultTicketTypeName()));

            $ledgerService->record([
                'ticket_id' => $ticketId,
                'user_id' => $return->user_id,
                'action_type' => 'LOCKED',
                'amount_change' => -1,
                'balance_before' => $balanceCurrent,
                'balance_after' => $balanceCurrent - 1,
                'reference_id' => (string) $return->id,
                'reference_type' => 'ORDER_RETURN',
                'ticket_type_code' => $ticketTypeCode,
                'ticket_type_name' => $ticketTypeName,
                'metadata' => [
                    'source_type' => 'ORDER_RETURN',
                    'order_id' => (string) $return->order_id,
                    'order_code' => $orderCode,
                    'return_id' => (string) $return->id,
                    'ticket_type_code' => $ticketTypeCode,
                    'ticket_type_name' => $ticketTypeName,
                ],
                'created_at' => $now,
            ]);
            $balanceCurrent--;
        }
    }

    public function releaseTicketsForReturn(OrderReturn $return): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        // Check if there are tickets locked by this return
        $tickets = DB::table('tickets')
            ->where('locked_by', 'return:' . $return->id)
            ->whereIn('status', ['LOCKED', 'locked'])
            ->lockForUpdate()
            ->get();

        if ($tickets->isEmpty()) {
            return;
        }

        $now = now();
        $releasedIds = [];

        foreach ($tickets as $ticket) {
            $expired = ! empty($ticket->expired_at) && $now->greaterThanOrEqualTo($ticket->expired_at);
            $payload = ['status' => $expired ? 'EXPIRED' : 'AVAILABLE'];

            foreach (['locked_at', 'locked_by', 'lock_expires_at'] as $column) {
                if (Schema::hasColumn('tickets', $column)) {
                    $payload[$column] = null;
                }
            }
            if (Schema::hasColumn('tickets', 'updated_at')) {
                $payload['updated_at'] = $now;
            }

            DB::table('tickets')->where('id', (string) $ticket->id)->update($payload);
            $releasedIds[] = (string) $ticket->id;
        }

        // Update user balance
        $balance = DB::table('user_balances')->where('user_id', $return->user_id)->lockForUpdate()->first();
        if (!$balance) {
            $this->createUserBalance((string) $return->user_id, count($releasedIds), $now);
            $balanceBefore = 0;
        } else {
            $balanceBefore = (int) $balance->available_balance;
            DB::table('user_balances')
                ->where('user_id', $return->user_id)
                ->update([
                    'available_balance' => $balanceBefore + count($releasedIds),
                    'last_updated' => $now,
                    'version' => DB::raw('version + 1'),
                ]);
        }

        // Record ledger entries
        $ledgerService = app(TicketLedgerService::class);
        $pricingService = app(TicketPricingService::class);
        $balanceCurrent = $balanceBefore;

        $orderCode = $return->order ? $return->order->order_code : null;

        foreach ($releasedIds as $ticketId) {
            $ticketObj = $tickets->firstWhere('id', $ticketId);
            $ticketTypeCode = $ticketObj ? strtoupper(trim((string) ($ticketObj->ticket_type_code ?? ''))) : '';
            $resolvedType = $pricingService->resolveTicketType($ticketTypeCode);
            $ticketTypeCode = strtoupper(trim((string) ($resolvedType['code'] ?? $pricingService->getDefaultTicketTypeCode())));
            $ticketTypeName = trim((string) ($resolvedType['name'] ?? $pricingService->getDefaultTicketTypeName()));

            $ledgerService->record([
                'ticket_id' => $ticketId,
                'user_id' => $return->user_id,
                'action_type' => 'RELEASED',
                'amount_change' => 1,
                'balance_before' => $balanceCurrent,
                'balance_after' => $balanceCurrent + 1,
                'reference_id' => (string) $return->id,
                'reference_type' => 'ORDER_RETURN',
                'ticket_type_code' => $ticketTypeCode,
                'ticket_type_name' => $ticketTypeName,
                'metadata' => [
                    'source_type' => 'ORDER_RETURN',
                    'order_id' => (string) $return->order_id,
                    'order_code' => $orderCode,
                    'return_id' => (string) $return->id,
                    'ticket_type_code' => $ticketTypeCode,
                    'ticket_type_name' => $ticketTypeName,
                ],
                'created_at' => $now,
            ]);
            $balanceCurrent++;
        }
    }

    public function revokeCustomerTicketsForReturn(OrderReturn $return, mixed $actor = null): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        $now = now();

        // 1. Revoke tickets locked by return
        $tickets = DB::table('tickets')
            ->where('locked_by', 'return:' . $return->id)
            ->whereIn('status', ['LOCKED', 'locked'])
            ->lockForUpdate()
            ->get();

        if ($tickets->isNotEmpty()) {
            $ticketIds = $tickets->pluck('id')->map(fn ($id): string => (string) $id)->values()->all();
            
            $payload = ['status' => 'CANCELLED'];
            foreach (['locked_at', 'locked_by', 'lock_expires_at'] as $column) {
                if (Schema::hasColumn('tickets', $column)) {
                    $payload[$column] = null;
                }
            }
            if (Schema::hasColumn('tickets', 'updated_at')) {
                $payload['updated_at'] = $now;
            }

            DB::table('tickets')->whereIn('id', $ticketIds)->update($payload);

            // Update existing LOCKED ledger entries for this return to REVOKED
            if (Schema::hasTable('ticket_ledger')) {
                DB::table('ticket_ledger')
                    ->where('reference_type', 'ORDER_RETURN')
                    ->where('reference_id', (string) $return->id)
                    ->where('action_type', 'LOCKED')
                    ->update([
                        'action_type' => 'REVOKED',
                    ]);
            }
        }

        // 2. If it is a complaint-initiated return, we also revoke the tickets locked by the complaint
        if (Schema::hasColumn('order_returns', 'complaint_id') && ! empty($return->complaint_id)) {
            $complaintLockService = app(ComplaintTicketLockService::class);
            $complaintLockService->revokeForRefund($return->complaint_id, $this->actorId($actor));
        }
    }

    /**
     * Send order return status notification to the customer.
     */
    private function sendCustomerReturnNotification(OrderReturn $return, string $type, string $title, string $message): void
    {
        if (! Schema::hasTable('notifications') || trim((string) $return->user_id) === '') {
            return;
        }

        $userId = (string) $return->user_id;

        try {
            // Create DB notification record
            $notification = app(\App\Services\NotificationService::class)->create([
                'audience_scope' => 'user_private',
                'user_id' => $userId,
                'type' => 'order',
                'title' => $title,
                'message' => $message,
                'body' => $message,
                'order_id' => $return->order_id,
                'action_url' => '/order/' . $return->order_id . '/return',
                'is_read' => false,
            ]);

            // Create realtime event payload
            $event = new \App\Events\Customer\NotificationCreatedEvent($userId, [
                'notification_id' => (string) $notification->id,
                'id' => (string) $notification->id,
                'user_id' => $userId,
                'type' => 'order',
                'title' => $title,
                'body' => $message,
                'message' => $message,
                'display' => [
                    'title' => $title,
                    'body' => $message,
                ],
                'deep_link' => $notification->action_url,
                'action_url' => $notification->action_url,
                'created_at' => $notification->created_at ? $notification->created_at->toIso8601String() : now()->toIso8601String(),
            ]);

            // Broadcast after database transaction commits to avoid race conditions
            if (DB::connection()->transactionLevel() > 0) {
                DB::afterCommit(fn () => event($event));
            } else {
                event($event);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

