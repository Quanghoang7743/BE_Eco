<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;

class RefundAmountCalculator
{
    /**
     * @param array<int, array{order_item_id:string, quantity:int}> $requestedItems
     * @return array{items: array<int, array<string, mixed>>, total: float}
     */
    public function calculate(Order $order, array $requestedItems, ?Collection $loadedItems = null): array
    {
        $items = $loadedItems ?? $order->items()->get();
        $itemsById = $items->keyBy(fn (OrderItem $item): string => (string) $item->id);
        $grossOrderAmount = $this->grossOrderAmount($items);
        $orderDiscount = $this->money($order->discount_amount ?? 0);
        $rows = [];
        $totalRefund = 0.0;

        foreach ($requestedItems as $requestedItem) {
            $item = $itemsById->get((string) ($requestedItem['order_item_id'] ?? ''));
            if (! $item instanceof OrderItem) {
                continue;
            }

            $quantity = max(1, (int) ($requestedItem['quantity'] ?? 1));
            $unitPrice = $this->unitPrice($item);
            $grossAmount = $this->money($unitPrice * $quantity);
            $discountAllocated = $grossOrderAmount > 0
                ? $this->money($orderDiscount * ($grossAmount / $grossOrderAmount))
                : 0.0;
            $refundAmount = $this->money(max(0, $grossAmount - $discountAllocated));

            $rows[] = [
                'order_item_id' => (string) $item->id,
                'quantity' => $quantity,
                'unit_price_snapshot' => $unitPrice,
                'gross_amount' => $grossAmount,
                'discount_allocated' => $discountAllocated,
                'refund_amount' => $refundAmount,
                'metadata' => [
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'product_name' => $item->product_name ?? $item->item_name,
                    'thumbnail' => $item->thumbnail ?? null,
                    'ordered_quantity' => (int) ($item->quantity ?? 0),
                ],
            ];
            $totalRefund = $this->money($totalRefund + $refundAmount);
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('order_returns') && \Illuminate\Support\Facades\Schema::hasTable('order_return_items')) {
            $isFinalReturn = true;
            
            $refundedQuantities = \Illuminate\Support\Facades\DB::table('order_return_items')
                ->join('order_returns', 'order_returns.id', '=', 'order_return_items.order_return_id')
                ->where('order_returns.order_id', (string) $order->id)
                ->where('order_returns.status', 'refunded')
                ->groupBy('order_return_items.order_item_id')
                ->select('order_return_items.order_item_id', \Illuminate\Support\Facades\DB::raw('SUM(order_return_items.quantity) as qty'))
                ->pluck('qty', 'order_item_id')
                ->all();

            $requestedItemsByItemId = collect($requestedItems)->keyBy('order_item_id');

            foreach ($items as $item) {
                $orderedQty = (int) ($item->quantity ?? 0);
                if ($orderedQty <= 0) {
                    continue;
                }

                $alreadyRefundedQty = (int) ($refundedQuantities[(string) $item->id] ?? 0);
                $reqItem = $requestedItemsByItemId->get((string) $item->id);
                $requestedQty = $reqItem ? (int) $reqItem['quantity'] : 0;

                if ($requestedQty + $alreadyRefundedQty !== $orderedQty) {
                    $isFinalReturn = false;
                    break;
                }
            }

            if ($isFinalReturn && $rows !== []) {
                $alreadyRefundedAmount = (float) \Illuminate\Support\Facades\DB::table('order_returns')
                    ->where('order_id', (string) $order->id)
                    ->where('status', 'refunded')
                    ->sum('refund_amount_final');

                $adjustedTotal = $this->money($this->orderPayableAmount($order) - $alreadyRefundedAmount);
                $drift = $this->signedMoney($adjustedTotal - $totalRefund);

                if (abs($drift) > 0.0) {
                    $rows = $this->applyFinalReturnDrift($rows, $drift);
                    $totalRefund = $this->sumRefundRows($rows);
                }
            }
        }

        return [
            'items' => $rows,
            'total' => $totalRefund,
        ];
    }

    private function grossOrderAmount(Collection $items): float
    {
        return $this->money($items->sum(function (OrderItem $item): float {
            $quantity = max(1, (int) ($item->quantity ?? 1));

            return $this->unitPrice($item) * $quantity;
        }));
    }

    private function unitPrice(OrderItem $item): float
    {
        $unitPrice = (float) ($item->unit_price ?? $item->price ?? 0);
        if ($unitPrice > 0) {
            return $this->money($unitPrice);
        }

        $quantity = max(1, (int) ($item->quantity ?? 1));
        $lineTotal = (float) ($item->total_price ?? 0);

        return $lineTotal > 0 ? $this->money($lineTotal / $quantity) : 0.0;
    }

    private function orderPayableAmount(Order $order): float
    {
        foreach (['final_amount', 'total_amount', 'total'] as $field) {
            $amount = (float) ($order->{$field} ?? 0);
            if ($amount > 0) {
                return $this->money($amount);
            }
        }

        return 0.0;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function applyFinalReturnDrift(array $rows, float $drift): array
    {
        if ($drift > 0.0) {
            $rows[0]['refund_amount'] = $this->money(((float) $rows[0]['refund_amount']) + $drift);

            return $rows;
        }

        $remainingReduction = abs($drift);
        foreach ($rows as $index => $row) {
            if ($remainingReduction <= 0.0) {
                break;
            }

            $currentRefund = (float) ($row['refund_amount'] ?? 0);
            $reduction = min($currentRefund, $remainingReduction);
            $rows[$index]['refund_amount'] = $this->money($currentRefund - $reduction);
            $remainingReduction = $this->signedMoney($remainingReduction - $reduction);
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function sumRefundRows(array $rows): float
    {
        return $this->money(array_reduce(
            $rows,
            fn (float $carry, array $row): float => $carry + (float) ($row['refund_amount'] ?? 0),
            0.0
        ));
    }

    private function signedMoney(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function money(mixed $value): float
    {
        return round(max(0.0, (float) $value), 2);
    }
}
