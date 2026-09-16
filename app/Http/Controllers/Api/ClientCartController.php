<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\UserCartItem;
use App\Services\FlashSaleService;
use App\Services\TicketPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientCartController extends ClientController
{
    private function cartProductColumns(): array
    {
        $columns = [
            'id',
            'name',
            'slug',
            'content',
            'thumbnail',
            'price',
            'sale_price',
            'stock',
            'store_id',
            'category_id',
            'ticket_reward_quantity',
        ];

        if (Schema::hasColumn('products', 'status')) {
            $columns[] = 'status';
        }

        return $columns;
    }

    private function cartVariantColumns(): array
    {
        $columns = [
            'id',
            'product_id',
            'stock',
            'price',
            'sale_price',
            'image',
            'ticket_reward_quantity',
            'ticket_type_code',
        ];

        if (Schema::hasColumn('product_variants', 'enabled')) {
            $columns[] = 'enabled';
        }

        return $columns;
    }

    private function cartStoreColumns(): array
    {
        $columns = ['id', 'name', 'is_open'];

        foreach (['status', 'is_debt_locked', 'debt_locked_at', 'debt_lock_reason'] as $column) {
            if (Schema::hasColumn('stores', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return $this->unauthorizedResponse();
        }

        if (! Schema::hasTable('user_cart_items')) {
            return response()->json([
                'success' => false,
                'data' => [],
                'issues' => [],
                'message' => 'Bang gio hang chua duoc khoi tao tren he thong.',
            ], 503);
        }

        [$items, $issues, $storeStatuses] = DB::transaction(function () use ($user) {
            return $this->sanitizeAndHydrateUserCart((int) $user->id);
        });

        $closedStores = array_values(array_filter(
            $storeStatuses,
            static fn (array $storeStatus): bool => ! (bool) ($storeStatus['is_currently_operating'] ?? true)
        ));

        return response()->json([
            'success' => true,
            'data' => $items,
            'issues' => $issues,
            'invalid_store_ids' => array_values(array_map(static fn (array $store) => (string) $store['id'], $closedStores)),
            'closed_stores' => $closedStores,
            'store_statuses' => array_values($storeStatuses),
            'meta' => [
                'total_items' => count($items),
                'total_stores' => count($storeStatuses),
                'total_closed_stores' => count($closedStores),
                'request_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return $this->unauthorizedResponse();
        }

        if (! Schema::hasTable('user_cart_items')) {
            return response()->json([
                'success' => false,
                'data' => [],
                'issues' => [],
                'message' => 'Bang gio hang chua duoc khoi tao tren he thong.',
            ], 503);
        }

        $validated = $request->validate([
            'cart' => ['present', 'array', 'max:200'],
            'cart.*.id' => ['required', 'integer', 'min:1'],
            'cart.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'cart.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'cart.*.toppings' => ['nullable', 'array'],
        ]);

        $rawItems = collect($validated['cart'] ?? [])
            ->map(function (array $item): array {
                return [
                    'id' => (int) ($item['id'] ?? 0),
                    'variant_id' => isset($item['variant_id']) ? (int) $item['variant_id'] : 0,
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'toppings' => $item['toppings'] ?? null,
                ];
            })
            ->values()
            ->all();

        [$items, $issues, $storeStatuses] = DB::transaction(function () use ($user, $rawItems) {
            $syncIssues = $this->replaceUserCart((int) $user->id, $rawItems);
            [$hydratedItems, $sanitizeIssues, $hydratedStoreStatuses] = $this->sanitizeAndHydrateUserCart((int) $user->id);

            return [$hydratedItems, array_values(array_merge($syncIssues, $sanitizeIssues)), $hydratedStoreStatuses];
        });

        $closedStores = array_values(array_filter(
            $storeStatuses,
            static fn (array $storeStatus): bool => ! (bool) ($storeStatus['is_currently_operating'] ?? true)
        ));

        return response()->json([
            'success' => true,
            'data' => $items,
            'issues' => $issues,
            'invalid_store_ids' => array_values(array_map(static fn (array $store) => (string) $store['id'], $closedStores)),
            'closed_stores' => $closedStores,
            'store_statuses' => array_values($storeStatuses),
            'meta' => [
                'total_items' => count($items),
                'total_stores' => count($storeStatuses),
                'total_closed_stores' => count($closedStores),
                'request_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return $this->unauthorizedResponse();
        }

        if (! Schema::hasTable('user_cart_items')) {
            return response()->json([
                'success' => false,
                'message' => 'Bang gio hang chua duoc khoi tao tren he thong.',
            ], 503);
        }

        UserCartItem::query()->where('user_id', (int) $user->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Da xoa toan bo gio hang tren he thong.',
            'data' => [],
            'issues' => [],
        ]);
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>}
     */
    private function sanitizeAndHydrateUserCart(int $userId): array
    {
        $issues = [];

        $rows = UserCartItem::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($rows->isEmpty()) {
            return [[], [], []];
        }

        $productIds = $rows->pluck('product_id')
            ->map(static fn ($value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        /** @var Collection<int, Product> $productsById */
        $productsById = Product::query()
            ->whereIn('id', $productIds)
            ->get($this->cartProductColumns())
            ->keyBy('id');

        $variants = ProductVariant::query()
            ->whereIn('product_id', $productIds)
            ->with('attributeValues.attribute')
            ->get($this->cartVariantColumns());

        $variantsByProductId = $variants->groupBy('product_id');
        $variantsById = $variants->keyBy('id');

        $storeIds = $productsById->pluck('store_id')
            ->filter(static fn ($value): bool => $value !== null && $value !== '')
            ->map(static fn ($value): string => (string) $value)
            ->unique()
            ->values();

        /** @var Collection<string, Store> $storesById */
        $storesById = Store::query()
            ->whereIn('id', $storeIds)
            ->get($this->cartStoreColumns())
            ->keyBy(static fn (Store $store): string => (string) $store->id);

        $storeStatusesById = $this->buildStoreStatuses($storesById);

        $items = [];
        $flashSaleItemToIndexes = [];

        foreach ($rows as $row) {
            $productId = (int) ($row->product_id ?? 0);
            $product = $productsById->get($productId);

            if (! $product) {
                $issues[] = $this->buildIssue(
                    'PRODUCT_NOT_FOUND',
                    'San pham trong gio hang khong con ton tai, he thong da tu dong xoa.',
                    $row
                );
                $row->delete();
                continue;
            }

            if (! $this->productIsApprovedForPurchase($product)) {
                $issues[] = $this->buildIssue(
                    'PRODUCT_NOT_PUBLIC',
                    'San pham trong gio hang khong con kha dung, he thong da tu dong xoa.',
                    $row,
                    [
                        'product_name' => (string) ($product->name ?? ''),
                    ]
                );
                $row->delete();
                continue;
            }

            $store = $storesById->get((string) ($product->store_id ?? ''));
            if (! $store || ! $this->storeIsPubliclyVisible($store)) {
                $issues[] = $this->buildIssue(
                    $store && $this->storeIsDebtLocked($store) ? 'STORE_DEBT_LOCKED' : 'STORE_NOT_PUBLIC',
                    'Cua hang cua san pham trong gio khong con kha dung, he thong da tu dong xoa.',
                    $row,
                    [
                        'product_name' => (string) ($product->name ?? ''),
                        'store_id' => (string) ($product->store_id ?? ''),
                    ]
                );
                $row->delete();
                continue;
            }

            $allProductVariants = $variantsByProductId->get($productId, collect());
            $productVariants = $allProductVariants
                ->filter(fn (ProductVariant $variant): bool => $this->variantIsEnabledForPurchase($variant))
                ->values();
            $requestedVariantId = (int) ($row->variant_id ?? 0);
            $variant = null;
            $resolvedVariantId = 0;

            if ($requestedVariantId > 0) {
                $candidateVariant = $variantsById->get($requestedVariantId);
                if (! $candidateVariant || (int) ($candidateVariant->product_id ?? 0) !== $productId || ! $this->variantIsEnabledForPurchase($candidateVariant)) {
                    $issues[] = $this->buildIssue(
                        'INVALID_VARIANT',
                        'Bien the trong gio hang khong con hop le, he thong da tu dong xoa mon.',
                        $row,
                        [
                            'product_name' => (string) ($product->name ?? ''),
                        ]
                    );
                    $row->delete();
                    continue;
                }

                $variant = $candidateVariant;
                $resolvedVariantId = (int) $candidateVariant->id;
            } else {
                if ($allProductVariants->isNotEmpty() && $productVariants->isEmpty()) {
                    $issues[] = $this->buildIssue(
                        'INVALID_VARIANT',
                        'Bien the trong gio hang khong con hop le, he thong da tu dong xoa mon.',
                        $row,
                        [
                            'product_name' => (string) ($product->name ?? ''),
                        ]
                    );
                    $row->delete();
                    continue;
                }

                if ($productVariants->count() > 1) {
                    $issues[] = $this->buildIssue(
                        'VARIANT_REQUIRED',
                        'San pham co nhieu phan loai, vui long chon lai phan loai.',
                        $row,
                        [
                            'product_name' => (string) ($product->name ?? ''),
                        ]
                    );
                    $row->delete();
                    continue;
                }

                if ($productVariants->count() === 1) {
                    $variant = $productVariants->first();
                    $resolvedVariantId = (int) ($variant->id ?? 0);

                    if ($resolvedVariantId > 0 && $resolvedVariantId !== $requestedVariantId) {
                        $row->variant_id = $resolvedVariantId;
                        $row->save();
                    }
                }
            }

            if ($this->resolveProductMenuAvailability(
                $productId,
                $product->store_id !== null ? (string) $product->store_id : null,
                $resolvedVariantId > 0 ? $resolvedVariantId : null
            ) === false) {
                $issues[] = $this->buildIssue(
                    'PRODUCT_CLOSED',
                    'San pham trong gio hang dang dong va duoc xoa.',
                    $row,
                    [
                        'product_name' => (string) ($product->name ?? ''),
                    ]
                );
                $row->delete();
                continue;
            }

            $availableStock = max(0, (int) ($variant ? ($variant->stock ?? 0) : ($product->stock ?? 0)));

            if ($availableStock <= 0) {
                $issues[] = $this->buildIssue(
                    'OUT_OF_STOCK',
                    'San pham trong gio hang da het hang va duoc xoa.',
                    $row,
                    [
                        'product_name' => (string) ($product->name ?? ''),
                        'available_stock' => 0,
                    ]
                );
                $row->delete();
                continue;
            }

            $quantity = max(0, (int) ($row->quantity ?? 0));
            if ($quantity <= 0) {
                $issues[] = $this->buildIssue(
                    'INVALID_QUANTITY',
                    'So luong mon khong hop le va da duoc xoa khoi gio.',
                    $row,
                    [
                        'product_name' => (string) ($product->name ?? ''),
                    ]
                );
                $row->delete();
                continue;
            }

            if ($quantity > $availableStock) {
                $issues[] = $this->buildIssue(
                    'QUANTITY_ADJUSTED',
                    'So luong trong gio vuot ton kho, he thong da tu dong dieu chinh.',
                    $row,
                    [
                        'product_name' => (string) ($product->name ?? ''),
                        'requested_quantity' => $quantity,
                        'available_stock' => $availableStock,
                    ]
                );
                $quantity = $availableStock;
                $row->quantity = $quantity;
                $row->save();
            }

            $pricing = $this->resolveEffectivePricing($product, $variant);
            $effectivePrice = (float) ($pricing['price'] ?? 0);
            if ($effectivePrice <= 0) {
                $issues[] = $this->buildIssue(
                    'INVALID_PRICE',
                    'San pham trong gio hang chua co gia hop le va da bi xoa.',
                    $row,
                    [
                        'product_name' => (string) ($product->name ?? ''),
                    ]
                );
                $row->delete();
                continue;
            }

            $storeStatus = null;
            $storeStatus = $storeStatusesById->get((string) $store->id);

            $index = count($items);
            $items[] = $this->toApiCartItem($row, $product, $variant, $store, $effectivePrice, $storeStatus, $pricing['flash_sale'] ?? null);
            if (isset($pricing['flash_sale']['flash_sale_item_id'])) {
                $fsiId = (int) $pricing['flash_sale']['flash_sale_item_id'];
                $flashSaleItemToIndexes[$fsiId][] = $index;
            }
        }

        // compute per-user purchased quantities for flash sale items in cart
        if (!empty($flashSaleItemToIndexes)) {
            $flashSaleIds = array_keys($flashSaleItemToIndexes);
            $used = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.user_id', $userId)
                ->whereIn('order_items.flash_sale_item_id', $flashSaleIds)
                ->whereRaw("LOWER(COALESCE(orders.status, '')) NOT IN ('cancelled','canceled')")
                ->select('order_items.flash_sale_item_id', DB::raw('SUM(order_items.quantity) as qty'))
                ->groupBy('order_items.flash_sale_item_id')
                ->pluck('qty', 'flash_sale_item_id')
                ->all();

            foreach ($flashSaleItemToIndexes as $fsiId => $indexes) {
                $perUserLimit = null;
                foreach ($indexes as $idx) {
                    $f = $items[$idx]['flash_sale'] ?? null;
                    if ($f) {
                        $perUserLimit = isset($f['per_user_limit']) ? (int) $f['per_user_limit'] : null;
                        $purchased = (int) ($used[$fsiId] ?? 0);
                        $items[$idx]['flash_sale']['purchased_by_current_user'] = $purchased;
                        $items[$idx]['flash_sale']['remaining_user_quota'] = $perUserLimit !== null ? max(0, $perUserLimit - $purchased) : null;
                    }
                }
            }
        }

        return [$items, $issues, $storeStatusesById->values()->all()];
    }

    /**
     * @param Collection<string, Store> $storesById
     * @return Collection<string, array<string, mixed>>
     */
    private function buildStoreStatuses(Collection $storesById): Collection
    {
        return $storesById->mapWithKeys(function (Store $store): array {
            $statusMessage = $this->getStoreOperatingStatusMessage($store);
            $statusCode = 'OPEN';
            if ($statusMessage !== null) {
                if (! $this->storeHasPublicStatus($store)) {
                    $statusCode = 'STORE_NOT_PUBLIC';
                } elseif ($this->storeIsDebtLocked($store)) {
                    $statusCode = 'STORE_DEBT_LOCKED';
                } else {
                    $statusCode = 'STORE_CLOSED';
                }
            }

            return [
                (string) $store->id => [
                    'id' => (string) $store->id,
                    'name' => (string) ($store->name ?? ''),
                    'is_currently_operating' => $statusMessage === null,
                    'status_code' => $statusCode,
                    'status_message' => $statusMessage,
                ],
            ];
        });
    }

    /**
     * @param array<int, array{id:int, variant_id:int, quantity:int}> $rawItems
     * @return array<int, array<string, mixed>>
     */
    private function replaceUserCart(int $userId, array $rawItems): array
    {
        $issues = [];

        $productIds = collect($rawItems)
            ->pluck('id')
            ->map(static fn ($value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        /** @var Collection<int, Product> $productsById */
        $productsById = Product::query()
            ->whereIn('id', $productIds)
            ->get($this->cartProductColumns())
            ->keyBy('id');

        $variants = ProductVariant::query()
            ->whereIn('product_id', $productIds)
            ->with('attributeValues.attribute')
            ->get($this->cartVariantColumns());

        $variantsByProductId = $variants->groupBy('product_id');
        $variantsById = $variants->keyBy('id');

        $storeIds = $productsById->pluck('store_id')
            ->filter(static fn ($value): bool => $value !== null && $value !== '')
            ->map(static fn ($value): string => (string) $value)
            ->unique()
            ->values();

        /** @var Collection<string, Store> $storesById */
        $storesById = Store::query()
            ->whereIn('id', $storeIds)
            ->get($this->cartStoreColumns())
            ->keyBy(static fn (Store $store): string => (string) $store->id);

        $desiredEntries = [];

        foreach ($rawItems as $rawItem) {
            $productId = (int) ($rawItem['id'] ?? 0);
            $requestedVariantId = max(0, (int) ($rawItem['variant_id'] ?? 0));
            $requestedQuantity = max(0, (int) ($rawItem['quantity'] ?? 0));

            if ($productId <= 0 || $requestedQuantity <= 0) {
                continue;
            }

            $product = $productsById->get($productId);
            if (! $product) {
                $issues[] = [
                    'code' => 'PRODUCT_NOT_FOUND',
                    'message' => 'San pham trong gio hang khong con ton tai.',
                    'product_id' => $productId,
                    'variant_id' => $requestedVariantId > 0 ? $requestedVariantId : null,
                    'requested_quantity' => $requestedQuantity,
                ];
                continue;
            }

            if (! $this->productIsApprovedForPurchase($product)) {
                $issues[] = [
                    'code' => 'PRODUCT_NOT_PUBLIC',
                    'message' => 'San pham khong con kha dung.',
                    'product_id' => $productId,
                    'variant_id' => $requestedVariantId > 0 ? $requestedVariantId : null,
                    'requested_quantity' => $requestedQuantity,
                ];
                continue;
            }

            $store = $storesById->get((string) ($product->store_id ?? ''));
            if (! $store || ! $this->storeIsPubliclyVisible($store)) {
                $issues[] = [
                    'code' => $store && $this->storeIsDebtLocked($store) ? 'STORE_DEBT_LOCKED' : 'STORE_NOT_PUBLIC',
                    'message' => 'Cua hang cua san pham khong con kha dung.',
                    'product_id' => $productId,
                    'variant_id' => $requestedVariantId > 0 ? $requestedVariantId : null,
                    'requested_quantity' => $requestedQuantity,
                    'store_id' => (string) ($product->store_id ?? ''),
                ];
                continue;
            }

            $allProductVariants = $variantsByProductId->get($productId, collect());
            $productVariants = $allProductVariants
                ->filter(fn (ProductVariant $variant): bool => $this->variantIsEnabledForPurchase($variant))
                ->values();
            $variant = null;
            $resolvedVariantId = 0;

            if ($requestedVariantId > 0) {
                $candidateVariant = $variantsById->get($requestedVariantId);
                if (! $candidateVariant || (int) ($candidateVariant->product_id ?? 0) !== $productId || ! $this->variantIsEnabledForPurchase($candidateVariant)) {
                    $issues[] = [
                        'code' => 'INVALID_VARIANT',
                        'message' => 'Bien the da chon khong hop le cho san pham nay.',
                        'product_id' => $productId,
                        'variant_id' => $requestedVariantId,
                        'requested_quantity' => $requestedQuantity,
                    ];
                    continue;
                }

                $variant = $candidateVariant;
                $resolvedVariantId = (int) $candidateVariant->id;
            } else {
                if ($allProductVariants->isNotEmpty() && $productVariants->isEmpty()) {
                    $issues[] = [
                        'code' => 'INVALID_VARIANT',
                        'message' => 'Bien the da chon khong hop le cho san pham nay.',
                        'product_id' => $productId,
                        'variant_id' => null,
                        'requested_quantity' => $requestedQuantity,
                    ];
                    continue;
                }

                if ($productVariants->count() > 1) {
                    $issues[] = [
                        'code' => 'VARIANT_REQUIRED',
                        'message' => 'San pham co nhieu phan loai, vui long chon phan loai truoc khi luu gio.',
                        'product_id' => $productId,
                        'variant_id' => null,
                        'requested_quantity' => $requestedQuantity,
                    ];
                    continue;
                }

                if ($productVariants->count() === 1) {
                    $variant = $productVariants->first();
                    $resolvedVariantId = (int) ($variant->id ?? 0);
                }
            }

            if ($this->resolveProductMenuAvailability(
                $productId,
                $product->store_id !== null ? (string) $product->store_id : null,
                $resolvedVariantId > 0 ? $resolvedVariantId : null
            ) === false) {
                $issues[] = [
                    'code' => 'PRODUCT_CLOSED',
                    'message' => 'San pham dang dong, khong the luu vao gio.',
                    'product_id' => $productId,
                    'variant_id' => $resolvedVariantId > 0 ? $resolvedVariantId : null,
                    'requested_quantity' => $requestedQuantity,
                ];
                continue;
            }

            $availableStock = max(0, (int) ($variant ? ($variant->stock ?? 0) : ($product->stock ?? 0)));
            if ($availableStock <= 0) {
                $issues[] = [
                    'code' => 'OUT_OF_STOCK',
                    'message' => 'San pham da het hang va khong duoc luu vao gio.',
                    'product_id' => $productId,
                    'variant_id' => $resolvedVariantId > 0 ? $resolvedVariantId : null,
                    'requested_quantity' => $requestedQuantity,
                    'available_stock' => 0,
                ];
                continue;
            }

            $effectivePrice = $this->resolveEffectivePrice($product, $variant);
            if ($effectivePrice <= 0) {
                $issues[] = [
                    'code' => 'INVALID_PRICE',
                    'message' => 'San pham chua co gia hop le, khong the luu vao gio.',
                    'product_id' => $productId,
                    'variant_id' => $resolvedVariantId > 0 ? $resolvedVariantId : null,
                    'requested_quantity' => $requestedQuantity,
                ];
                continue;
            }

            $safeQuantity = min($requestedQuantity, $availableStock, 100);
            if ($safeQuantity !== $requestedQuantity) {
                $issues[] = [
                    'code' => 'QUANTITY_ADJUSTED',
                    'message' => 'So luong trong gio da duoc dieu chinh theo ton kho.',
                    'product_id' => $productId,
                    'variant_id' => $resolvedVariantId > 0 ? $resolvedVariantId : null,
                    'requested_quantity' => $requestedQuantity,
                    'available_stock' => $availableStock,
                ];
            }

            if ($safeQuantity <= 0) {
                continue;
            }

            $toppingIds = [];
            if (!empty($rawItem['toppings']) && is_array($rawItem['toppings'])) {
                foreach ($rawItem['toppings'] as $topping) {
                    if (isset($topping['id'])) {
                        $toppingIds[] = (int) $topping['id'];
                    }
                }
            }
            sort($toppingIds);
            $toppingKey = implode('-', $toppingIds);
            $entryKey = $productId . ':' . $resolvedVariantId . ':' . $toppingKey;

            if (! isset($desiredEntries[$entryKey])) {
                $desiredEntries[$entryKey] = [
                    'user_id' => $userId,
                    'product_id' => $productId,
                    'variant_id' => $resolvedVariantId,
                    'quantity' => $safeQuantity,
                    'available_stock' => $availableStock,
                    'toppings' => !empty($rawItem['toppings']) ? $rawItem['toppings'] : null,
                ];
                continue;
            }

            $mergedQuantity = min(
                (int) $desiredEntries[$entryKey]['quantity'] + $safeQuantity,
                (int) $desiredEntries[$entryKey]['available_stock'],
                100
            );

            $desiredEntries[$entryKey]['quantity'] = $mergedQuantity;
        }

        UserCartItem::query()->where('user_id', $userId)->delete();

        foreach ($desiredEntries as $entry) {
            UserCartItem::query()->create([
                'user_id' => (int) $entry['user_id'],
                'product_id' => (int) $entry['product_id'],
                'variant_id' => (int) $entry['variant_id'],
                'quantity' => (int) $entry['quantity'],
                'toppings' => $entry['toppings'],
            ]);
        }

        return $issues;
    }

    private function resolveEffectivePricing(Product $product, ?ProductVariant $variant): array
    {
        $basePrice = 0.0;
        $variantId = null;

        if ($variant) {
            $variantId = (int) ($variant->id ?? 0) ?: null;
            $variantSalePrice = (float) ($variant->sale_price ?? 0);
            $variantPrice = (float) ($variant->price ?? 0);
            $basePrice = $variantSalePrice > 0 ? $variantSalePrice : $variantPrice;
        } else {
            $productSalePrice = (float) ($product->sale_price ?? 0);
            $productPrice = (float) ($product->price ?? 0);
            $basePrice = $productSalePrice > 0 ? $productSalePrice : $productPrice;
        }

        $flashSaleService = app(FlashSaleService::class);

        return $flashSaleService->resolveEffectivePrice(
            $product->store_id !== null ? (string) $product->store_id : null,
            (int) $product->id,
            $variantId,
            $basePrice
        );
    }

    private function resolveEffectivePrice(Product $product, ?ProductVariant $variant): float
    {
        $pricing = $this->resolveEffectivePricing($product, $variant);

        return (float) ($pricing['price'] ?? 0);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function buildIssue(string $code, string $message, UserCartItem $row, array $extra = []): array
    {
        return array_merge([
            'code' => $code,
            'message' => $message,
            'product_id' => (int) ($row->product_id ?? 0),
            'variant_id' => (int) ($row->variant_id ?? 0) > 0 ? (int) $row->variant_id : null,
            'requested_quantity' => max(0, (int) ($row->quantity ?? 0)),
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function toApiCartItem(
        UserCartItem $row,
        Product $product,
        ?ProductVariant $variant,
        ?Store $store,
        float $effectivePrice,
        ?array $storeStatus = null,
        ?array $flashSale = null
    ): array
    {
        $toppingTotal = 0.0;
        if (!empty($row->toppings) && is_array($row->toppings)) {
            foreach ($row->toppings as $topping) {
                $tPrice = (float) ($topping['price'] ?? 0.0);
                $tSalePrice = isset($topping['sale_price']) ? (float) $topping['sale_price'] : null;
                if ($tSalePrice !== null && $tSalePrice > 0 && $tSalePrice < $tPrice) {
                    $toppingTotal += $tSalePrice;
                } else {
                    $toppingTotal += $tPrice;
                }
            }
        }
        $effectivePrice += $toppingTotal;

        $variantPayload = null;

        if ($variant) {
            $variantPreviewBasePrice = $flashSale
                ? (float) ($flashSale['price'] ?? 0)
                : (float) ($variant->sale_price ?? $variant->price ?? 0);

            $variantPayload = [
                'id' => (int) $variant->id,
                'product_id' => (int) ($variant->product_id ?? 0),
                'price' => (float) ($variant->price ?? 0),
                'sale_price' => (float) ($variant->sale_price ?? 0),
                'stock' => (int) ($variant->stock ?? 0),
                'image' => $variant->image,
                'ticket_reward_quantity' => (int) ($variant->ticket_reward_quantity ?? 0),
                'ticket_type_code' => $variant->ticket_type_code,
                'ticket_pricing_preview' => app(TicketPricingService::class)->calculatePreview(
                    $variantPreviewBasePrice,
                    (int) ($variant->ticket_reward_quantity ?? 0),
                    $variant->ticket_type_code
                ),
                'attribute_values' => $variant->attributeValues
                    ->map(function ($attributeValue): array {
                        $attribute = $attributeValue->attribute;

                        return [
                            'id' => (int) ($attributeValue->id ?? 0),
                            'attribute_id' => (int) ($attributeValue->attribute_id ?? 0),
                            'value' => (string) ($attributeValue->value ?? ''),
                            'attribute' => $attribute
                                ? [
                                    'id' => (int) ($attribute->id ?? 0),
                                    'name' => (string) ($attribute->name ?? ''),
                                ]
                                : null,
                        ];
                    })
                    ->values()
                    ->all(),
            ];

            if ($flashSale !== null) {
                $variantPayload['flash_sale'] = $flashSale;
            }
        }

        $productPreviewBasePrice = $flashSale && !$variant
            ? (float) ($flashSale['price'] ?? 0)
            : (float) ($product->sale_price ?? $product->price ?? 0);
        $menuAvailability = $this->resolveProductMenuAvailability(
            (int) $product->id,
            $product->store_id !== null ? (string) $product->store_id : null,
            $variant ? (int) $variant->id : null
        );

        $productPayload = [
            'id' => (int) $product->id,
            'name' => (string) ($product->name ?? ''),
            'slug' => (string) ($product->slug ?? ''),
            'content' => (string) ($product->content ?? ''),
            'thumbnail' => (string) ($product->thumbnail ?? ''),
            'price' => (float) ($product->price ?? 0),
            'sale_price' => (float) ($product->sale_price ?? 0),
            'stock' => (int) ($product->stock ?? 0),
            'in_stock' => $menuAvailability ?? true,
            'is_available' => $menuAvailability ?? true,
            'category_id' => $product->category_id !== null ? (int) $product->category_id : null,
            'store_id' => $product->store_id !== null ? (string) $product->store_id : null,
            'store_name' => $store ? (string) ($store->name ?? '') : null,
            'is_currently_operating' => $storeStatus !== null
                ? (bool) ($storeStatus['is_currently_operating'] ?? true)
                : true,
            'store_status_message' => $storeStatus['status_message'] ?? null,
            'ticket_reward_quantity' => (int) ($product->ticket_reward_quantity ?? 0),
            'menu_item_id' => null,
            'variants' => [],
            'flash_sale' => $flashSale && !$variant ? $flashSale : null,
            'ticket_pricing_preview' => app(TicketPricingService::class)->calculatePreview(
                $productPreviewBasePrice,
                (int) ($product->ticket_reward_quantity ?? 0),
                $product->ticket_type_code ?? null
            ),
        ];

        return [
            'id' => (int) $row->id,
            'product_id' => (int) $product->id,
            'variant_id' => $variant ? (int) $variant->id : null,
            'quantity' => (int) ($row->quantity ?? 0),
            'price' => $effectivePrice,
            'name' => (string) ($product->name ?? ''),
            'store_id' => $product->store_id !== null ? (string) $product->store_id : null,
            'store_name' => $store ? (string) ($store->name ?? '') : null,
            'is_currently_operating' => $storeStatus !== null
                ? (bool) ($storeStatus['is_currently_operating'] ?? true)
                : true,
            'store_status_message' => $storeStatus['status_message'] ?? null,
            'flash_sale' => $flashSale,
            'product' => $productPayload,
            'variant' => $variantPayload,
            'toppings' => $row->toppings,
        ];
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'issues' => [],
            'message' => 'Ban chua dang nhap.',
        ], 401);
    }
}
