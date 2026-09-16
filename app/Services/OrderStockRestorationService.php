<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class OrderStockRestorationService
{
    public function restoreForCancellation(Order|string $order, ?string $statusBeforeCancellation = null): bool
    {
        $restore = function () use ($order, $statusBeforeCancellation): bool {
            $orderId = $order instanceof Order ? (string) $order->getKey() : (string) $order;
            $lockedOrder = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if (!Schema::hasColumn($lockedOrder->getTable(), 'stock_restored_at')) {
                throw new RuntimeException('orders.stock_restored_at is required before restoring order stock.');
            }

            if ($lockedOrder->stock_restored_at !== null) {
                return false;
            }

            $sourceStatus = $this->normalizeStatus($statusBeforeCancellation ?? $lockedOrder->status);
            if (!$this->statusHadStockDeducted($sourceStatus)) {
                return false;
            }

            $this->restoreOrderItems($lockedOrder);

            $restoredAt = now();
            $lockedOrder->forceFill(['stock_restored_at' => $restoredAt])->save();

            if ($order instanceof Order) {
                $order->setAttribute('stock_restored_at', $restoredAt);
            }

            return true;
        };

        return DB::transactionLevel() > 0 ? $restore() : DB::transaction($restore);
    }

    public function statusHadStockDeducted(?string $status): bool
    {
        $normalized = $this->normalizeStatus($status);

        return $normalized !== '' && $normalized !== 'pending_payment';
    }

    private function restoreOrderItems(Order $order): void
    {
        if (
            !Schema::hasTable('order_items')
            || !Schema::hasColumn('order_items', 'order_id')
            || !Schema::hasColumn('order_items', 'product_id')
            || !Schema::hasColumn('order_items', 'quantity')
        ) {
            return;
        }

        $hasVariantId = Schema::hasColumn('order_items', 'variant_id');
        $columns = ['product_id', 'quantity'];
        if ($hasVariantId) {
            $columns[] = 'variant_id';
        }

        $items = DB::table('order_items')
            ->where('order_id', $order->getKey())
            ->lockForUpdate()
            ->get($columns);

        $variantQuantities = [];
        $productQuantities = [];

        foreach ($items as $item) {
            $quantity = max(0, (int) ($item->quantity ?? 0));
            $productId = (int) ($item->product_id ?? 0);
            $variantId = $hasVariantId ? (int) ($item->variant_id ?? 0) : 0;

            if ($quantity <= 0 || $productId <= 0) {
                continue;
            }

            if ($variantId > 0 && Schema::hasTable('product_variants')) {
                $variantQuantities[$variantId] ??= [
                    'product_id' => $productId,
                    'quantity' => 0,
                ];
                $variantQuantities[$variantId]['quantity'] += $quantity;
                continue;
            }

            $productQuantities[$productId] = ($productQuantities[$productId] ?? 0) + $quantity;
        }

        $variantProductIds = [];
        foreach ($variantQuantities as $variantId => $stockLine) {
            $variant = DB::table('product_variants')
                ->where('id', $variantId)
                ->lockForUpdate()
                ->first(['id', 'product_id', 'stock']);

            if (!$variant) {
                $fallbackProductId = (int) $stockLine['product_id'];
                $productQuantities[$fallbackProductId] = ($productQuantities[$fallbackProductId] ?? 0)
                    + (int) $stockLine['quantity'];
                continue;
            }

            $productId = (int) ($variant->product_id ?? 0);
            $payload = ['stock' => max(0, (int) ($variant->stock ?? 0)) + (int) $stockLine['quantity']];
            if (Schema::hasColumn('product_variants', 'updated_at')) {
                $payload['updated_at'] = now();
            }

            DB::table('product_variants')->where('id', $variantId)->update($payload);
            if ($productId > 0) {
                $variantProductIds[$productId] = $productId;
            }
        }

        foreach ($productQuantities as $productId => $quantity) {
            $product = DB::table('products')
                ->where('id', $productId)
                ->lockForUpdate()
                ->first(['id', 'stock']);

            if (!$product) {
                continue;
            }

            $payload = ['stock' => max(0, (int) ($product->stock ?? 0)) + $quantity];
            if (Schema::hasColumn('products', 'updated_at')) {
                $payload['updated_at'] = now();
            }

            DB::table('products')->where('id', $productId)->update($payload);
        }

        foreach ($variantProductIds as $productId) {
            $this->syncProductStockFromVariants($productId);
        }
    }

    private function syncProductStockFromVariants(int $productId): void
    {
        if (
            $productId <= 0
            || !Schema::hasTable('product_variants')
            || !Schema::hasColumn('product_variants', 'product_id')
            || !Schema::hasColumn('product_variants', 'stock')
            || !Schema::hasTable('products')
            || !Schema::hasColumn('products', 'stock')
        ) {
            return;
        }

        DB::table('products')->where('id', $productId)->lockForUpdate()->first(['id']);

        $payload = [
            'stock' => max(0, (int) DB::table('product_variants')->where('product_id', $productId)->sum('stock')),
        ];
        if (Schema::hasColumn('products', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        DB::table('products')->where('id', $productId)->update($payload);
    }

    private function normalizeStatus(?string $status): string
    {
        return mb_strtolower(trim((string) $status));
    }
}
