<?php

namespace App\Services;

use App\Events\OrderSlaWarningEvent;
use App\Events\OrderTimeoutEvent;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Store;
use App\Models\StoreAdminNotification;
use App\Models\TicketLedger;
use App\Models\UserBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderSlaService
{
    public function __construct(
        private readonly OrderStockRestorationService $stockRestorationService
    ) {
    }

    public function sendSlaWarning(Order $order): bool
    {
        try {
            if ($order->sla_warning_sent) {
                return false;
            }

            if (!$order->isPending() || !$order->isCloseToTimeout(2)) {
                return false;
            }

            $remainingMinutes = $order->getRemainingMinutes();

            StoreAdminNotification::create([
                'id' => (string) Str::uuid(),
                'store_id' => $order->store_id,
                'type' => 'order_sla_warning',
                'title' => 'Cảnh báo SLA đơn hàng',
                'message' => "Bạn còn {$remainingMinutes} phút để xác nhận đơn #{$order->order_code}.",
                'related_order_id' => $order->id,
                'is_read' => false,
            ]);

            $order->update(['sla_warning_sent' => true]);

            event(new OrderSlaWarningEvent($order, $remainingMinutes));

            Log::info("SLA warning sent for order: {$order->order_code}");

            return true;
        } catch (\Exception $e) {
            Log::error("Error sending SLA warning for order {$order->order_code}: " . $e->getMessage());
            return false;
        }
    }

    public function autoCancelTimeout(Order $order): bool
    {
        try {
            if (!$order->isPending() || !$order->hasTimedOut()) {
                return false;
            }

            return DB::transaction(function () use ($order) {
                $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();
                if (!$order || !$order->isPending() || !$order->hasTimedOut()) {
                    return false;
                }

                $creditService = app(StoreCreditService::class);
                $store = !empty($order->store_id)
                    ? Store::query()->whereKey((string) $order->store_id)->lockForUpdate()->first()
                    : null;

                if ($store && $creditService->shouldLockStoreForCreditTimeout($order, $store)) {
                    $creditService->lockStoreForCreditTimeout((string) $order->store_id);

                    $this->cancelPendingOrderInsideTransaction(
                        $order,
                        'credit_capacity_timeout',
                        'Auto cancelled because store credit capacity timed out',
                        true
                    );

                    Log::info("Store {$order->store_id} locked for credit capacity timeout; cancelled timed-out order {$order->order_code}. Other pending orders are kept.");

                    return true;
                }

                $this->cancelPendingOrderInsideTransaction(
                    $order,
                    'order_timeout',
                    'Auto cancelled due to SLA timeout',
                    false
                );

                Log::info("Order {$order->order_code} auto-cancelled due to timeout");

                return true;
            });
        } catch (\Exception $e) {
            Log::error("Error auto-cancelling order {$order->order_code}: " . $e->getMessage());
            return false;
        }
    }

    public function refundTicketsToCustomer(Order $order): bool
    {
        try {
            if (!$order->user_id || $order->total_tickets_used <= 0) {
                return true;
            }

            return DB::transaction(function () use ($order) {
                $totalTickets = $order->total_tickets_used;

                $balance = UserBalance::firstOrCreate(
                    ['user_id' => $order->user_id],
                    ['available_balance' => 0, 'locked_balance' => 0]
                );

                $balanceBefore = $balance->available_balance ?? 0;
                $balance->increment('available_balance', $totalTickets);
                $balanceAfter = $balance->available_balance ?? 0;

                TicketLedger::create([
                    'user_id' => $order->user_id,
                    'action_type' => 'refund',
                    'amount_change' => $totalTickets,
                    'reference_type' => 'order',
                    'reference_id' => $order->id,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'created_at' => now(),
                ]);

                Log::info("Refunded {$totalTickets} tickets to user {$order->user_id} for order {$order->order_code}");

                return true;
            });
        } catch (\Exception $e) {
            Log::error("Error refunding tickets for order {$order->order_code}: " . $e->getMessage());
            return false;
        }
    }

    public function checkAllSlaTimeouts(): array
    {
        try {
            $result = [
                'warnings_sent' => 0,
                'orders_cancelled' => 0,
                'errors' => 0,
            ];

            $warningDeadline = now()->addMinutes(2);

            $pendingOrders = Order::where('status', 'pending')
                ->where(function ($query) use ($warningDeadline) {
                    $query->whereNull('timeout_at')
                        ->orWhere('timeout_at', '<=', $warningDeadline);
                })
                ->get();

            foreach ($pendingOrders as $order) {
                if (!$order->timeout_at) {
                    $order->update([
                        'timeout_at' => $order->calculateTimeoutAt(),
                    ]);
                }

                if ($order->hasTimedOut()) {
                    if ($this->autoCancelTimeout($order)) {
                        $result['orders_cancelled']++;
                    } else {
                        $freshStatus = Order::query()
                            ->whereKey($order->getKey())
                            ->value('status');

                        if (strtolower((string) $freshStatus) === 'pending') {
                            $result['errors']++;
                        }
                    }
                } elseif ($order->isCloseToTimeout(2)) {
                    if ($this->sendSlaWarning($order)) {
                        $result['warnings_sent']++;
                    }
                }
            }

            Log::info("SLA check completed: " . json_encode($result));

            return $result;
        } catch (\Exception $e) {
            Log::error("Error in checkAllSlaTimeouts: " . $e->getMessage());
            return ['warnings_sent' => 0, 'orders_cancelled' => 0, 'errors' => 1];
        }
    }

    private function cancelPendingOrderInsideTransaction(Order $order, string $reasonCode, string $historyReason, bool $creditLock): void
    {
        $now = now();
        $this->stockRestorationService->restoreForCancellation($order, 'pending');

        $combinedReason = '[' . strtoupper($reasonCode) . '] - ' . ($creditLock
            ? 'Tự động hủy do cửa hàng chưa nạp đủ hạn mức công nợ ticket.'
            : 'Tự động hủy do quá giờ xác nhận (SLA timeout).');

        $order->update([
            'status' => 'canceled',
            'cancel_reason' => $combinedReason,
            'cancelled_by' => 'system',
            'cancelled_at' => $now,
        ]);

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'old_status' => 'pending',
            'new_status' => 'canceled',
            'changed_by_role' => 'system',
            'reason' => $historyReason,
            'created_at' => $now,
        ]);

        $reasonText = $creditLock
            ? 'Cửa hàng chưa nạp đủ hạn mức công nợ ticket'
            : 'Quá giờ xác nhận đơn';

        StoreAdminNotification::create([
            'id' => (string) Str::uuid(),
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'order_code' => $order->order_code,
            'type' => 'order_timeout',
            'title' => $creditLock ? 'Cửa hàng bị khóa do vượt hạn mức' : 'Đơn hàng quá giờ xác nhận',
            'message' => $creditLock
                ? "Đơn hàng #{$order->order_code} đã bị hủy tự động do cửa hàng chưa nạp đủ hạn mức công nợ ticket."
                : "Đơn hàng #{$order->order_code} đã bị hủy tự động do quá giờ xác nhận.",
            'data' => [
                'order_id' => $order->id,
                'order_code' => $order->order_code,
                'reason_code' => $reasonCode,
                'reason_text' => $reasonText,
                'cancelled_at' => $now->toIso8601String(),
            ],
            'is_read' => false,
        ]);

        $this->refundTicketsToCustomer($order);

        Notification::create([
            'user_id' => $order->user_id,
            'type' => 'order_timeout',
            'title' => 'Đơn hàng bị hủy',
            'message' => $creditLock
                ? "Đơn hàng #{$order->order_code} của bạn đã bị hủy do cửa hàng chưa nạp đủ hạn mức. Vé đã được hoàn lại ví của bạn."
                : "Đơn hàng #{$order->order_code} của bạn đã bị hủy do cửa hàng không xác nhận kịp thời. Vé đã được hoàn lại ví của bạn.",
            'is_read' => false,
        ]);

        event(new OrderTimeoutEvent($order, $creditLock, $reasonCode));
    }

    private function cancelPendingCreditLockedOrdersInsideTransaction(string $storeId): int
    {
        $pendingOrders = Order::query()
            ->where('store_id', $storeId)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $cancelled = 0;
        foreach ($pendingOrders as $pendingOrder) {
            if (! $pendingOrder->isPending()) {
                continue;
            }

            $this->cancelPendingOrderInsideTransaction(
                $pendingOrder,
                'credit_capacity_timeout',
                'Auto cancelled because store credit capacity timed out',
                true
            );
            $cancelled++;
        }

        return $cancelled;
    }
}
