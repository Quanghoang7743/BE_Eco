<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FlashSaleService
{
    private const FLASH_SALE_TIMEZONE = 'Asia/Ho_Chi_Minh';

    protected static ?array $activeItemsCache = null;

    protected function getActiveItems(): array
    {
        if (self::$activeItemsCache === null) {
            $now = now('Asia/Ho_Chi_Minh');
            self::$activeItemsCache = DB::table('flash_sale_items as fsi')
                ->join('flash_sales as fs', 'fs.id', '=', 'fsi.flash_sale_id')
                ->where('fs.is_active', true)
                ->where('fs.start_time', '<=', $now)
                ->where('fs.end_time', '>=', $now)
                ->whereRaw('COALESCE(fsi.sale_quantity, 0) > COALESCE(fsi.sold_quantity, 0)')
                ->select([
                    'fsi.id as flash_sale_item_id',
                    'fsi.flash_sale_id',
                    'fsi.product_id',
                    'fsi.variant_id',
                    'fsi.flash_sale_price',
                    'fsi.sale_quantity',
                    'fsi.per_user_limit',
                    'fsi.sold_quantity',
                    'fs.start_time',
                    'fs.end_time',
                    'fs.is_active',
                    'fs.name',
                    'fs.store_id',
                ])
                ->orderBy('fs.start_time')
                ->get()
                ->all();
        }

        return self::$activeItemsCache;
    }

    public function resolveActiveFlashSaleItem(string $storeId, int $productId, ?int $variantId = null, bool $lockForUpdate = false): ?\stdClass
    {
        if (!$this->isFlashSaleTablesReady()) {
            return null;
        }

        if ($productId <= 0 || $storeId === '') {
            return null;
        }

        if ($lockForUpdate) {
            $now = now('Asia/Ho_Chi_Minh');
            $query = DB::table('flash_sale_items as fsi')
                ->join('flash_sales as fs', 'fs.id', '=', 'fsi.flash_sale_id')
                ->where('fs.store_id', $storeId)
                ->where('fs.is_active', true)
                ->where('fs.start_time', '<=', $now)
                ->where('fs.end_time', '>=', $now)
                ->where('fsi.product_id', $productId)
                ->whereRaw('COALESCE(fsi.sale_quantity, 0) > COALESCE(fsi.sold_quantity, 0)')
                ->select([
                    'fsi.id as flash_sale_item_id',
                    'fsi.flash_sale_id',
                    'fsi.product_id',
                    'fsi.variant_id',
                    'fsi.flash_sale_price',
                    'fsi.sale_quantity',
                    'fsi.per_user_limit',
                    'fsi.sold_quantity',
                    'fs.start_time',
                    'fs.end_time',
                    'fs.is_active',
                    'fs.name',
                ])
                ->orderBy('fs.start_time')
                ->lockForUpdate();

            if ($variantId !== null && $variantId > 0) {
                $item = (clone $query)
                    ->where('fsi.variant_id', $variantId)
                    ->first();

                if ($item) {
                    return $item;
                }
            }

            return $query
                ->whereNull('fsi.variant_id')
                ->first();
        }

        $items = $this->getActiveItems();
        $matching = [];

        foreach ($items as $item) {
            if ((string)$item->store_id === (string)$storeId && (int)$item->product_id === (int)$productId) {
                $matching[] = $item;
            }
        }

        if (empty($matching)) {
            return null;
        }

        if ($variantId !== null && $variantId > 0) {
            foreach ($matching as $item) {
                if ((int)$item->variant_id === (int)$variantId) {
                    return $item;
                }
            }
        }

        foreach ($matching as $item) {
            if ($item->variant_id === null) {
                return $item;
            }
        }

        return null;
    }

    public function resolveEffectivePrice(
        ?string $storeId,
        int $productId,
        ?int $variantId,
        float $basePrice,
        bool $lockForUpdate = false
    ): array {
        $basePrice = max(0.0, $basePrice);

        if (!$storeId || $productId <= 0) {
            return $this->buildPriceResult($basePrice, null);
        }

        $item = $this->resolveActiveFlashSaleItem($storeId, $productId, $variantId, $lockForUpdate);
        if (!$item) {
            return $this->buildPriceResult($basePrice, null);
        }

        $flashSalePrice = max(0.0, (float) ($item->flash_sale_price ?? 0));
        if ($flashSalePrice <= 0 || ($basePrice > 0 && $flashSalePrice >= $basePrice)) {
            return $this->buildPriceResult($basePrice, null);
        }

        return $this->buildPriceResult($flashSalePrice, $item);
    }

    public function consumeFlashSaleQuantity(
        ?string $storeId,
        int $productId,
        ?int $variantId,
        int $quantity,
        float $basePrice,
        ?int $userId = null
    ): array {
        $quantity = max(0, (int) $quantity);
        if ($quantity <= 0) {
            return $this->buildPriceResult(max(0.0, $basePrice), null);
        }

        $item = $this->resolveActiveFlashSaleItem((string) $storeId, $productId, $variantId, true);
        if (!$item) {
            return $this->buildPriceResult(max(0.0, $basePrice), null);
        }

        $flashSalePrice = max(0.0, (float) ($item->flash_sale_price ?? 0));
        if ($flashSalePrice <= 0 || ($basePrice > 0 && $flashSalePrice >= $basePrice)) {
            return $this->buildPriceResult(max(0.0, $basePrice), null);
        }

        $saleQuantity = (int) ($item->sale_quantity ?? 0);
        $soldQuantity = (int) ($item->sold_quantity ?? 0);
        $perUserLimit = isset($item->per_user_limit) ? (int) $item->per_user_limit : null;

        if ($userId !== null && $perUserLimit !== null && $perUserLimit > 0) {
            $userPurchased = (int) DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.user_id', $userId)
                ->where('order_items.flash_sale_item_id', $item->flash_sale_item_id)
                ->whereRaw("LOWER(COALESCE(orders.status, '')) NOT IN ('cancelled','canceled')")
                ->sum('order_items.quantity');

            if ($userPurchased + $quantity > $perUserLimit) {
                throw new \App\Exceptions\FlashSaleUserLimitExceededException(
                    (int) $item->flash_sale_item_id,
                    $perUserLimit,
                    $userPurchased,
                    $quantity
                );
            }
        }
        $remaining = max(0, $saleQuantity - $soldQuantity);

        if ($quantity > $remaining) {
            throw new \RuntimeException('So luong Flash Sale da het. Vui long giam so luong san pham.');
        }

        DB::table('flash_sale_items')
            ->where('id', $item->flash_sale_item_id)
            ->update([
                'sold_quantity' => $soldQuantity + $quantity,
                'updated_at' => now('Asia/Ho_Chi_Minh'),
            ]);

        return $this->buildPriceResult($flashSalePrice, $item, $remaining - $quantity);
    }

    private function buildPriceResult(float $price, ?\stdClass $item, ?int $remainingOverride = null): array
    {
        if (!$item) {
            return [
                'price' => $price,
                'is_flash_sale' => false,
                'flash_sale' => null,
            ];
        }

        $saleQuantity = (int) ($item->sale_quantity ?? 0);
        $soldQuantity = (int) ($item->sold_quantity ?? 0);
        $remaining = $remainingOverride !== null
            ? max(0, $remainingOverride)
            : max(0, $saleQuantity - $soldQuantity);

        return [
            'price' => $price,
            'is_flash_sale' => true,
            'flash_sale' => [
                'flash_sale_item_id' => $item->flash_sale_item_id,
                'flash_sale_id' => $item->flash_sale_id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'price' => (float) ($item->flash_sale_price ?? 0),
                'sale_quantity' => $saleQuantity,
                'per_user_limit' => isset($item->per_user_limit) ? (int) $item->per_user_limit : null,
                'sold_quantity' => $soldQuantity,
                'remaining_quantity' => $remaining,
                'start_time' => $this->toIso($item->start_time ?? null),
                'end_time' => $this->toIso($item->end_time ?? null),
                'name' => (string) ($item->name ?? ''),
                'is_active' => (bool) ($item->is_active ?? true),
            ],
        ];
    }

    private function toIso($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $value, self::FLASH_SALE_TIMEZONE)->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isFlashSaleTablesReady(): bool
    {
        return Schema::hasTable('flash_sales') && Schema::hasTable('flash_sale_items');
    }
}
