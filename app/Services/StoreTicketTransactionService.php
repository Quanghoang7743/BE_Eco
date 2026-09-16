<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StoreTicketTransactionService
{
    public function __construct(
        private readonly TicketPricingService $ticketPricingService,
    ) {
    }

    public function isEnabled(): bool
    {
        return Schema::hasTable('store_ticket_transactions');
    }

    public function recordOrderConsumption(Order $order): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        if (!$this->isOrderStatusCompleted($order)) {
            return 0;
        }

        $orderId = (string) $order->id;
        if ($this->hasEvent($orderId, 'ORDER_DELIVERED')) {
            return 0;
        }

        if (!Schema::hasTable('order_items') || !Schema::hasTable('products')) {
            return 0;
        }

        $items = DB::table('order_items as oi')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->where('oi.order_id', $orderId)
            ->selectRaw('oi.product_id as product_id')
            ->selectRaw('COALESCE(oi.quantity, 0) as quantity')
            ->selectRaw('COALESCE(oi.unit_price, 0) as unit_price')
            ->selectRaw('COALESCE(p.ticket_reward_quantity, 0) as ticket_reward_quantity')
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $config = $this->ticketPricingService->getConfig();
        $storeId = (string) ($order->store_id ?? '');
        $now = now();

        $rows = [];
        foreach ($items as $item) {
            $quantity = max(0, (int) ($item->quantity ?? 0));
            $ticketPerUnit = max(0, (int) ($item->ticket_reward_quantity ?? 0));

            if ($quantity < 1 || $ticketPerUnit < 1) {
                continue;
            }

            $ticketsConsumed = $quantity * $ticketPerUnit;
            $unitPrice = (float) ($item->unit_price ?? 0);
            $ticketUnitPrice = (float) $this->ticketPricingService->calculatePerTicketValue($unitPrice);
            $totalDebit = round($ticketsConsumed * $ticketUnitPrice, 2);

            $rows[] = [
                'transaction_id' => (string) Str::uuid(),
                'order_id' => $orderId,
                'store_id' => $storeId !== '' ? $storeId : null,
                'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
                'tickets_consumed' => $ticketsConsumed,
                'unit_price_at_time' => $ticketUnitPrice,
                'total_debit_amount' => $totalDebit,
                'event_type' => 'ORDER_DELIVERED',
                'ticket_calc_type' => (string) ($config['calc_type'] ?? 'fixed'),
                'ticket_value_snapshot' => (float) ($config['ticket_value'] ?? 0),
                'metadata' => json_encode([
                    'order_code' => $order->order_code ?? null,
                    'quantity' => $quantity,
                    'ticket_per_unit' => $ticketPerUnit,
                    'source' => 'order_completed',
                ], JSON_UNESCAPED_UNICODE),
                'timestamp' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        DB::table('store_ticket_transactions')->insert($rows);

        return count($rows);
    }

    public function recordRefundReversal(Order $order, string $reason = 'order_refund'): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        $orderId = (string) $order->id;
        if ($orderId === '') {
            return 0;
        }

        if ($this->hasEvent($orderId, 'ORDER_REFUND')) {
            return 0;
        }

        $originalRows = DB::table('store_ticket_transactions')
            ->where('order_id', $orderId)
            ->where('event_type', 'ORDER_DELIVERED')
            ->get();

        if ($originalRows->isEmpty()) {
            return 0;
        }

        $now = now();
        $reversalRows = [];

        foreach ($originalRows as $row) {
            $ticketsConsumed = (int) ($row->tickets_consumed ?? 0);
            $totalDebit = (float) ($row->total_debit_amount ?? 0);

            if ($ticketsConsumed === 0 && $totalDebit == 0.0) {
                continue;
            }

            $metadata = [
                'reason' => $reason,
                'source' => 'order_refund',
                'reversal_of' => $row->transaction_id ?? null,
                'order_code' => $order->order_code ?? null,
            ];

            $reversalRows[] = [
                'transaction_id' => (string) Str::uuid(),
                'order_id' => $orderId,
                'store_id' => $row->store_id ?? null,
                'product_id' => $row->product_id ?? null,
                'tickets_consumed' => -1 * $ticketsConsumed,
                'unit_price_at_time' => (float) ($row->unit_price_at_time ?? 0),
                'total_debit_amount' => -1 * $totalDebit,
                'event_type' => 'ORDER_REFUND',
                'ticket_calc_type' => $row->ticket_calc_type ?? null,
                'ticket_value_snapshot' => $row->ticket_value_snapshot ?? null,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'timestamp' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($reversalRows)) {
            return 0;
        }

        DB::table('store_ticket_transactions')->insert($reversalRows);

        return count($reversalRows);
    }

    private function hasEvent(string $orderId, string $eventType): bool
    {
        return DB::table('store_ticket_transactions')
            ->where('order_id', $orderId)
            ->where('event_type', $eventType)
            ->exists();
    }

    private function isOrderStatusCompleted(Order $order): bool
    {
        $status = mb_strtolower(trim((string) ($order->status ?? '')));

        return in_array($status, ['delivered', 'completed'], true);
    }
}
