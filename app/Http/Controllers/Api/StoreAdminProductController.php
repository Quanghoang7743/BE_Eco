<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Topping;
use App\Events\ProductApprovalUpdated;
use App\Events\StoreAdminProductUpdated;
use App\Services\NotificationService;
use App\Services\ProductApprovalHistoryService;
use App\Services\TicketPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StoreAdminProductController extends Controller
{
    private const FLASH_SALE_TIMEZONE = 'Asia/Ho_Chi_Minh';

    public function toppings(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $toppings = Topping::query()
            ->where('store_id', $store->id)
            ->get();

        return response()->json([
            'data' => $toppings->map(function (Topping $topping) {
                return $this->serializeTopping($topping);
            })->values(),
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function ticketPricingConfig(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $ticketPricingService = app(TicketPricingService::class);
        $config = $ticketPricingService->getConfig();
        $ticketTypes = $ticketPricingService->getTicketTypes();
        $defaultTypeCode = $ticketPricingService->getDefaultTicketTypeCode();

        return response()->json([
            'data' => [
                'ticket_calc_type' => $config['calc_type'],
                'ticket_value' => (float) $config['ticket_value'],
                'ticket_types' => $ticketTypes,
                'default_ticket_type_code' => $defaultTypeCode,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function ticketPricingPreview(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'price' => ['required', 'numeric', 'min:0'],
            'ticket_reward_quantity' => ['required', 'integer', 'min:0', 'max:1000'],
            'ticket_type_code' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $price = (float) $validated['price'];
        $ticketRewardQuantity = (int) $validated['ticket_reward_quantity'];
        $ticketTypeCode = isset($validated['ticket_type_code']) ? strtoupper(trim((string) $validated['ticket_type_code'])) : null;

        $preview = app(TicketPricingService::class)->calculatePreview(
            $price,
            $ticketRewardQuantity,
            $ticketTypeCode
        );

        $ticketCalcType = (string) ($preview['calc_type'] ?? 'fixed');
        if ($ticketCalcType === 'fixed') {
            $ticketCalcType = 'fixed_amount';
        }

        $ticketValue = (float) ($preview['ticket_value'] ?? 0);
        $ticketUnitValue = (float) ($preview['per_ticket_value_vnd'] ?? 0);
        $totalCost = (float) ($preview['total_recovery_vnd'] ?? 0);

        return response()->json([
            'data' => [
                'price' => $price,
                'ticket_reward_quantity' => $ticketRewardQuantity,
                'ticket_type_code' => $preview['ticket_type_code'] ?? $ticketTypeCode,
                'ticket_type_name' => $preview['ticket_type_name'] ?? null,
                'ticket_calc_type' => $ticketCalcType,
                'ticket_value' => $ticketValue,
                'ticket_unit_value' => $ticketUnitValue,
                'total_cost' => $totalCost,
                // Backward-compatible fields for existing clients.
                'calc_type' => (string) ($preview['calc_type'] ?? 'fixed'),
                'per_ticket_value_vnd' => $ticketUnitValue,
                'ticket_quantity' => $ticketRewardQuantity,
                'total_recovery_vnd' => $totalCost,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function approvalStatus(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('products')) {
            return $this->errorResponse('TABLE_NOT_FOUND', 'Bang products chua ton tai.', 500, true, true);
        }

        if (!Schema::hasColumn('products', 'store_id')) {
            return $this->errorResponse('COLUMN_NOT_FOUND', 'Bang products thieu cot store_id de gioi han du lieu theo cua hang.', 500, true, true);
        }

        $product = Product::query()
            ->where('id', $id)
            ->where('store_id', $store->id)
            ->first();

        if (!$product instanceof Product) {
            return $this->errorResponse('PRODUCT_NOT_FOUND', 'Khong tim thay san pham hoac san pham khong thuoc cua hang hien tai.', 404, true, true);
        }

        $menuApprovedProductIds = $this->resolveMenuApprovedProductIds($store, [$product->id]);
        $status = $this->resolveStoreAdminProductStatus($product, $menuApprovedProductIds);

        return response()->json([
            'data' => [
                'product_id' => $product->id,
                'store_id' => $product->store_id,
                'status' => $status,
                'approved_at' => Schema::hasColumn('products', 'approved_at')
                    ? $this->toIsoDateTime($product->approved_at)
                    : null,
                'published_at' => Schema::hasColumn('products', 'published_at')
                    ? $this->toIsoDateTime($product->published_at)
                    : null,
                'rejection_reason' => (
                    $status === 'rejected'
                    && Schema::hasColumn('products', 'rejection_reason')
                )
                    ? (trim((string) ($product->rejection_reason ?? '')) !== '' ? (string) $product->rejection_reason : null)
                    : null,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function approvalStatusList(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('products')) {
            return $this->errorResponse('TABLE_NOT_FOUND', 'Bang products chua ton tai.', 500, true, true);
        }

        if (!Schema::hasColumn('products', 'store_id')) {
            return $this->errorResponse('COLUMN_NOT_FOUND', 'Bang products thieu cot store_id de gioi han du lieu theo cua hang.', 500, true, true);
        }

        $validator = Validator::make($request->query(), [
            'product_ids' => ['required', 'array'],
            'product_ids.*' => ['required', 'numeric'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'Du lieu dau vao khong hop le.',
                422,
                true,
                true,
                $validator->errors()->toArray(),
            );
        }

        $productIds = array_values(array_unique(array_filter($request->query('product_ids', []), function ($value) {
            return is_numeric($value);
        })));

        if (count($productIds) === 0) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->where('store_id', $store->id)
            ->get();

        $menuApprovedProductIds = $this->resolveMenuApprovedProductIds($store, $products->pluck('id')->all());

        $items = $products->map(function (Product $product) use ($menuApprovedProductIds) {
            $status = $this->resolveStoreAdminProductStatus($product, $menuApprovedProductIds);

            return [
                'product_id' => $product->id,
                'store_id' => $product->store_id,
                'status' => $status,
                'approved_at' => Schema::hasColumn('products', 'approved_at')
                    ? $this->toIsoDateTime($product->approved_at)
                    : null,
                'published_at' => Schema::hasColumn('products', 'published_at')
                    ? $this->toIsoDateTime($product->published_at)
                    : null,
                'rejection_reason' => (
                    $status === 'rejected'
                    && Schema::hasColumn('products', 'rejection_reason')
                )
                    ? (trim((string) ($product->rejection_reason ?? '')) !== '' ? (string) $product->rejection_reason : null)
                    : null,
            ];
        })->toArray();

        return response()->json([
            'data' => $items,
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->productListResponse($request, false);
    }

    public function flashSaleProducts(Request $request): JsonResponse
    {
        return $this->productListResponse($request, true);
    }

    private function productListResponse(Request $request, bool $approvedOnly): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('products')) {
            return $this->errorResponse('TABLE_NOT_FOUND', 'Bang products chua ton tai.', 500, true, true);
        }

        if (!Schema::hasColumn('products', 'store_id')) {
            return $this->errorResponse('COLUMN_NOT_FOUND', 'Bang products thieu cot store_id de gioi han du lieu theo cua hang.', 500, true, true);
        }

        $validator = Validator::make($request->query(), [
            'status' => ['nullable', 'string', 'max:80'],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'max:80'],
            'product_ids' => ['nullable', 'array', 'max:100'],
            'product_ids.*' => ['nullable', 'numeric'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'Du lieu dau vao khong hop le.',
                422,
                true,
                true,
                $validator->errors()->toArray()
            );
        }

        $filters = $validator->validated();
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['page_size'] ?? 20);
        $status = trim((string) ($filters['status'] ?? ''));
        $queryText = trim((string) ($filters['q'] ?? ''));
        $sort = trim((string) ($filters['sort'] ?? ''));
        $productIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): int => (int) $value,
            (array) ($filters['product_ids'] ?? [])
        ), static fn (int $value): bool => $value > 0)));

        $productQuery = Product::query()
            ->with(['category', 'variants.attributeValues.attribute', 'toppings'])
            ->withCount('variants')
            ->where('store_id', $store->id);

        if ($approvedOnly) {
            $hasStatusColumn = Schema::hasColumn('products', 'status');
            $hasStoreMenuMapping = Schema::hasTable('store_menu')
                && Schema::hasColumn('store_menu', 'store_id')
                && Schema::hasColumn('store_menu', 'product_id');

            if ($hasStatusColumn) {
                $productQuery->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), Product::APPROVED_STATUSES);
            } elseif ($hasStoreMenuMapping) {
                $productQuery->whereExists(function ($menuQuery) use ($store) {
                    $menuQuery->select(DB::raw(1))
                        ->from('store_menu')
                        ->whereColumn('store_menu.product_id', 'products.id')
                        ->where('store_menu.store_id', $store->id);

                    if (Schema::hasColumn('store_menu', 'is_available')) {
                        $menuQuery->where('store_menu.is_available', true);
                    }
                });
            }
        } elseif ($status !== '' && $status !== 'all') {
            $productQuery->where('status', $status);
        }

        if ($queryText !== '') {
            $productQuery->where(function ($subQuery) use ($queryText) {
                $subQuery->where('name', 'like', '%' . $queryText . '%')
                    ->orWhere('sku', 'like', '%' . $queryText . '%');
            });
        }

        if (count($productIds) > 0) {
            $productQuery->whereIn('id', $productIds);
        }

        $total = (int) (clone $productQuery)->count();
        $rows = $productQuery
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get();

        $productIds = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $menuApprovedProductIds = $this->resolveMenuApprovedProductIds($store, $productIds);
        $reservedFlashSaleQuantities = $approvedOnly
            ? $this->resolveReservedFlashSaleQuantityMap((string) $store->id, $productIds)
            : [];
        $items = $rows
            ->map(fn (Product $product) => $this->serializeStoreAdminProduct(
                $product,
                $menuApprovedProductIds,
                $approvedOnly,
                $reservedFlashSaleQuantities
            ))
            ->values()
            ->all();
        $totalPages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 0;

        return response()->json([
            'data' => $items,
            'meta' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function serializeStoreAdminProduct(
        Product $product,
        array $menuApprovedProductIds = [],
        bool $withFlashSaleAvailability = false,
        array $reservedFlashSaleQuantities = []
    ): array
    {
        $images = $product->images;
        if (is_string($images)) {
            $decoded = json_decode($images, true);
            if (is_array($decoded)) {
                $images = $decoded;
            }
        }

        $productId = (int) $product->id;
        $reservedBaseQuantity = (int) ($reservedFlashSaleQuantities[$this->buildFlashSaleInventoryKey($productId, null)] ?? 0);
        $reservedProductQuantity = $withFlashSaleAvailability
            ? $reservedBaseQuantity
            : 0;
        $productStock = (int) ($product->stock ?? 0);
        $variantRows = [];
        foreach ($product->variants->values() as $variant) {
            $variantStock = (int) ($variant->stock ?? 0);
            $variantId = (int) $variant->id;
            $reservedVariantQuantity = $withFlashSaleAvailability
                ? $reservedBaseQuantity + (int) ($reservedFlashSaleQuantities[$this->buildFlashSaleInventoryKey($productId, $variantId)] ?? 0)
                : 0;
            $attributeRows = [];
            foreach ($variant->attributeValues as $attributeValue) {
                $attributeRows[] = [
                    'id' => trim((string) ($attributeValue->id ?? '')),
                    'attribute_id' => trim((string) ($attributeValue->attribute_id ?? '')),
                    'attribute_name' => trim((string) ($attributeValue->attribute?->name ?? '')),
                    'value' => trim((string) ($attributeValue->value ?? '')),
                ];
            }

            $variantRows[] = [
                'id' => (int) $variant->id,
                'enabled' => !Schema::hasColumn('product_variants', 'enabled') || (bool) ($variant->enabled ?? true),
                'stock' => $variantStock,
                'original_stock' => $variantStock,
                'reserved_flash_sale_quantity' => $reservedVariantQuantity,
                'price' => $variant->price !== null ? (float) $variant->price : null,
                'sale_price' => $variant->sale_price !== null ? (float) $variant->sale_price : null,
                'commission_rate' => $variant->commission_rate !== null ? (float) $variant->commission_rate : 0,
                'commission_rate_mode' => $this->resolveVariantCommissionMode($variant, $product),
                'image' => $variant->image !== null ? trim((string) $variant->image) : null,
                'ticket_reward_quantity' => (int) ($variant->ticket_reward_quantity ?? 0),
                'attributes' => $attributeRows,
            ];
        }

        return [
            'id' => (string) $product->id,
            'name' => (string) $product->name,
            'status' => $this->resolveStoreAdminProductStatus($product, $menuApprovedProductIds),
            'slug' => trim((string) ($product->slug ?? '')) ?: null,
            'sku' => trim((string) ($product->sku ?? '')) ?: null,
            'store_id' => trim((string) ($product->store_id ?? '')) ?: null,
            'category_id' => trim((string) ($product->category_id ?? '')) ?: null,
            'thumbnail' => trim((string) ($product->thumbnail ?? '')) ?: null,
            'images' => is_array($images) ? array_values(array_filter($images, fn ($item) => is_string($item) && trim($item) !== '')) : [],
            'price' => $product->price !== null ? (float) $product->price : null,
            'sale_price' => $product->sale_price !== null ? (float) $product->sale_price : null,
            'commission_rate' => $product->commission_rate !== null ? (float) $product->commission_rate : 0,
            ...$this->buildAffiliateCommissionResponseFields($product),
            'ticket_reward_enabled' => Schema::hasColumn('products', 'ticket_reward_enabled') ? (bool) ($product->ticket_reward_enabled ?? ((int) ($product->ticket_reward_quantity ?? 0) > 0)) : ((int) ($product->ticket_reward_quantity ?? 0) > 0),
            'ticket_type_code' => Schema::hasColumn('products', 'ticket_type_code') ? ($product->ticket_type_code !== null ? (string) $product->ticket_type_code : null) : null,
            'stock' => $productStock,
            'original_stock' => $productStock,
            'reserved_flash_sale_quantity' => $reservedProductQuantity,
            'variant_count' => (int) ($product->variants_count ?? 0),
            'variants' => $variantRows,
            'category' => $product->category?->name ?? null,
            'created_at' => optional($product->created_at)->toIso8601String(),
            'updated_at' => optional($product->updated_at)->toIso8601String(),
            'toppings' => $product->relationLoaded('toppings')
                ? $product->toppings->map(function (Topping $topping) {
                    return $this->serializeTopping($topping);
                })->values()->toArray()
                : [],
        ];
    }

    private function serializeTopping(Topping $topping): array
    {
        return [
            'id' => $topping->id,
            'name' => $topping->name,
            'price' => (float) $topping->price,
            'sale_price' => $topping->sale_price !== null ? (float) $topping->sale_price : null,
            'stock' => $topping->stock !== null ? (int) $topping->stock : null,
            'sku' => $topping->sku,
            'enabled' => (bool) $topping->enabled,
        ];
    }

    private function syncProductToppings(Product $product, Store $store, mixed $toppingsInput): void
    {
        $rows = $this->normalizeToppingRows($toppingsInput);
        $existingIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $rows
        ), static fn (int $id): bool => $id > 0)));

        $existingById = empty($existingIds)
            ? collect()
            : Topping::query()
                ->where('store_id', $store->id)
                ->whereIn('id', $existingIds)
                ->get()
                ->keyBy(static fn (Topping $topping): int => (int) $topping->id);

        $toppingIds = [];
        foreach ($rows as $row) {
            $attributes = [
                'store_id' => $store->id,
                'name' => $row['name'],
                'price' => $row['price'],
                'sale_price' => $row['sale_price'],
                'stock' => $row['stock'],
                'sku' => $row['sku'],
                'enabled' => $row['enabled'],
            ];

            $topping = $row['id'] ? $existingById->get((int) $row['id']) : null;
            if ($topping instanceof Topping) {
                $topping->fill($attributes);
                if ($topping->isDirty()) {
                    $topping->save();
                }
            } else {
                $topping = Topping::query()->create($attributes);
            }

            $toppingIds[(int) $topping->id] = (int) $topping->id;
        }

        $product->toppings()->sync(array_values($toppingIds));
    }

    private function normalizeToppingRows(mixed $toppingsInput): array
    {
        if (!is_array($toppingsInput)) {
            return [];
        }

        $rows = [];
        foreach ($toppingsInput as $toppingData) {
            if (!is_array($toppingData)) {
                continue;
            }

            $toppingName = trim((string) data_get($toppingData, 'name', ''));
            if ($toppingName === '') {
                continue;
            }

            $toppingId = data_get($toppingData, 'id');
            $rows[] = [
                'id' => is_numeric($toppingId) ? (int) $toppingId : null,
                'name' => $toppingName,
                'price' => $this->normalizeNullableNumber(data_get($toppingData, 'price', 0)) ?? 0,
                'sale_price' => $this->normalizeNullableNumber(data_get($toppingData, 'sale_price')),
                'stock' => $this->normalizeNullableInteger(data_get($toppingData, 'stock')),
                'sku' => $this->normalizeNullableString(data_get($toppingData, 'sku')),
                'enabled' => (bool) data_get($toppingData, 'enabled', true),
            ];
        }

        return $rows;
    }

    public function show(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('products')) {
            return $this->errorResponse('TABLE_NOT_FOUND', 'Bang products chua ton tai.', 500, true, true);
        }

        $productQuery = Product::query()->with([
            'variants.attributeValues.attribute',
            'category',
            'group',
            'toppings',
        ]);

        $product = $productQuery->find($id);
        if (!$product instanceof Product) {
            return $this->errorResponse('PRODUCT_NOT_FOUND', 'Khong tim thay san pham.', 404, true, true);
        }

        if (Schema::hasColumn('products', 'store_id') && (string) $product->store_id !== (string) $store->id) {
            return $this->errorResponse('FORBIDDEN_PRODUCT_SCOPE', 'Ban khong co quyen truy cap san pham nay.', 403, true, true);
        }

        $coverPath = $this->normalizeStoragePath($product->thumbnail);
        $galleryPaths = $this->normalizeImageList(is_array($product->images) ? $product->images : []);

        $images = [];
        $seenPaths = [];
        $index = 0;

        if ($coverPath !== null) {
            $seenPaths[$coverPath] = true;
            $images[] = [
                'id' => 'img_cover',
                'url' => $this->toPublicUrl($coverPath),
                'is_cover' => true,
                'sort_order' => 0,
            ];
            $index = 1;
        }

        foreach ($galleryPaths as $galleryPath) {
            if (isset($seenPaths[$galleryPath])) {
                continue;
            }

            $seenPaths[$galleryPath] = true;
            $images[] = [
                'id' => 'img_' . $index,
                'url' => $this->toPublicUrl($galleryPath),
                'is_cover' => false,
                'sort_order' => $index,
            ];
            $index++;
        }

        $variantList = $product->variants->values();
        $attributes = $this->extractProductAttributes($variantList);
        $ticketRewardQuantity = Schema::hasColumn('products', 'ticket_reward_quantity')
            ? (int) ($product->ticket_reward_quantity ?? 0)
            : 0;
        $ticketTypeCode = Schema::hasColumn('products', 'ticket_type_code')
            ? ($product->ticket_type_code !== null ? (string) $product->ticket_type_code : null)
            : null;
        $ticketBasePrice = (float) ($product->sale_price ?? $product->price ?? 0);
        $ticketPricingPreview = app(TicketPricingService::class)->calculatePreview($ticketBasePrice, $ticketRewardQuantity, $ticketTypeCode);
        $statistics = $this->resolveProductStatistics($product);

        $group1 = $attributes[0] ?? null;
        $group2 = $attributes[1] ?? null;

        $hasVariantAxis = $group1 !== null || $group2 !== null;
        $rows = [];
        foreach ($variantList as $variant) {
            $selected = [];
            $selectedPairs = [];

            foreach ($variant->attributeValues as $attributeValue) {
                $attributeName = trim((string) ($attributeValue->attribute?->name ?? ''));
                $attributeId = $attributeValue->attribute_id;
                $value = trim((string) $attributeValue->value);
                if ($value === '') {
                    continue;
                }

                if ($attributeName !== '') {
                    $selected[$attributeName] = $value;
                } elseif ($attributeId !== null) {
                    $selected[(string) $attributeId] = $value;
                }

                $selectedPairs[] = $value;
            }

            $variantKey = null;
            if (Schema::hasColumn('product_variants', 'variant_key')) {
                $variantKey = $variant->getAttribute('variant_key');
            }
            if (!is_string($variantKey) || trim($variantKey) === '') {
                $keyParts = array_map(static fn ($s): string => Str::slug((string) $s, '_'), $selectedPairs);
                $keyParts = array_values(array_filter($keyParts, static fn ($s): bool => $s !== ''));
                $variantKey = !empty($keyParts) ? implode('__', $keyParts) : 'variant_' . $variant->id;
            }

            $sku = null;
            if (Schema::hasColumn('product_variants', 'sku')) {
                $sku = $variant->getAttribute('sku');
            }
            if (!is_string($sku) || trim($sku) === '') {
                $sku = ($product->sku ? $product->sku . '-' : 'SKU-') . $variant->id;
            }

            $rows[] = [
                'id' => $variant->id,
                'key' => $variantKey,
                'enabled' => !Schema::hasColumn('product_variants', 'enabled') || (bool) ($variant->enabled ?? true),
                'price' => $variant->price !== null ? (float) $variant->price : ($product->price !== null ? (float) $product->price : null),
                'sale_price' => $variant->sale_price !== null ? (float) $variant->sale_price : ($product->sale_price !== null ? (float) $product->sale_price : null),
                'stock' => (int) ($variant->stock ?? 0),
                'sku' => $sku,
                'image' => $variant->image ? $this->toPublicUrl((string) $variant->image) : null,
                'commission_rate' => $variant->commission_rate !== null ? (float) $variant->commission_rate : (float) ($product->commission_rate ?? 0),
                'commission_rate_mode' => $this->resolveVariantCommissionMode($variant, $product),
                'ticket_reward_quantity' => (int) ($variant->ticket_reward_quantity ?? 0),
                'attribute_values' => $selected,
            ];
        }

        return response()->json([
            'data' => [
                'product_id' => $product->id,
                'basic_info' => [
                    'name' => $product->name,
                    'category_id' => $product->category_id,
                    'description' => $product->content,
                    'summary' => $product->summary,
                    'thumbnail' => $coverPath ? $this->toPublicUrl($coverPath) : null,
                    'images' => $images,
                    'ticket_reward_quantity' => $ticketRewardQuantity,
                    'ticket_reward_enabled' => Schema::hasColumn('products', 'ticket_reward_enabled') ? (bool) ($product->ticket_reward_enabled ?? ($ticketRewardQuantity > 0)) : ($ticketRewardQuantity > 0),
                    'ticket_type_code' => $ticketTypeCode,
                    ...$this->buildAffiliateCommissionResponseFields($product),
                ],
                'ticket_pricing_preview' => $ticketPricingPreview,
                'statistics' => $statistics,
                'variants' => [
                    'enabled' => $hasVariantAxis,
                    'group1' => $group1,
                    'group2' => $group2,
                ],
                'pricing' => [
                    'rows' => $rows,
                    ...$this->buildAffiliateCommissionResponseFields($product),
                ],
                'toppings' => $product->toppings->map(function (Topping $topping) {
                    return $this->serializeTopping($topping);
                })->values(),
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function resolveProductStatistics(Product $product): array
    {
        $soldQuantity = 0;
        if (Schema::hasTable('order_items')
            && Schema::hasTable('orders')
            && Schema::hasColumn('order_items', 'product_id')) {
            $soldQuery = DB::table('order_items as oi')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->where('oi.product_id', $product->id)
                ->where('o.store_id', $product->store_id);

            if (Schema::hasColumn('orders', 'status')) {
                $soldQuery->whereIn(
                    DB::raw('LOWER(COALESCE(o.status, ""))'),
                    ['delivered', 'completed', 'call_completed']
                );
            }

            $soldQuantity = (int) $soldQuery->sum('oi.quantity');
        }

        $reviewCount = 0;
        $averageRating = 0.0;
        if (Schema::hasTable('store_reviews') && Schema::hasColumn('store_reviews', 'product_id')) {
            $reviewQuery = DB::table('store_reviews')->where('product_id', $product->id);
            if (Schema::hasColumn('store_reviews', 'is_hidden')) {
                $reviewQuery->where('is_hidden', false);
            }

            $reviewMetrics = $reviewQuery
                ->selectRaw('COUNT(*) as review_count, AVG(rating) as average_rating')
                ->first();
            $reviewCount = (int) ($reviewMetrics->review_count ?? 0);
            $averageRating = $reviewCount > 0
                ? round((float) ($reviewMetrics->average_rating ?? 0), 1)
                : 0.0;
        }

        return [
            'sold_quantity' => max(0, $soldQuantity),
            'view_count' => Schema::hasColumn('products', 'view_count')
                ? max(0, (int) ($product->view_count ?? 0))
                : 0,
            'review_count' => max(0, $reviewCount),
            'average_rating' => max(0.0, min(5.0, $averageRating)),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('products')) {
            return $this->errorResponse('TABLE_NOT_FOUND', 'Bang products chua ton tai.', 500, true, true);
        }

        if (Schema::hasColumn('stores', 'status')) {
            $storeStatus = mb_strtolower(trim((string) ($store->status ?? '')));
            if (!in_array($storeStatus, ['active', 'approved'], true)) {
                return $this->errorResponse('STORE_NOT_ACTIVE', 'Cua hang chua o trang thai hoat dong, khong the gui san pham duyet.', 403, true, true);
            }
        }

        $validator = Validator::make($request->all(), [
            'draft_id' => ['nullable', 'string'],
            'payload' => ['nullable', 'array'],
            'basic_info' => ['nullable', 'array'],
            'variants' => ['nullable', 'array'],
            'pricing' => ['nullable', 'array'],
            'ticket_reward_quantity' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'ticket_reward_enabled' => ['nullable', 'boolean'],
            'ticket_type_code' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'Du lieu dau vao khong hop le.',
                422,
                true,
                true,
                $validator->errors()->toArray()
            );
        }

        $validated = $validator->validated();
        $draft = null;

        if (!empty($validated['draft_id'])) {
            if (!Schema::hasTable('product_drafts')) {
                return $this->errorResponse('TABLE_NOT_FOUND', 'Bang product_drafts chua ton tai.', 500, true, true);
            }

            $draft = ProductDraft::query()
                ->where('id', $validated['draft_id'])
                ->where('store_id', $store->id)
                ->first();

            if (!$draft) {
                return $this->errorResponse('DRAFT_NOT_FOUND', 'Khong tim thay ban nhap hoac ban nhap khong thuoc cua hang hien tai.', 404, true, true);
            }
        }

        $payload = $this->resolvePayload($request, $draft);
        $basicInfo = $this->normalizeBasicInfo($payload);

        // Support direct field from frontend even when basic_info exists.
        if (!array_key_exists('ticket_reward_quantity', $basicInfo) && array_key_exists('ticket_reward_quantity', $payload)) {
            $basicInfo['ticket_reward_quantity'] = $payload['ticket_reward_quantity'];
        }
        if (!array_key_exists('ticket_type_code', $basicInfo) && array_key_exists('ticket_type_code', $payload)) {
            $basicInfo['ticket_type_code'] = $payload['ticket_type_code'];
        }
        $this->hydrateBusinessFields($basicInfo, $payload);

        $variantGroups = $this->normalizeVariantGroups($payload);
        if (empty($variantGroups) && $draft) {
            $variantGroups = $this->resolveVariantGroupsFromDraftConfig((string) $draft->id);
        }
        $pricingRows = $this->normalizePricingRows($payload);
        $pricingBulk = $this->normalizePricingBulk($payload);

        if (trim((string) ($basicInfo['sku'] ?? '')) === '' && trim((string) ($pricingBulk['sku'] ?? '')) !== '') {
            $basicInfo['sku'] = (string) $pricingBulk['sku'];
        }

        $publishErrors = $this->validateNormalizedPayload($store, $basicInfo, $variantGroups, $pricingRows);
        if (!empty($publishErrors)) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu san pham khong hop le.', 422, true, true, $publishErrors);
        }

        logger()->info('publish.debug', [
            'variantGroups' => $variantGroups,
            'pricingRowsFirst' => $pricingRows[0] ?? null,
        ]);

        $productId = null;
        $createdVariantCount = 0;
        $createdAttributeCount = 0;

        DB::transaction(function () use (
            $store,
            $basicInfo,
            $variantGroups,
            $pricingRows,
            $pricingBulk,
            $payload,
            &$productId,
            &$createdVariantCount,
            &$createdAttributeCount,
            $draft
        ): void {
            $thumbnail = $this->normalizeStoragePath($basicInfo['thumbnail'] ?? null);
            $images = $this->normalizeImageList($basicInfo['images'] ?? []);

            $hasVariantRows = !empty($pricingRows);
            $basePrice = $hasVariantRows
                ? $this->normalizeNullableNumber($basicInfo['price'] ?? null)
                : $this->normalizeNullableNumber($pricingBulk['price'] ?? ($basicInfo['price'] ?? null));
            $baseSalePrice = $hasVariantRows
                ? $this->normalizeNullableNumber($basicInfo['sale_price'] ?? null)
                : $this->normalizeNullableNumber($pricingBulk['sale_price'] ?? ($basicInfo['sale_price'] ?? null));
            $productCommissionMode = $this->normalizeCommissionModeForValue(
                $basicInfo['commission_rate_mode'] ?? null,
                $basicInfo['commission_rate'] ?? null
            );

            $content = $this->normalizeNullableString($basicInfo['content'] ?? null);
            if ($content === null) {
                $content = $this->normalizeNullableString($basicInfo['description'] ?? null);
            }
            $summary = $this->normalizeNullableString($basicInfo['summary'] ?? null);
            if ($summary === null && $content !== null) {
                $summary = trim(preg_replace('/\s+/', ' ', strip_tags($content)));
                if (mb_strlen($summary) > 250) {
                    $summary = mb_substr($summary, 0, 247) . '...';
                }
                if ($summary === '') {
                    $summary = null;
                }
            }

            $productData = [
                'name' => trim((string) ($basicInfo['name'] ?? '')),
                'summary' => $summary,
                'content' => $content,
                'thumbnail' => $thumbnail,
                'images' => $images,
                'price' => $basePrice,
                'sale_price' => $baseSalePrice,
                'slug' => $this->resolveUniqueSlug((string) ($basicInfo['slug'] ?? ''), (string) ($basicInfo['name'] ?? ''), null),
                'sku' => $this->resolveUniqueSku((string) ($basicInfo['sku'] ?? ''), (string) ($basicInfo['name'] ?? '')),
                'store_id' => $store->id,
                'category_id' => $this->normalizeNullableInteger($basicInfo['category_id'] ?? null),
                'group_id' => $this->normalizeNullableInteger($basicInfo['group_id'] ?? null),
                'stock' => 0,
                'commission_rate' => $this->normalizeCommissionValue($basicInfo['commission_rate'] ?? null) ?? 0,
            ];
            if (Schema::hasColumn('products', 'commission_rate_mode')) {
                $productData['commission_rate_mode'] = $productCommissionMode;
            }

            if (Schema::hasColumn('products', 'ticket_reward_quantity')) {
                $productData['ticket_reward_quantity'] = $this->normalizeTicketRewardQuantity($basicInfo['ticket_reward_quantity'] ?? 0);
            }
            if (Schema::hasColumn('products', 'ticket_reward_enabled')) {
                $productData['ticket_reward_enabled'] = (bool) ($basicInfo['ticket_reward_enabled'] ?? $payload['ticket_reward_enabled'] ?? ($productData['ticket_reward_quantity'] ?? 0) > 0);
            }
            if (Schema::hasColumn('products', 'ticket_type_code')) {
                $productData['ticket_type_code'] = $this->normalizeTicketTypeCode($basicInfo['ticket_type_code'] ?? null);
            }

            if (Schema::hasColumn('products', 'status')) {
                $productData['status'] = 'pending_approval';
            }
            if (Schema::hasColumn('products', 'published_at')) {
                $productData['published_at'] = null;
            }
            if (Schema::hasColumn('products', 'approved_by')) {
                $productData['approved_by'] = null;
            }
            if (Schema::hasColumn('products', 'approved_at')) {
                $productData['approved_at'] = null;
            }

            $product = Product::query()->create($productData);
            $productId = $product->id;

            $attributeCache = [];
            $attributeValueCache = [];
            $attributes = [];

            // 1. Batch fetch & insert Attributes
            $attributeNames = [];
            foreach ($variantGroups as $group) {
                $name = trim((string) ($group['name'] ?? ''));
                if ($name !== '') {
                    $attributeNames[] = $name;
                }
            }
            $attributeNames = array_values(array_unique($attributeNames));

            if (!empty($attributeNames)) {
                $existingAttributes = Attribute::query()
                    ->where('store_id', $store->id)
                    ->whereIn('name', $attributeNames)
                    ->get()
                    ->keyBy(fn($attr) => mb_strtolower($attr->name));

                $now = now();
                $attributesToInsert = [];
                foreach ($attributeNames as $name) {
                    $key = mb_strtolower($name);
                    if (!$existingAttributes->has($key)) {
                        $attributesToInsert[] = [
                            'store_id' => $store->id,
                            'name' => $name,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        $createdAttributeCount++;
                    }
                }

                if (!empty($attributesToInsert)) {
                    Attribute::query()->insert($attributesToInsert);
                    $existingAttributes = Attribute::query()
                        ->where('store_id', $store->id)
                        ->whereIn('name', $attributeNames)
                        ->get()
                        ->keyBy(fn($attr) => mb_strtolower($attr->name));
                }

                foreach ($existingAttributes as $key => $attr) {
                    $attributeCache[$key] = $attr;
                }
            }

            // 2. Normalize and prepare attributes array
            foreach ($variantGroups as $groupIndex => $group) {
                $attributeName = trim((string) ($group['name'] ?? ''));
                if ($attributeName === '') {
                    continue;
                }

                $attributeKey = mb_strtolower($attributeName);
                $attribute = $attributeCache[$attributeKey] ?? null;
                if ($attribute) {
                    $attributes[$groupIndex] = [
                        'record' => $attribute,
                        'name' => $attributeName,
                        'options' => $this->normalizeOptions($group['options'] ?? [], $attributeName),
                    ];
                }
            }

            // 3. Batch fetch & insert AttributeValues
            $allValuesByAttrId = [];
            foreach ($attributes as $groupIndex => $attrInfo) {
                $attr = $attrInfo['record'];
                foreach ($attrInfo['options'] as $optionValue) {
                    $allValuesByAttrId[$attr->id][] = $optionValue;
                }
            }

            if (!empty($allValuesByAttrId)) {
                $existingValuesQuery = AttributeValue::query();
                $existingValuesQuery->where(function ($query) use ($allValuesByAttrId) {
                    foreach ($allValuesByAttrId as $attrId => $vals) {
                        $query->orWhere(function ($q) use ($attrId, $vals) {
                            $q->where('attribute_id', $attrId)->whereIn('value', $vals);
                        });
                    }
                });
                $existingValues = $existingValuesQuery->get()->groupBy('attribute_id');

                $valuesToInsert = [];
                $now = now();
                foreach ($allValuesByAttrId as $attrId => $vals) {
                    $existingForAttr = $existingValues->get($attrId, collect())->keyBy(fn($v) => mb_strtolower($v->value));
                    foreach ($vals as $val) {
                        $key = mb_strtolower($val);
                        if (!$existingForAttr->has($key)) {
                            $valuesToInsert[] = [
                                'attribute_id' => $attrId,
                                'value' => $val,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                }

                if (!empty($valuesToInsert)) {
                    AttributeValue::query()->insert($valuesToInsert);
                    
                    // Re-fetch all to populate cache
                    $existingValuesQuery = AttributeValue::query();
                    $existingValuesQuery->where(function ($query) use ($allValuesByAttrId) {
                        foreach ($allValuesByAttrId as $attrId => $vals) {
                            $query->orWhere(function ($q) use ($attrId, $vals) {
                                $q->where('attribute_id', $attrId)->whereIn('value', $vals);
                            });
                        }
                    });
                    $existingValues = $existingValuesQuery->get()->groupBy('attribute_id');
                }

                foreach ($existingValues as $attrId => $vals) {
                    foreach ($vals as $val) {
                        $attributeValueCache[$attrId][mb_strtolower($val->value)] = $val;
                    }
                }
            }

            $combinations = $this->buildCombinations(array_values($attributes));
            $hasVariantTicketTypeColumn = Schema::hasColumn('product_variants', 'ticket_type_code');
            $hasVariantCommissionModeColumn = Schema::hasColumn('product_variants', 'commission_rate_mode');
            $hasVariantEnabledColumn = Schema::hasColumn('product_variants', 'enabled');

            $pivotData = [];
            $now = now();

            if (!empty($pricingRows)) {
                foreach ($pricingRows as $index => $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $rowEnabled = $this->isPricingRowEnabled($row);
                    if (!$rowEnabled && !$hasVariantEnabledColumn) {
                        continue;
                    }

                    $rowCommissionMode = $this->normalizeCommissionModeForValue(
                        $row['commission_rate_mode'] ?? null,
                        $row['commission_rate'] ?? null,
                        $productCommissionMode
                    );
                    $variantData = [
                        'stock' => $this->normalizeInteger($row['stock'] ?? 0),
                        'price' => $this->normalizeNullableNumber($row['price'] ?? null),
                        'sale_price' => $this->normalizeNullableNumber($row['sale_price'] ?? null),
                        'commission_rate' => $this->normalizeCommissionValue($row['commission_rate'] ?? null) ?? ($product->commission_rate ?? 0),
                        'image' => $this->normalizeStoragePath($row['image'] ?? null),
                        'ticket_reward_quantity' => $this->normalizeInteger($row['ticket_reward_quantity'] ?? ($row['ticketQuantity'] ?? 0)),
                    ];
                    if ($hasVariantEnabledColumn) {
                        $variantData['enabled'] = $rowEnabled;
                    }
                    if ($hasVariantCommissionModeColumn) {
                        $variantData['commission_rate_mode'] = $rowCommissionMode;
                    }
                    if ($hasVariantTicketTypeColumn) {
                        $variantData['ticket_type_code'] = $this->normalizeTicketTypeCode($row['ticket_type_code'] ?? null);
                    }

                    $variant = $product->variants()->create($variantData);

                    $attributeValueIds = $this->resolveVariantAttributeValueIds($row, $attributes, $attributeValueCache, $combinations, $index);
                    logger()->info('publish.variant.attach', [
                        'row' => $row,
                        'attributeValueIds' => $attributeValueIds,
                    ]);
                    
                    if (!empty($attributeValueIds)) {
                        foreach (array_unique($attributeValueIds) as $attrValId) {
                            $pivotData[] = [
                                'variant_id' => $variant->id,
                                'attribute_value_id' => $attrValId,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }

                    $createdVariantCount++;
                }

                if ($createdVariantCount === 1 && Schema::hasColumn('products', 'ticket_reward_quantity') && $product->ticket_reward_quantity === 0) {
                    $singleVariant = $product->variants()->first();
                    if ($singleVariant !== null && $singleVariant->ticket_reward_quantity > 0) {
                        $product->ticket_reward_quantity = $singleVariant->ticket_reward_quantity;
                    }
                }
            }

            if ($createdVariantCount === 0) {
                $bulkCommissionMode = $this->normalizeCommissionModeForValue(
                    $pricingBulk['commission_rate_mode'] ?? null,
                    $pricingBulk['commission_rate'] ?? ($basicInfo['commission_rate'] ?? null),
                    $productCommissionMode
                );
                $variantData = [
                    'stock' => $this->normalizeInteger($pricingBulk['stock'] ?? ($basicInfo['stock'] ?? 0)),
                    'price' => $this->normalizeNullableNumber($pricingBulk['price'] ?? ($basicInfo['price'] ?? null)),
                    'sale_price' => $this->normalizeNullableNumber($pricingBulk['sale_price'] ?? ($basicInfo['sale_price'] ?? null)),
                    'commission_rate' => $this->normalizeCommissionValue($pricingBulk['commission_rate'] ?? ($basicInfo['commission_rate'] ?? null)) ?? ($product->commission_rate ?? 0),
                    'image' => $this->normalizeStoragePath($basicInfo['thumbnail'] ?? null),
                    'ticket_reward_quantity' => $this->normalizeInteger($pricingBulk['ticket_reward_quantity'] ?? ($basicInfo['ticket_reward_quantity'] ?? 0)),
                ];
                if ($hasVariantCommissionModeColumn) {
                    $variantData['commission_rate_mode'] = $bulkCommissionMode;
                }
                if ($hasVariantEnabledColumn) {
                    $variantData['enabled'] = true;
                }
                if ($hasVariantTicketTypeColumn) {
                    $variantData['ticket_type_code'] = $this->normalizeTicketTypeCode($pricingBulk['ticket_type_code'] ?? ($basicInfo['ticket_type_code'] ?? null));
                }

                $variant = $product->variants()->create($variantData);
                $createdVariantCount = 1;

                $fallbackAttributeValueIds = $this->resolveVariantAttributeValueIds([], $attributes, $attributeValueCache, $combinations, 0);
                if (!empty($fallbackAttributeValueIds)) {
                    foreach (array_unique($fallbackAttributeValueIds) as $attrValId) {
                        $pivotData[] = [
                            'variant_id' => $variant->id,
                            'attribute_value_id' => $attrValId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }

            // Batch insert pivot records
            if (!empty($pivotData)) {
                DB::table('variant_attribute_values')->insert($pivotData);
            }

            $totalStock = array_reduce($pricingRows, function (int $carry, $row): int {
                if (!is_array($row)) {
                    return $carry;
                }

                if (!$this->isPricingRowEnabled($row)) {
                    return $carry;
                }

                return $carry + $this->normalizeInteger($row['stock'] ?? 0);
            }, 0);

            if (!empty($pricingRows)) {
                $product->stock = $totalStock;
                if ($product->price === null) {
                    $firstRow = collect($pricingRows)->first(fn ($row): bool => is_array($row) && $this->isPricingRowEnabled($row));
                    if (is_array($firstRow)) {
                        $product->price = $this->normalizeNullableNumber($firstRow['price'] ?? null);
                        $product->sale_price = $this->normalizeNullableNumber($firstRow['sale_price'] ?? null);
                    }
                }
            } else {
                $product->stock = $this->normalizeInteger($pricingBulk['stock'] ?? ($basicInfo['stock'] ?? 0));
            }

            if ($product->sku === null || $product->sku === '') {
                $product->sku = $this->resolveUniqueSku('', (string) ($basicInfo['name'] ?? ''));
            }

            if ($product->slug === null || $product->slug === '') {
                $product->slug = $this->resolveUniqueSlug('', (string) ($basicInfo['name'] ?? ''), $product->id);
            }

            $this->syncProductToppings($product, $store, data_get($payload, 'toppings'));

            $this->syncProductBusinessFieldsFromVariants($product);
            $product->save();

            if ($draft) {
                $draft->delete();
            }
        });

        if ($productId === null) {
            return $this->errorResponse('PUBLISH_FAILED', 'Khong the tao san pham moi.', 500, true, true);
        }

        $product = Product::query()->with(['category', 'group', 'variants.attributeValues.attribute', 'store', 'toppings'])->find($productId);
        if (!$product instanceof Product) {
            return $this->errorResponse('PUBLISH_FAILED', 'Khong the tai lai san pham vua tao.', 500, true, true);
        }

        $this->recordApprovalSubmissionIfPending($product, $user);

        app(NotificationService::class)->create([
            'title' => 'Sản phẩm cần duyệt',
            'message' => sprintf('Cửa hàng %s đã tạo sản phẩm "%s" và cần admin duyệt.',
                $store->name ?? 'Store',
                $product->name
            ),
            'audience_scope' => 'admin_shared',
            'action_url' => '/admin/stores/' . $store->id . '/edit?active_tab=store-product-approvals',
        ]);

        if (Schema::hasColumn('products', 'status')) {
            $pendingApprovalCount = Product::query()
                ->where('store_id', $store->id)
                ->where('status', 'pending_approval')
                ->count();

            event(new ProductApprovalUpdated(
                (string) $store->id,
                $pendingApprovalCount,
                'created',
                (string) $product->id,
                (string) ($product->status ?? 'pending_approval')
            ));
        }

        return response()->json([
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'status' => Schema::hasColumn('products', 'status') ? ($product->status ?? 'pending_approval') : 'pending_approval',
                'slug' => $product->slug,
                'sku' => $product->sku,
                'store_id' => $product->store_id,
                'category_id' => $product->category_id,
                'group_id' => $product->group_id,
                'thumbnail' => $product->thumbnail,
                'images' => $product->images ?? [],
                'price' => isset($product->price) ? (float) $product->price : null,
                'sale_price' => isset($product->sale_price) ? (float) $product->sale_price : null,
                'stock' => (int) $product->stock,
                'commission_rate' => isset($product->commission_rate) ? (float) $product->commission_rate : 0,
                ...$this->buildAffiliateCommissionResponseFields($product),
                'ticket_reward_quantity' => Schema::hasColumn('products', 'ticket_reward_quantity') ? (int) ($product->ticket_reward_quantity ?? 0) : 0,
                'ticket_reward_enabled' => Schema::hasColumn('products', 'ticket_reward_enabled') ? (bool) ($product->ticket_reward_enabled ?? ((int) ($product->ticket_reward_quantity ?? 0) > 0)) : ((int) ($product->ticket_reward_quantity ?? 0) > 0),
                'ticket_type_code' => Schema::hasColumn('products', 'ticket_type_code') ? ($product->ticket_type_code !== null ? (string) $product->ticket_type_code : null) : null,
                'variant_count' => (int) $product->variants->count(),
                'published_at' => null,
                'draft_deleted' => $draft !== null,
                'variants' => $product->variants->map(function (ProductVariant $variant) use ($product): array {
                    return [
                        'id' => $variant->id,
                        'enabled' => !Schema::hasColumn('product_variants', 'enabled') || (bool) ($variant->enabled ?? true),
                        'stock' => (int) $variant->stock,
                        'price' => isset($variant->price) ? (float) $variant->price : null,
                        'sale_price' => isset($variant->sale_price) ? (float) $variant->sale_price : null,
                        'commission_rate' => isset($variant->commission_rate) ? (float) $variant->commission_rate : 0,
                        'commission_rate_mode' => $this->resolveVariantCommissionMode($variant, $product),
                        'image' => $variant->image,
                        'ticket_reward_quantity' => (int) ($variant->ticket_reward_quantity ?? 0),
                        'ticket_type_code' => $variant->ticket_type_code,
                        'ticket_pricing_preview' => app(TicketPricingService::class)->calculatePreview(
                            (float) ($variant->sale_price ?? $variant->price ?? 0),
                            (int) ($variant->ticket_reward_quantity ?? 0),
                            $variant->ticket_type_code
                        ),
                        'attributes' => $variant->attributeValues->map(static function (AttributeValue $attributeValue): array {
                            return [
                                'id' => $attributeValue->id,
                                'attribute_id' => $attributeValue->attribute_id,
                                'attribute_name' => $attributeValue->attribute?->name,
                                'value' => $attributeValue->value,
                            ];
                        })->values(),
                    ];
                })->values(),
                'toppings' => $product->toppings->map(function (Topping $topping) {
                    return $this->serializeTopping($topping);
                })->values(),
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('products')) {
            return $this->errorResponse('TABLE_NOT_FOUND', 'Bang products chua ton tai.', 500, true, true);
        }

        $product = Product::query()->with('variants.attributeValues.attribute')->find($id);
        if (!$product instanceof Product) {
            return $this->errorResponse('PRODUCT_NOT_FOUND', 'Khong tim thay san pham.', 404, true, true);
        }

        if (Schema::hasColumn('products', 'store_id') && (string) $product->store_id !== (string) $store->id) {
            return $this->errorResponse('FORBIDDEN_PRODUCT_SCOPE', 'Ban khong co quyen cap nhat san pham nay.', 403, true, true);
        }

        $validator = Validator::make($request->all(), [
            'payload' => ['nullable', 'array'],
            'basic_info' => ['nullable', 'array'],
            'variants' => ['nullable', 'array'],
            'pricing' => ['nullable', 'array'],
            'ticket_reward_quantity' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'ticket_reward_enabled' => ['nullable', 'boolean'],
            'ticket_type_code' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'Du lieu dau vao khong hop le.',
                422,
                true,
                true,
                $validator->errors()->toArray()
            );
        }

        $payload = $this->resolvePayload($request, null);
        $basicInfo = $this->normalizeBasicInfo($payload);

        // Support direct field from frontend even when basic_info exists.
        if (!array_key_exists('ticket_reward_quantity', $basicInfo) && array_key_exists('ticket_reward_quantity', $payload)) {
            $basicInfo['ticket_reward_quantity'] = $payload['ticket_reward_quantity'];
        }
        if (!array_key_exists('ticket_type_code', $basicInfo) && array_key_exists('ticket_type_code', $payload)) {
            $basicInfo['ticket_type_code'] = $payload['ticket_type_code'];
        }
        $this->hydrateBusinessFields($basicInfo, $payload);

        $variantGroups = $this->normalizeVariantGroups($payload);
        $pricingRows = $this->normalizePricingRows($payload);

        $statusOnlyPayload = $this->validateStatusOnlyUpdatePayload($payload);
        if ($statusOnlyPayload['is_status_only']) {
            if (!empty($statusOnlyPayload['errors'])) {
                return $this->errorResponse('VALIDATION_ERROR', 'Trang thai san pham khong hop le.', 422, true, true, $statusOnlyPayload['errors']);
            }

            $status = (string) $statusOnlyPayload['status'];
            if (in_array($status, Product::APPROVED_STATUSES, true) && !$this->productHasSellableStock($product)) {
                return $this->errorResponse(
                    'PRODUCT_OUT_OF_STOCK',
                    'San pham da het hang. Vui long cap nhat ton kho truoc khi bat hoat dong.',
                    422,
                    true,
                    true,
                    [
                        'stock' => [
                            'San pham da het hang.',
                        ],
                    ]
                );
            }

            $this->applyStatusOnlyUpdate($product, $status, $user);

            $product->refresh();
            $product->load(['category', 'group', 'variants.attributeValues.attribute', 'store']);

            $this->recordApprovalSubmissionIfPending($product, $user);

            if (Schema::hasColumn('products', 'status')) {
                $pendingApprovalCount = Product::query()
                    ->where('store_id', $store->id)
                    ->where('status', 'pending_approval')
                    ->count();

                event(new ProductApprovalUpdated(
                    (string) $store->id,
                    $pendingApprovalCount,
                    'updated',
                    (string) $product->id,
                    (string) ($product->status ?? 'pending_approval')
                ));
            }

            event(new StoreAdminProductUpdated(
                (string) ($product->store_id ?? ''),
                (string) $product->id,
                'status_updated'
            ));

            return $this->buildUpdateResponse($product, $store, false);
        }

        if (array_key_exists('status', $payload)) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'Trang thai phe duyet san pham chi duoc cap nhat boi admin he thong.',
                422,
                true,
                true,
                [
                    'status' => [
                        'Trang thai phe duyet san pham chi duoc cap nhat boi admin he thong.',
                    ],
                ]
            );
        }

        $stockOnlyPayload = $this->validateStockOnlyUpdatePayload($payload);
        if ($stockOnlyPayload['is_stock_only']) {
            if (!empty($stockOnlyPayload['errors'])) {
                return $this->errorResponse('VALIDATION_ERROR', 'Du lieu cap nhat ton kho khong hop le.', 422, true, true, $stockOnlyPayload['errors']);
            }

            $updateErrors = $this->applyStockOnlyUpdate($product, $stockOnlyPayload);
            if (!empty($updateErrors)) {
                return $this->errorResponse('VALIDATION_ERROR', 'Du lieu cap nhat ton kho khong hop le.', 422, true, true, $updateErrors);
            }

            $product->refresh();
            $product->load(['category', 'group', 'variants.attributeValues.attribute', 'store']);

            event(new StoreAdminProductUpdated(
                (string) ($product->store_id ?? ''),
                (string) $product->id,
                'stock_updated'
            ));

            return $this->buildUpdateResponse($product, $store, true);
        }

        $isPartialUpdate = !$this->hasExplicitProductUpdatePayload($payload);

        if (!$isPartialUpdate) {
            $updateErrors = $this->validateNormalizedPayload($store, $basicInfo, $variantGroups, $pricingRows);
            $this->validateSkuForUpdate($store, $product, $pricingRows, $basicInfo, $updateErrors);

            if (!empty($updateErrors)) {
                return $this->errorResponse('VALIDATION_ERROR', 'Du lieu cap nhat san pham khong hop le.', 422, true, true, $updateErrors);
            }
        }

        DB::transaction(function () use ($store, $user, $product, $basicInfo, $variantGroups, $pricingRows, $payload): void {
            $thumbnail = $this->normalizeStoragePath($basicInfo['thumbnail'] ?? null);
            $images = $this->normalizeImageList($basicInfo['images'] ?? []);

            $content = $this->normalizeNullableString($basicInfo['content'] ?? null);
            if ($content === null) {
                $content = $this->normalizeNullableString($basicInfo['description'] ?? null);
            }
            $summary = $this->normalizeNullableString($basicInfo['summary'] ?? null);
            if ($summary === null && $content !== null) {
                $summary = trim(preg_replace('/\s+/', ' ', strip_tags($content)));
                if (mb_strlen($summary) > 250) {
                    $summary = mb_substr($summary, 0, 247) . '...';
                }
                if ($summary === '') {
                    $summary = null;
                }
            }

            $product->name = trim((string) ($basicInfo['name'] ?? $product->name));
            $product->summary = $summary ?? $product->summary;
            $product->content = $content;
            if (array_key_exists('thumbnail', $basicInfo)) {
                $product->thumbnail = $thumbnail;
            }
            if (array_key_exists('images', $basicInfo)) {
                $product->images = $images;
            }
            $product->category_id = $this->normalizeNullableInteger($basicInfo['category_id'] ?? $product->category_id);
            $product->group_id = $this->normalizeNullableInteger($basicInfo['group_id'] ?? $product->group_id);
            $product->commission_rate = $this->normalizeCommissionValue($basicInfo['commission_rate'] ?? $product->commission_rate) ?? 0;
            if (Schema::hasColumn('products', 'commission_rate_mode')) {
                $product->commission_rate_mode = $this->normalizeCommissionModeForValue(
                    $basicInfo['commission_rate_mode'] ?? null,
                    $basicInfo['commission_rate'] ?? null,
                    $product->commission_rate_mode ?? 'percent'
                );
            }
            if (Schema::hasColumn('products', 'ticket_reward_quantity')) {
                $product->ticket_reward_quantity = $this->normalizeTicketRewardQuantity($basicInfo['ticket_reward_quantity'] ?? $product->ticket_reward_quantity ?? 0);
            }
            if (Schema::hasColumn('products', 'ticket_reward_enabled')) {
                $product->ticket_reward_enabled = (bool) ($basicInfo['ticket_reward_enabled'] ?? $payload['ticket_reward_enabled'] ?? (($product->ticket_reward_quantity ?? 0) > 0));
            }
            if (Schema::hasColumn('products', 'ticket_type_code')) {
                $product->ticket_type_code = $this->normalizeTicketTypeCode($basicInfo['ticket_type_code'] ?? $product->ticket_type_code ?? null);
            }
            $newStatus = null;
            if (Schema::hasColumn('products', 'status')) {
                if (array_key_exists('status', $payload)) {
                    $newStatus = trim((string) ($payload['status'] ?? 'pending_approval'));
                } else {
                    // Revert to pending_approval for any store admin edits
                    $newStatus = 'pending_approval';
                }

                if ($newStatus !== null) {
                    $product->status = $newStatus;
                }
            }

            $shouldResetApprovals = ($newStatus === 'pending_approval') || 
                                    (!array_key_exists('status', $payload) && $product->status === 'pending_approval');

            if ($shouldResetApprovals) {
                if (Schema::hasColumn('products', 'approved_by')) {
                    $product->approved_by = null;
                }
                if (Schema::hasColumn('products', 'approved_at')) {
                    $product->approved_at = null;
                }
                if (Schema::hasColumn('products', 'published_at')) {
                    $product->published_at = null;
                }
                if (Schema::hasColumn('products', 'rejection_reason')) {
                    $product->rejection_reason = null;
                }
            } else if (array_key_exists('status', $payload)) {
                $isPublished = trim((string) ($payload['status'] ?? '')) === 'published';
                if (Schema::hasColumn('products', 'approved_by')) {
                    $product->approved_by = $isPublished ? ($user->id ?? null) : null;
                }
                if (Schema::hasColumn('products', 'approved_at')) {
                    $product->approved_at = $isPublished ? now() : null;
                }
                if (Schema::hasColumn('products', 'published_at')) {
                    $product->published_at = $isPublished ? now() : null;
                }
                if (Schema::hasColumn('products', 'rejection_reason')) {
                    $product->rejection_reason = null;
                }
            }

            $incomingProductPrice = $this->normalizeNullableNumber($basicInfo['price'] ?? null);
            $incomingProductSalePrice = $this->normalizeNullableNumber($basicInfo['sale_price'] ?? null);
            if ($incomingProductPrice !== null) {
                $product->price = $incomingProductPrice;
            }
            if ($incomingProductSalePrice !== null) {
                $product->sale_price = $incomingProductSalePrice;
            }

            if (array_key_exists('slug', $basicInfo)) {
                $product->slug = $this->resolveUniqueSlug((string) ($basicInfo['slug'] ?? ''), (string) $product->name, $product->id);
            } elseif ($product->slug === null || trim((string) $product->slug) === '') {
                $product->slug = $this->resolveUniqueSlug('', (string) $product->name, $product->id);
            }

            if (array_key_exists('sku', $basicInfo)) {
                $product->sku = $this->resolveUniqueSkuForUpdate((string) ($basicInfo['sku'] ?? ''), (string) $product->name, $product->id);
            } elseif ($product->sku === null || trim((string) $product->sku) === '') {
                $product->sku = $this->resolveUniqueSkuForUpdate('', (string) $product->name, $product->id);
            }

            if (array_key_exists('toppings', $payload)) {
                $this->syncProductToppings($product, $store, data_get($payload, 'toppings'));
            }

            $explicitVariantPayload = array_key_exists('pricing', $payload) || array_key_exists('rows', $payload) || array_key_exists('variants', $payload);
            if (!$explicitVariantPayload) {
                if (array_key_exists('ticket_reward_enabled', $payload) && !($payload['ticket_reward_enabled'] ?? false)) {
                    $product->variants()->update(['ticket_reward_quantity' => 0]);
                }

                $this->syncProductBusinessFieldsFromVariants($product);
                $product->save();
                return;
            }

            $hasVariantAxis = !empty($variantGroups);
            $attributeValueCache = [];
            $attributes = [];
            if ($hasVariantAxis) {
                $attributeCache = [];
                
                // 1. Batch fetch & insert Attributes
                $attributeNames = [];
                foreach ($variantGroups as $group) {
                    $name = trim((string) ($group['name'] ?? ''));
                    if ($name !== '') {
                        $attributeNames[] = $name;
                    }
                }
                $attributeNames = array_values(array_unique($attributeNames));

                if (!empty($attributeNames)) {
                    $existingAttributes = Attribute::query()
                        ->where('store_id', $store->id)
                        ->whereIn('name', $attributeNames)
                        ->get()
                        ->keyBy(fn($attr) => mb_strtolower($attr->name));

                    $now = now();
                    $attributesToInsert = [];
                    foreach ($attributeNames as $name) {
                        $key = mb_strtolower($name);
                        if (!$existingAttributes->has($key)) {
                            $attributesToInsert[] = [
                                'store_id' => $store->id,
                                'name' => $name,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }

                    if (!empty($attributesToInsert)) {
                        Attribute::query()->insert($attributesToInsert);
                        $existingAttributes = Attribute::query()
                            ->where('store_id', $store->id)
                            ->whereIn('name', $attributeNames)
                            ->get()
                            ->keyBy(fn($attr) => mb_strtolower($attr->name));
                    }

                    foreach ($existingAttributes as $key => $attr) {
                        $attributeCache[$key] = $attr;
                    }
                }

                // 2. Normalize and prepare attributes array
                foreach ($variantGroups as $groupIndex => $group) {
                    $attributeName = trim((string) ($group['name'] ?? ''));
                    if ($attributeName === '') {
                        continue;
                    }

                    $attributeKey = mb_strtolower($attributeName);
                    $attribute = $attributeCache[$attributeKey] ?? null;
                    if ($attribute) {
                        $attributes[$groupIndex] = [
                            'record' => $attribute,
                            'name' => $attributeName,
                            'options' => $this->normalizeOptions($group['options'] ?? [], $attributeName),
                        ];
                    }
                }

                // 3. Batch fetch & insert AttributeValues
                $allValuesByAttrId = [];
                foreach ($attributes as $groupIndex => $attrInfo) {
                    $attr = $attrInfo['record'];
                    foreach ($attrInfo['options'] as $optionValue) {
                        $allValuesByAttrId[$attr->id][] = $optionValue;
                    }
                }

                if (!empty($allValuesByAttrId)) {
                    $existingValuesQuery = AttributeValue::query();
                    $existingValuesQuery->where(function ($query) use ($allValuesByAttrId) {
                        foreach ($allValuesByAttrId as $attrId => $vals) {
                            $query->orWhere(function ($q) use ($attrId, $vals) {
                                $q->where('attribute_id', $attrId)->whereIn('value', $vals);
                            });
                        }
                    });
                    $existingValues = $existingValuesQuery->get()->groupBy('attribute_id');

                    $valuesToInsert = [];
                    $now = now();
                    foreach ($allValuesByAttrId as $attrId => $vals) {
                        $existingForAttr = $existingValues->get($attrId, collect())->keyBy(fn($v) => mb_strtolower($v->value));
                        foreach ($vals as $val) {
                            $key = mb_strtolower($val);
                            if (!$existingForAttr->has($key)) {
                                $valuesToInsert[] = [
                                    'attribute_id' => $attrId,
                                    'value' => $val,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ];
                            }
                        }
                    }

                    if (!empty($valuesToInsert)) {
                        AttributeValue::query()->insert($valuesToInsert);
                        
                        // Re-fetch all to populate cache
                        $existingValuesQuery = AttributeValue::query();
                        $existingValuesQuery->where(function ($query) use ($allValuesByAttrId) {
                            foreach ($allValuesByAttrId as $attrId => $vals) {
                                $query->orWhere(function ($query2) use ($attrId, $vals) {
                                    $query2->where('attribute_id', $attrId)->whereIn('value', $vals);
                                });
                            }
                        });
                        $existingValues = $existingValuesQuery->get()->groupBy('attribute_id');
                    }

                    foreach ($existingValues as $attrId => $vals) {
                        foreach ($vals as $val) {
                            $attributeValueCache[$attrId][mb_strtolower($val->value)] = $val;
                        }
                    }
                }
            }

            $hasVariantSkuColumn = Schema::hasColumn('product_variants', 'sku');
            $hasVariantKeyColumn = Schema::hasColumn('product_variants', 'variant_key');
            $hasVariantCommissionModeColumn = Schema::hasColumn('product_variants', 'commission_rate_mode');
            $hasVariantEnabledColumn = Schema::hasColumn('product_variants', 'enabled');
            $productCommissionMode = $this->resolveProductCommissionMode($product);

            $existingVariants = $product->variants()->with('attributeValues.attribute')->get();
            $existingByKey = [];
            foreach ($existingVariants as $existingVariant) {
                $existingKey = $this->resolveExistingVariantKey($existingVariant);
                if ($existingKey !== null && $existingKey !== '') {
                    $existingByKey[$existingKey] = $existingVariant;
                }
            }

            $keepVariantIds = [];
            $updatedVariantIds = [];

            if ($hasVariantAxis && !empty($pricingRows)) {
                $pivotData = [];
                $now = now();
                foreach ($pricingRows as $index => $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $rowEnabled = $this->isPricingRowEnabled($row);
                    if (!$rowEnabled && !$hasVariantEnabledColumn) {
                        continue;
                    }

                    $incomingKey = $this->resolveIncomingVariantKey($row, $attributes, $index);
                    $isExisting = isset($existingByKey[$incomingKey]);
                    $variant = $isExisting
                        ? $existingByKey[$incomingKey]
                        : new ProductVariant(['product_id' => $product->id]);

                    $variant->product_id = $product->id;
                    $variant->stock = $this->normalizeInteger($row['stock'] ?? 0);
                    $variant->price = $this->normalizeNullableNumber($row['price'] ?? null);
                    $variant->sale_price = $this->normalizeNullableNumber($row['sale_price'] ?? null);
                    $variant->commission_rate = $this->normalizeCommissionValue($row['commission_rate'] ?? null) ?? ($variant->commission_rate ?? 0);
                    if ($hasVariantCommissionModeColumn) {
                        $variant->commission_rate_mode = $this->normalizeCommissionModeForValue(
                            $row['commission_rate_mode'] ?? null,
                            $row['commission_rate'] ?? null,
                            $variant->commission_rate_mode ?? $productCommissionMode
                        );
                    }
                    $variant->image = $this->normalizeStoragePath($row['image'] ?? null);
                    $variant->ticket_reward_quantity = $this->normalizeInteger($row['ticket_reward_quantity'] ?? ($row['ticketQuantity'] ?? 0));
                    $variant->ticket_type_code = $this->normalizeNullableString($row['ticket_type_code'] ?? null);

                    if ($hasVariantEnabledColumn) {
                        $variant->enabled = $rowEnabled;
                    }

                    if ($hasVariantSkuColumn) {
                        $variant->setAttribute('sku', $this->normalizeNullableString($row['sku'] ?? null));
                    }

                    if ($hasVariantKeyColumn) {
                        $variant->setAttribute('variant_key', $incomingKey);
                    }

                    $variant->save();
                    $keepVariantIds[] = $variant->id;
                    if ($isExisting) {
                        $updatedVariantIds[] = $variant->id;
                    }

                    $selectedValues = $this->normalizeSelectedValues($row['attribute_values'] ?? ($row['attributes'] ?? null), $attributes);
                    if (empty($selectedValues) && $incomingKey !== null && $incomingKey !== '') {
                        $selectedValues = $this->resolveSelectedValuesByVariantKey($incomingKey, $attributes);
                    }

                    $attributeValueIds = [];
                    foreach ($selectedValues as $groupIndex => $selectedValue) {
                        if (!isset($attributes[$groupIndex]['record'])) {
                            continue;
                        }

                        $attribute = $attributes[$groupIndex]['record'];
                        $normalizedValue = mb_strtolower((string) $selectedValue);
                        if (isset($attributeValueCache[$attribute->id][$normalizedValue])) {
                            $attributeValueIds[] = $attributeValueCache[$attribute->id][$normalizedValue]->id;
                        }
                    }

                    if (!empty($attributeValueIds)) {
                        foreach (array_unique($attributeValueIds) as $attrValId) {
                            $pivotData[] = [
                                'variant_id' => $variant->id,
                                'attribute_value_id' => $attrValId,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                }

                // Delete old pivots for updated variants
                if (!empty($updatedVariantIds)) {
                    DB::table('variant_attribute_values')->whereIn('variant_id', $updatedVariantIds)->delete();
                }

                // Batch insert all variant pivot records
                if (!empty($pivotData)) {
                    DB::table('variant_attribute_values')->insert($pivotData);
                }

                if (!empty($keepVariantIds)) {
                    $product->variants()->whereNotIn('id', $keepVariantIds)->delete();
                }

                $product->stock = array_reduce($pricingRows, function (int $carry, $row): int {
                    if (!is_array($row)) {
                        return $carry;
                    }

                    if (!$this->isPricingRowEnabled($row)) {
                        return $carry;
                    }

                    return $carry + $this->normalizeInteger($row['stock'] ?? 0);
                }, 0);

                if ($product->price === null) {
                    $firstRow = collect($pricingRows)->first(fn ($row): bool => is_array($row) && $this->isPricingRowEnabled($row));
                    if (is_array($firstRow)) {
                        $product->price = $this->normalizeNullableNumber($firstRow['price'] ?? null);
                        $product->sale_price = $this->normalizeNullableNumber($firstRow['sale_price'] ?? null);
                    }
                }
            } else {
                $product->variants()->delete();

                $firstRow = !empty($pricingRows) ? $pricingRows[0] : null;

                $defaultVariant = $product->variants()->create([
                    'stock' => $this->normalizeInteger($firstRow['stock'] ?? ($basicInfo['stock'] ?? 0)),
                    'price' => $this->normalizeNullableNumber($firstRow['price'] ?? ($basicInfo['price'] ?? $product->price)),
                    'sale_price' => $this->normalizeNullableNumber($firstRow['sale_price'] ?? ($basicInfo['sale_price'] ?? $product->sale_price)),
                    'commission_rate' => $this->normalizeCommissionValue($firstRow['commission_rate'] ?? ($basicInfo['commission_rate'] ?? $product->commission_rate)) ?? 0,
                    'image' => $firstRow && isset($firstRow['image']) ? $this->normalizeStoragePath($firstRow['image']) : $thumbnail,
                    'ticket_reward_quantity' => $this->normalizeInteger($firstRow['ticket_reward_quantity'] ?? ($firstRow['ticketQuantity'] ?? ($basicInfo['ticket_reward_quantity'] ?? $product->ticket_reward_quantity ?? 0))),
                    'ticket_type_code' => $this->normalizeNullableString($firstRow['ticket_type_code'] ?? ($basicInfo['ticket_type_code'] ?? $product->ticket_type_code ?? null)),
                ]);
                if ($hasVariantCommissionModeColumn) {
                    $defaultVariant->commission_rate_mode = $productCommissionMode;
                    $defaultVariant->save();
                }

                if ($hasVariantEnabledColumn) {
                    $defaultVariant->enabled = true;
                    $defaultVariant->save();
                }

                if ($hasVariantSkuColumn) {
                    $defaultSku = $this->normalizeNullableString($firstRow['sku'] ?? ($basicInfo['sku'] ?? $product->sku));
                    $defaultVariant->setAttribute('sku', $defaultSku);
                    $defaultVariant->save();
                }

                $product->stock = $this->normalizeInteger($firstRow['stock'] ?? ($basicInfo['stock'] ?? 0));
            }

            if (!$explicitVariantPayload) {
                // Preserve existing variants when the request only patches top-level fields.
                $this->syncProductBusinessFieldsFromVariants($product);
                $product->save();
                return;
            }

            $this->syncProductBusinessFieldsFromVariants($product);
            $product->save();
        });

        $product->refresh();
        $product->load(['category', 'group', 'variants.attributeValues.attribute', 'store', 'toppings']);

        $this->recordApprovalSubmissionIfPending($product, $user);

        app(NotificationService::class)->create([
            'title' => 'Sản phẩm cần duyệt',
            'message' => sprintf('Cửa hàng %s đã cập nhật sản phẩm "%s" và cần admin duyệt.',
                $store->name ?? 'Store',
                $product->name
            ),
            'audience_scope' => 'admin_shared',
            'action_url' => '/admin/stores/' . $store->id . '/edit?active_tab=store-product-approvals',
        ]);

        if (Schema::hasColumn('products', 'status')) {
            $pendingApprovalCount = Product::query()
                ->where('store_id', $store->id)
                ->where('status', 'pending_approval')
                ->count();

            event(new ProductApprovalUpdated(
                (string) $store->id,
                $pendingApprovalCount,
                'updated',
                (string) $product->id,
                (string) ($product->status ?? 'pending_approval')
            ));
        }

        event(new StoreAdminProductUpdated(
            (string) ($product->store_id ?? ''),
            (string) $product->id,
            'updated'
        ));

        return $this->buildUpdateResponse($product, $store, false);
    }

    private function buildUpdateResponse(Product $product, Store $store, bool $autoApproved): JsonResponse
    {
        $menuApprovedProductIds = $this->resolveMenuApprovedProductIds($store, [$product->id]);
        $meta = [
            'authenticated' => true,
            'has_store' => true,
            'request_at' => now()->toIso8601String(),
        ];

        if ($autoApproved) {
            $meta['auto_approved'] = true;
        }

        return response()->json([
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'status' => $this->resolveStoreAdminProductStatus($product, $menuApprovedProductIds),
                'slug' => $product->slug,
                'sku' => $product->sku,
                'store_id' => $product->store_id,
                'category_id' => $product->category_id,
                'group_id' => $product->group_id,
                'thumbnail' => $product->thumbnail,
                'images' => $product->images ?? [],
                'price' => isset($product->price) ? (float) $product->price : null,
                'sale_price' => isset($product->sale_price) ? (float) $product->sale_price : null,
                'stock' => (int) $product->stock,
                'commission_rate' => isset($product->commission_rate) ? (float) $product->commission_rate : 0,
                ...$this->buildAffiliateCommissionResponseFields($product),
                'ticket_reward_quantity' => Schema::hasColumn('products', 'ticket_reward_quantity') ? (int) ($product->ticket_reward_quantity ?? 0) : 0,
                'ticket_reward_enabled' => Schema::hasColumn('products', 'ticket_reward_enabled') ? (bool) ($product->ticket_reward_enabled ?? ((int) ($product->ticket_reward_quantity ?? 0) > 0)) : ((int) ($product->ticket_reward_quantity ?? 0) > 0),
                'ticket_type_code' => Schema::hasColumn('products', 'ticket_type_code') ? ($product->ticket_type_code !== null ? (string) $product->ticket_type_code : null) : null,
                'variant_count' => (int) $product->variants->count(),
                'updated_at' => optional($product->updated_at)?->toIso8601String(),
                'toppings' => $product->toppings->map(function (Topping $topping) {
                    return $this->serializeTopping($topping);
                })->values(),
                'variants' => $product->variants->map(function (ProductVariant $variant) use ($product): array {
                    return [
                        'id' => $variant->id,
                        'enabled' => !Schema::hasColumn('product_variants', 'enabled') || (bool) ($variant->enabled ?? true),
                        'stock' => (int) $variant->stock,
                        'price' => isset($variant->price) ? (float) $variant->price : null,
                        'sale_price' => isset($variant->sale_price) ? (float) $variant->sale_price : null,
                        'commission_rate' => isset($variant->commission_rate) ? (float) $variant->commission_rate : 0,
                        'commission_rate_mode' => $this->resolveVariantCommissionMode($variant, $product),
                        'image' => $variant->image,
                        'ticket_reward_quantity' => (int) ($variant->ticket_reward_quantity ?? 0),
                        'ticket_type_code' => $variant->ticket_type_code,
                        'ticket_pricing_preview' => app(TicketPricingService::class)->calculatePreview(
                            (float) ($variant->sale_price ?? $variant->price ?? 0),
                            (int) ($variant->ticket_reward_quantity ?? 0),
                            $variant->ticket_type_code
                        ),
                        'attributes' => $variant->attributeValues->map(static function (AttributeValue $attributeValue): array {
                            return [
                                'id' => $attributeValue->id,
                                'attribute_id' => $attributeValue->attribute_id,
                                'attribute_name' => $attributeValue->attribute?->name,
                                'value' => $attributeValue->value,
                            ];
                        })->values(),
                    ];
                })->values(),
            ],
            'meta' => $meta,
            'errors' => [],
        ]);
    }

    private function syncProductBusinessFieldsFromVariants(Product $product): void
    {
        $variantQuery = $product->variants();
        if (Schema::hasColumn('product_variants', 'enabled')) {
            $variantQuery->where('enabled', true);
        }

        $variantStats = $variantQuery
            ->selectRaw('MAX(COALESCE(commission_rate, 0)) as max_commission_rate')
            ->selectRaw('MAX(COALESCE(ticket_reward_quantity, 0)) as max_ticket_reward_quantity')
            ->first();

        $maxCommissionRate = (float) ($variantStats?->max_commission_rate ?? 0);
        if ($maxCommissionRate > 0) {
            $product->commission_rate = $maxCommissionRate;
            if (Schema::hasColumn('products', 'commission_rate_mode') && Schema::hasColumn('product_variants', 'commission_rate_mode')) {
                $variantCommissionModeQuery = $product->variants()
                    ->where('commission_rate', $maxCommissionRate)
                    ->when(Schema::hasColumn('product_variants', 'enabled'), fn ($query) => $query->where('enabled', true));
                $variantCommissionMode = $variantCommissionModeQuery->value('commission_rate_mode');
                $product->commission_rate_mode = $this->normalizeCommissionMode($variantCommissionMode);
            }
        }

        if (Schema::hasColumn('products', 'ticket_reward_quantity')) {
            $maxTicketRewardQuantity = max(0, (int) ($variantStats?->max_ticket_reward_quantity ?? 0));
            $currentTicketRewardQuantity = max(0, (int) ($product->ticket_reward_quantity ?? 0));
            if ($currentTicketRewardQuantity <= 0 && $maxTicketRewardQuantity > 0) {
                $product->ticket_reward_quantity = $maxTicketRewardQuantity;
                $currentTicketRewardQuantity = $maxTicketRewardQuantity;
            }

            if (Schema::hasColumn('products', 'ticket_reward_enabled')) {
                $product->ticket_reward_enabled = $currentTicketRewardQuantity > 0 || $maxTicketRewardQuantity > 0;
            }
        }

        if (
            Schema::hasColumn('products', 'ticket_type_code')
            && ($product->ticket_type_code === null || trim((string) $product->ticket_type_code) === '')
            && Schema::hasColumn('product_variants', 'ticket_type_code')
        ) {
            $variantTicketTypeCodeQuery = $product->variants()
                ->whereNotNull('ticket_type_code')
                ->where('ticket_type_code', '!=', '')
                ->orderByDesc('ticket_reward_quantity');
            if (Schema::hasColumn('product_variants', 'enabled')) {
                $variantTicketTypeCodeQuery->where('enabled', true);
            }
            $variantTicketTypeCode = $variantTicketTypeCodeQuery->value('ticket_type_code');

            if ($variantTicketTypeCode !== null && trim((string) $variantTicketTypeCode) !== '') {
                $product->ticket_type_code = $this->normalizeTicketTypeCode($variantTicketTypeCode);
            }
        }

        // Sync price and sale_price from first variant if variants exist
        $firstVariant = $product->variants()->first();
        if ($firstVariant) {
            $product->price = $firstVariant->price;
            $product->sale_price = $firstVariant->sale_price;
        }
    }

    private function buildAffiliateCommissionResponseFields(Product $product): array
    {
        $commissionRate = max(0.0, (float) ($product->commission_rate ?? 0));
        $commissionMode = $this->resolveProductCommissionMode($product);

        return [
            'affiliate_commission_enabled' => $commissionRate > 0,
            'affiliate_commission_mode' => $commissionMode,
            'affiliate_commission_type' => $commissionMode,
            'affiliate_commission_value' => $commissionRate,
            'commission_rate_mode' => $commissionMode,
            'affiliate_commission' => [
                'enabled' => $commissionRate > 0,
                'mode' => $commissionMode,
                'type' => $commissionMode,
                'value' => $commissionRate,
            ],
        ];
    }

    private function resolveProductCommissionMode(Product $product): string
    {
        if (Schema::hasColumn('products', 'commission_rate_mode')) {
            return $this->normalizeCommissionMode($product->commission_rate_mode ?? null);
        }

        return 'percent';
    }

    private function resolveVariantCommissionMode(ProductVariant $variant, Product $product): string
    {
        if (Schema::hasColumn('product_variants', 'commission_rate_mode')) {
            return $this->normalizeCommissionMode($variant->commission_rate_mode ?? $this->resolveProductCommissionMode($product));
        }

        return $this->resolveProductCommissionMode($product);
    }

    private function normalizeCommissionMode(mixed $value): string
    {
        $mode = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return in_array($mode, ['fixed', 'amount', 'money', 'cash', 'vnd'], true) ? 'fixed' : 'percent';
    }

    private function normalizeCommissionModeForValue(mixed $mode, mixed $value, string $fallback = 'percent'): string
    {
        if (is_scalar($mode) && trim((string) $mode) !== '') {
            return $this->normalizeCommissionMode($mode);
        }

        $commissionValue = $this->normalizeCommissionValue($value);
        if ($commissionValue !== null && $commissionValue > 100) {
            return 'fixed';
        }

        return $this->normalizeCommissionMode($fallback);
    }

    private function normalizeCommissionValue(mixed $value): ?float
    {
        $normalized = $this->normalizeNullableNumber($value);

        return $normalized === null ? null : max(0.0, $normalized);
    }

    private function resolveMenuApprovedProductIds(Store $store, array $productIds): array
    {
        if (
            !Schema::hasTable('store_menu')
            || !Schema::hasColumn('store_menu', 'store_id')
            || !Schema::hasColumn('store_menu', 'product_id')
        ) {
            return [];
        }

        $normalizedProductIds = array_values(array_unique(array_filter(array_map(
            static fn ($productId): int => (int) $productId,
            $productIds
        ), static fn (int $productId): bool => $productId > 0)));

        if (empty($normalizedProductIds)) {
            return [];
        }

        $menuQuery = DB::table('store_menu')
            ->where('store_id', $store->id)
            ->whereIn('product_id', $normalizedProductIds);

        if (Schema::hasColumn('store_menu', 'is_available')) {
            $menuQuery->where('is_available', true);
        }

        $menuProductIds = $menuQuery
            ->pluck('product_id')
            ->map(static fn ($productId): int => (int) $productId)
            ->filter(static fn (int $productId): bool => $productId > 0)
            ->values()
            ->all();

        return array_fill_keys($menuProductIds, true);
    }

    private function buildFlashSaleInventoryKey(int $productId, ?int $variantId): string
    {
        return $productId . ':' . ($variantId !== null && $variantId > 0 ? $variantId : 'base');
    }

    private function resolveReservedFlashSaleQuantityMap(string $storeId, array $productIds): array
    {
        if (
            !Schema::hasTable('flash_sale_items')
            || !Schema::hasTable('flash_sales')
        ) {
            return [];
        }

        $normalizedProductIds = array_values(array_unique(array_filter(array_map(
            static fn ($productId): int => (int) $productId,
            $productIds
        ), static fn (int $productId): bool => $productId > 0)));

        if (empty($normalizedProductIds)) {
            return [];
        }

        $rows = DB::table('flash_sale_items as fsi')
            ->join('flash_sales as fs', 'fs.id', '=', 'fsi.flash_sale_id')
            ->where('fs.store_id', $storeId)
            ->where('fs.is_active', true)
            ->where('fs.end_time', '>', now(self::FLASH_SALE_TIMEZONE)->toDateTimeString())
            ->whereIn('fsi.product_id', $normalizedProductIds)
            ->groupBy('fsi.product_id', 'fsi.variant_id')
            ->selectRaw('
                fsi.product_id,
                fsi.variant_id,
                COALESCE(SUM(GREATEST(COALESCE(fsi.sale_quantity, 0) - COALESCE(fsi.sold_quantity, 0), 0)), 0) as reserved_quantity
            ')
            ->get();

        $reservedByKey = [];
        foreach ($rows as $row) {
            $productId = (int) ($row->product_id ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $variantId = $row->variant_id !== null ? (int) $row->variant_id : null;
            $reservedQuantity = max(0, (int) ($row->reserved_quantity ?? 0));
            $reservedByKey[$this->buildFlashSaleInventoryKey($productId, $variantId)] =
                (int) (($reservedByKey[$this->buildFlashSaleInventoryKey($productId, $variantId)] ?? 0) + $reservedQuantity);
        }

        return $reservedByKey;
    }

    private function resolveStoreAdminProductStatus(Product $product, array $menuApprovedProductIds = []): string
    {
        $status = Schema::hasColumn('products', 'status')
            ? trim((string) ($product->status ?? ''))
            : '';

        $normalizedStatus = mb_strtolower($status);
        if ($normalizedStatus === '') {
            $status = 'pending_approval';
            $normalizedStatus = 'pending_approval';
        }

        if (Schema::hasColumn('products', 'status')) {
            return $status;
        }

        if (isset($menuApprovedProductIds[(int) $product->id])) {
            return 'published';
        }

        return $status;
    }

    private function recordApprovalSubmissionIfPending(Product $product, mixed $user): void
    {
        $status = mb_strtolower(trim((string) ($product->status ?? 'pending_approval')));
        if ($status !== 'pending_approval') {
            return;
        }

        app(ProductApprovalHistoryService::class)->recordSubmission($product, $user->id ?? null);
    }

    private function validateStatusOnlyUpdatePayload(array $payload): array
    {
        $result = [
            'is_status_only' => false,
            'errors' => [],
            'status' => null,
        ];

        if (count($payload) !== 1 || !array_key_exists('status', $payload)) {
            return $result;
        }

        $result['is_status_only'] = true;
        $status = mb_strtolower(trim((string) ($payload['status'] ?? '')));
        if (in_array($status, Product::APPROVED_STATUSES, true)) {
            $result['errors'][] = [
                'path' => 'status',
                'message' => 'Trang thai phe duyet san pham chi duoc cap nhat boi admin he thong.',
            ];

            return $result;
        }

        $allowedStatuses = ['pending_approval', 'pending', 'draft', 'rejected'];

        if (!in_array($status, $allowedStatuses, true)) {
            $result['errors'][] = [
                'path' => 'status',
                'message' => 'Trang thai san pham khong hop le.',
            ];

            return $result;
        }

        $result['status'] = $status === 'pending' ? 'pending_approval' : $status;

        return $result;
    }

    private function applyStatusOnlyUpdate(Product $product, string $status, mixed $user): void
    {
        DB::transaction(function () use ($product, $status, $user): void {
            $isApprovedStatus = in_array($status, Product::APPROVED_STATUSES, true);

            if (Schema::hasColumn('products', 'status')) {
                $product->status = $status;
            }

            if (Schema::hasColumn('products', 'approved_by')) {
                $product->approved_by = $isApprovedStatus ? ($user->id ?? null) : null;
            }

            if (Schema::hasColumn('products', 'approved_at')) {
                $product->approved_at = $isApprovedStatus ? now() : null;
            }

            if (Schema::hasColumn('products', 'published_at')) {
                $product->published_at = $isApprovedStatus ? now() : null;
            }

            if (Schema::hasColumn('products', 'rejection_reason') && $status !== 'rejected') {
                $product->rejection_reason = null;
            }

            $product->save();
        });
    }

    private function productHasSellableStock(Product $product): bool
    {
        if ((int) ($product->stock ?? 0) > 0) {
            return true;
        }

        if ($product->relationLoaded('variants')) {
            return $product->variants
                ->filter(static fn ($variant): bool => !Schema::hasColumn('product_variants', 'enabled') || (bool) ($variant->enabled ?? true))
                ->sum(static fn ($variant): int => (int) ($variant->stock ?? 0)) > 0;
        }

        $query = $product->variants()->where('stock', '>', 0);
        if (Schema::hasColumn('product_variants', 'enabled')) {
            $query->where('enabled', true);
        }

        return $query->exists();
    }

    private function validateStockOnlyUpdatePayload(array $payload): array
    {
        $result = [
            'is_stock_only' => false,
            'errors' => [],
            'rows' => [],
            'stock' => null,
        ];

        $allowedRoot = ['stock', 'basic_info', 'pricing', 'rows'];
        foreach ($payload as $key => $value) {
            if (!in_array($key, $allowedRoot, true)) {
                return $result;
            }
        }

        $hasStock = false;

        if (array_key_exists('stock', $payload)) {
            if (!$this->isNonNegativeInteger($payload['stock'])) {
                $result['errors'][] = [
                    'path' => 'stock',
                    'message' => 'So luong ton kho phai la so nguyen >= 0.',
                ];
            } else {
                $result['stock'] = max(0, (int) $payload['stock']);
                $hasStock = true;
            }
        }

        $basicInfo = $payload['basic_info'] ?? null;
        if ($basicInfo !== null) {
            if (!is_array($basicInfo)) {
                return $result;
            }

            foreach ($basicInfo as $key => $value) {
                if ($key !== 'stock') {
                    return $result;
                }

                if (!$this->isNonNegativeInteger($value)) {
                    $result['errors'][] = [
                        'path' => 'basic_info.stock',
                        'message' => 'So luong ton kho phai la so nguyen >= 0.',
                    ];
                } else {
                    $result['stock'] = max(0, (int) $value);
                    $hasStock = true;
                }
            }
        }

        $rowsFromPricing = [];
        $pricing = $payload['pricing'] ?? null;
        if ($pricing !== null) {
            if (!is_array($pricing)) {
                return $result;
            }

            foreach ($pricing as $key => $value) {
                if ($key === 'bulk') {
                    if (!is_array($value)) {
                        return $result;
                    }

                    foreach ($value as $bulkKey => $bulkValue) {
                        if ($bulkKey !== 'stock') {
                            return $result;
                        }

                        if (!$this->isNonNegativeInteger($bulkValue)) {
                            $result['errors'][] = [
                                'path' => 'pricing.bulk.stock',
                                'message' => 'So luong ton kho phai la so nguyen >= 0.',
                            ];
                        } else {
                            $result['stock'] = max(0, (int) $bulkValue);
                            $hasStock = true;
                        }
                    }
                } elseif ($key === 'rows') {
                    $rowsFromPricing = $this->validateStockRows($value, 'pricing.rows', $result['errors']);
                } else {
                    return $result;
                }
            }
        }

        $rowsFromRoot = [];
        if (array_key_exists('rows', $payload)) {
            $rowsFromRoot = $this->validateStockRows($payload['rows'], 'rows', $result['errors']);
        }

        if (!empty($rowsFromPricing) && !empty($rowsFromRoot)) {
            return $result;
        }

        $rows = !empty($rowsFromPricing) ? $rowsFromPricing : $rowsFromRoot;
        if (!empty($rows)) {
            $result['rows'] = $rows;
            $hasStock = true;
        }

        $result['is_stock_only'] = $hasStock;

        return $result;
    }

    private function validateStockRows(mixed $rows, string $pathPrefix, array &$errors): array
    {
        if (!is_array($rows)) {
            $errors[] = [
                'path' => $pathPrefix,
                'message' => 'Danh sach ton kho khong hop le.',
            ];
            return [];
        }

        $normalized = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $errors[] = [
                    'path' => $pathPrefix . '.' . $index,
                    'message' => 'Dong ton kho khong hop le.',
                ];
                continue;
            }

            foreach ($row as $key => $value) {
                if (!in_array($key, ['id', 'key', 'variant_key', 'stock'], true)) {
                    return [];
                }
            }

            if (!array_key_exists('stock', $row)) {
                $errors[] = [
                    'path' => $pathPrefix . '.' . $index . '.stock',
                    'message' => 'So luong ton kho bat buoc.',
                ];
                continue;
            }

            if (!$this->isNonNegativeInteger($row['stock'])) {
                $errors[] = [
                    'path' => $pathPrefix . '.' . $index . '.stock',
                    'message' => 'So luong ton kho phai la so nguyen >= 0.',
                ];
                continue;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    private function applyStockOnlyUpdate(Product $product, array $stockPayload): array
    {
        $rows = $stockPayload['rows'] ?? [];
        $stock = $stockPayload['stock'] ?? null;
        $errors = [];

        if (!empty($rows)) {
            $variants = $product->variants()->get();
            $variantsById = [];
            $variantsByKey = [];

            foreach ($variants as $variant) {
                $variantsById[$variant->id] = $variant;
                $variantKey = $this->resolveExistingVariantKey($variant);
                if ($variantKey !== null && $variantKey !== '') {
                    $variantsByKey[$variantKey] = $variant;
                }
            }

            foreach ($rows as $index => $row) {
                $variant = null;
                if (isset($row['id'])) {
                    $variant = $variantsById[(int) $row['id']] ?? null;
                }

                if ($variant === null) {
                    $key = $row['key'] ?? $row['variant_key'] ?? null;
                    if (is_string($key) && $key !== '') {
                        $variant = $variantsByKey[$key] ?? null;
                    }
                }

                if (!$variant) {
                    $errors[] = [
                        'path' => 'pricing.rows.' . $index,
                        'message' => 'Khong tim thay bien the can cap nhat ton kho.',
                    ];
                }
            }

            if (!empty($errors)) {
                return $errors;
            }

            DB::transaction(function () use ($product, $rows, $variantsById, $variantsByKey): void {
                foreach ($rows as $row) {
                    $variant = null;
                    if (isset($row['id'])) {
                        $variant = $variantsById[(int) $row['id']] ?? null;
                    }

                    if ($variant === null) {
                        $key = $row['key'] ?? $row['variant_key'] ?? null;
                        if (is_string($key) && $key !== '') {
                            $variant = $variantsByKey[$key] ?? null;
                        }
                    }

                    if (!$variant) {
                        continue;
                    }

                    $variant->stock = $this->normalizeInteger($row['stock'] ?? 0);
                    $variant->save();
                }

                $stockQuery = $product->variants();
                if (Schema::hasColumn('product_variants', 'enabled')) {
                    $stockQuery->where('enabled', true);
                }
                $product->stock = (int) $stockQuery->sum('stock');
                $product->save();
            });

            return [];
        }

        if ($stock === null) {
            return [];
        }

        DB::transaction(function () use ($product, $stock): void {
            $product->stock = $this->normalizeInteger($stock);
            $product->save();

            if ($product->variants()->exists()) {
                $product->variants()->update(['stock' => $product->stock]);
            }
        });

        return [];
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('products')) {
            return $this->errorResponse('TABLE_NOT_FOUND', 'Bang products chua ton tai.', 500, true, true);
        }

        $product = Product::query()->find($id);
        if (!$product instanceof Product) {
            return $this->errorResponse('PRODUCT_NOT_FOUND', 'Khong tim thay san pham.', 404, true, true);
        }

        if (Schema::hasColumn('products', 'store_id') && (string) $product->store_id !== (string) $store->id) {
            return $this->errorResponse('FORBIDDEN_PRODUCT_SCOPE', 'Ban khong co quyen xoa san pham nay.', 403, true, true);
        }

        $productName = (string) ($product->name ?? '');

        DB::transaction(function () use ($product, $store): void {
            if (Schema::hasTable('store_menu')) {
                $menuQuery = DB::table('store_menu')->where('store_id', (string) $store->id);
                if (Schema::hasColumn('store_menu', 'product_id')) {
                    $menuQuery->where('product_id', (int) $product->id)->delete();
                }
            }

            $product->delete();
        });

        return response()->json([
            'data' => [
                'id' => $id,
                'name' => $productName,
                'deleted' => true,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function resolvePayload(Request $request, ?ProductDraft $draft): array
    {
        $payload = [];

        if ($draft && is_array($draft->payload)) {
            $payload = $draft->payload;
        }

        $requestPayload = $request->input('payload');
        if (is_array($requestPayload)) {
            $payload = array_replace_recursive($payload, $requestPayload);
        }

        $directPayload = $request->except(['draft_id', 'payload']);
        if (!empty($directPayload)) {
            $payload = array_replace_recursive($payload, $directPayload);
        }

        return $payload;
    }

    private function normalizeBasicInfo(array $payload): array
    {
        $basicInfo = data_get($payload, 'basic_info');
        if (is_array($basicInfo) && !empty($basicInfo)) {
            if (!array_key_exists('content', $basicInfo) && array_key_exists('description', $basicInfo)) {
                $basicInfo['content'] = $basicInfo['description'];
            }
            if (!array_key_exists('commission_rate', $basicInfo)) {
                $basicInfo['commission_rate'] = $basicInfo['affiliate_commission_value']
                    ?? $basicInfo['commission_value']
                    ?? $basicInfo['commissionRate']
                    ?? $basicInfo['affiliateCommissionValue']
                    ?? data_get($basicInfo, 'affiliate_commission.value')
                    ?? data_get($basicInfo, 'affiliateCommission.value')
                    ?? data_get($basicInfo, 'commission.value')
                    ?? null;
            }
            if (!array_key_exists('commission_rate_mode', $basicInfo)) {
                $basicInfo['commission_rate_mode'] = $basicInfo['affiliate_commission_mode']
                    ?? $basicInfo['affiliate_commission_type']
                    ?? $basicInfo['affiliateCommissionMode']
                    ?? $basicInfo['affiliateCommissionType']
                    ?? $basicInfo['commission_type']
                    ?? $basicInfo['commissionRateMode']
                    ?? data_get($basicInfo, 'affiliate_commission.mode')
                    ?? data_get($basicInfo, 'affiliate_commission.type')
                    ?? data_get($basicInfo, 'affiliateCommission.mode')
                    ?? data_get($basicInfo, 'affiliateCommission.type')
                    ?? data_get($basicInfo, 'commission.mode')
                    ?? data_get($basicInfo, 'commission.type')
                    ?? null;
            }
            return $basicInfo;
        }

        $keys = [
            'name',
            'summary',
            'content',
            'description',
            'thumbnail',
            'images',
            'price',
            'sale_price',
            'slug',
            'sku',
            'category_id',
            'category_name',
            'category',
            'group_id',
            'stock',
            'commission_rate',
            'affiliate_commission_value',
            'commission_value',
            'commissionRate',
            'affiliateCommissionValue',
            'commission_rate_mode',
            'commissionRateMode',
            'affiliate_commission_mode',
            'affiliate_commission_type',
            'affiliateCommissionMode',
            'affiliateCommissionType',
            'commission_type',
            'ticket_reward_quantity',
            'ticket_type_code',
        ];

        $normalized = array_intersect_key($payload, array_flip($keys));
        if (!array_key_exists('content', $normalized) && array_key_exists('description', $normalized)) {
            $normalized['content'] = $normalized['description'];
        }
        if (!array_key_exists('commission_rate', $normalized)) {
            $normalized['commission_rate'] = $normalized['affiliate_commission_value']
                ?? $normalized['commission_value']
                ?? $normalized['commissionRate']
                ?? $normalized['affiliateCommissionValue']
                ?? null;
        }
        if (!array_key_exists('commission_rate_mode', $normalized)) {
            $normalized['commission_rate_mode'] = $normalized['affiliate_commission_mode']
                ?? $normalized['affiliate_commission_type']
                ?? $normalized['affiliateCommissionMode']
                ?? $normalized['affiliateCommissionType']
                ?? $normalized['commission_type']
                ?? $normalized['commissionRateMode']
                ?? null;
        }

        return $normalized;
    }

    private function hydrateBusinessFields(array &$basicInfo, array $payload): void
    {
        $ticketRewardQuantity = $this->firstPayloadValue([$basicInfo, $payload], [
            'ticket_reward_quantity',
            'ticketRewardQuantity',
            'ticket_quantity',
            'ticketQuantity',
            'ticket_reward.ticket_reward_quantity',
            'ticket_reward.ticketRewardQuantity',
            'ticket_reward.ticket_count',
            'ticket_reward.ticketCount',
            'ticket_reward.quantity',
            'ticketReward.ticket_reward_quantity',
            'ticketReward.ticketRewardQuantity',
            'ticketReward.ticket_count',
            'ticketReward.ticketCount',
            'ticketReward.quantity',
            'pricing.ticket_reward_quantity',
            'pricing.ticketRewardQuantity',
            'pricing.ticket_quantity',
            'pricing.ticketQuantity',
            'pricing.ticket_reward.ticket_reward_quantity',
            'pricing.ticket_reward.ticketRewardQuantity',
            'pricing.ticket_reward.ticket_count',
            'pricing.ticket_reward.ticketCount',
            'pricing.ticket_reward.quantity',
            'pricing.ticketReward.ticket_reward_quantity',
            'pricing.ticketReward.ticketRewardQuantity',
            'pricing.ticketReward.ticket_count',
            'pricing.ticketReward.ticketCount',
            'pricing.ticketReward.quantity',
            'basic_info.ticket_reward_quantity',
            'basic_info.ticketRewardQuantity',
            'basic_info.ticket_reward.ticket_reward_quantity',
            'basic_info.ticket_reward.quantity',
        ]);

        if ($ticketRewardQuantity !== null) {
            $currentTicketQuantity = $this->normalizeTicketRewardQuantity($basicInfo['ticket_reward_quantity'] ?? null);
            $nextTicketQuantity = $this->normalizeTicketRewardQuantity($ticketRewardQuantity);
            if (!array_key_exists('ticket_reward_quantity', $basicInfo) || $currentTicketQuantity === 0 || $nextTicketQuantity === 0) {
                $basicInfo['ticket_reward_quantity'] = $nextTicketQuantity;
            }
        }

        $ticketTypeCode = $this->firstPayloadValue([$basicInfo, $payload], [
            'ticket_type_code',
            'ticketTypeCode',
            'ticket_reward.ticket_type_code',
            'ticket_reward.ticketTypeCode',
            'ticketReward.ticket_type_code',
            'ticketReward.ticketTypeCode',
            'pricing.ticket_type_code',
            'pricing.ticketTypeCode',
            'pricing.ticket_reward.ticket_type_code',
            'pricing.ticket_reward.ticketTypeCode',
            'pricing.ticketReward.ticket_type_code',
            'pricing.ticketReward.ticketTypeCode',
            'basic_info.ticket_type_code',
            'basic_info.ticketTypeCode',
        ]);

        if ($ticketTypeCode !== null && (!array_key_exists('ticket_type_code', $basicInfo) || $basicInfo['ticket_type_code'] === null || $basicInfo['ticket_type_code'] === '')) {
            $basicInfo['ticket_type_code'] = $ticketTypeCode;
        }

        $commissionRate = $this->firstPayloadValue([$basicInfo, $payload], [
            'affiliate_commission_value',
            'affiliateCommissionValue',
            'affiliate_commission.value',
            'affiliateCommission.value',
            'commission_value',
            'commissionValue',
            'commission.value',
            'pricing.affiliate_commission_value',
            'pricing.affiliateCommissionValue',
            'pricing.affiliate_commission.value',
            'pricing.affiliateCommission.value',
            'pricing.commission_value',
            'pricing.commissionValue',
            'pricing.commission.value',
            'basic_info.affiliate_commission_value',
            'basic_info.affiliateCommissionValue',
            'basic_info.affiliate_commission.value',
            'basic_info.affiliateCommission.value',
            'basic_info.commission_value',
            'basic_info.commissionValue',
            'basic_info.commission.value',
            'commission_rate',
            'commissionRate',
            'pricing.commission_rate',
            'pricing.commissionRate',
            'basic_info.commission_rate',
            'basic_info.commissionRate',
        ]);

        if ($commissionRate !== null) {
            $basicInfo['commission_rate'] = $this->normalizeCommissionValue($commissionRate) ?? 0;
        }

        $commissionMode = $this->firstPayloadValue([$basicInfo, $payload], [
            'commission_rate_mode',
            'commissionRateMode',
            'affiliate_commission_mode',
            'affiliateCommissionMode',
            'affiliate_commission_type',
            'affiliateCommissionType',
            'commission_type',
            'commissionType',
            'affiliate_commission.mode',
            'affiliate_commission.type',
            'affiliateCommission.mode',
            'affiliateCommission.type',
            'commission.mode',
            'commission.type',
            'pricing.commission_rate_mode',
            'pricing.commissionRateMode',
            'pricing.affiliate_commission_mode',
            'pricing.affiliateCommissionMode',
            'pricing.affiliate_commission_type',
            'pricing.affiliateCommissionType',
            'pricing.commission_type',
            'pricing.commissionType',
            'pricing.affiliate_commission.mode',
            'pricing.affiliate_commission.type',
            'pricing.affiliateCommission.mode',
            'pricing.affiliateCommission.type',
            'pricing.commission.mode',
            'pricing.commission.type',
            'basic_info.commission_rate_mode',
            'basic_info.commissionRateMode',
            'basic_info.affiliate_commission_mode',
            'basic_info.affiliateCommissionMode',
            'basic_info.affiliate_commission_type',
            'basic_info.affiliateCommissionType',
            'basic_info.commission_type',
            'basic_info.commissionType',
            'basic_info.affiliate_commission.mode',
            'basic_info.affiliate_commission.type',
            'basic_info.affiliateCommission.mode',
            'basic_info.affiliateCommission.type',
            'basic_info.commission.mode',
            'basic_info.commission.type',
        ]);

        if ($commissionMode !== null) {
            $basicInfo['commission_rate_mode'] = $this->normalizeCommissionMode($commissionMode);
        } elseif (($this->normalizeCommissionValue($basicInfo['commission_rate'] ?? null) ?? 0) > 100) {
            $basicInfo['commission_rate_mode'] = 'fixed';
        }

        if (!array_key_exists('ticket_reward_enabled', $basicInfo) && array_key_exists('ticket_reward_enabled', $payload)) {
            $basicInfo['ticket_reward_enabled'] = $payload['ticket_reward_enabled'];
        }

        if (!array_key_exists('ticket_reward_enabled', $basicInfo)) {
            $basicInfo['ticket_reward_enabled'] = $this->normalizeTicketRewardQuantity($basicInfo['ticket_reward_quantity'] ?? 0) > 0;
        }
    }

    private function firstPayloadValue(array $sources, array $paths): mixed
    {
        $missing = new \stdClass();

        foreach ($paths as $path) {
            foreach ($sources as $source) {
                if (!is_array($source)) {
                    continue;
                }

                $value = data_get($source, $path, $missing);
                if ($value !== $missing && $value !== null && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function hasExplicitProductUpdatePayload(array $payload): bool
    {
        return array_key_exists('basic_info', $payload)
            || array_key_exists('variants', $payload)
            || array_key_exists('pricing', $payload)
            || array_key_exists('rows', $payload);
    }

    private function normalizeVariantGroups(array $payload): array
    {
        $variantPayload = data_get($payload, 'variants', []);
        $groups = [];

        if (is_array($variantPayload)) {
            $groupAliases = [
                ['group1', 'group_1'],
                ['group2', 'group_2'],
                ['group3', 'group_3'],
            ];

            foreach ($groupAliases as $index => $aliases) {
                foreach ($aliases as $groupKey) {
                    if (array_key_exists($groupKey, $variantPayload)) {
                        $groups[] = $this->normalizeAttributeGroup($variantPayload[$groupKey], 'Attribute ' . ($index + 1));
                        break;
                    }
                }
            }

            if (empty($groups) && isset($variantPayload['groups']) && is_array($variantPayload['groups'])) {
                foreach ($variantPayload['groups'] as $index => $group) {
                    $groups[] = $this->normalizeAttributeGroup($group, 'Attribute ' . ($index + 1), is_string($index) ? $index : null);
                }
            }

            if (empty($groups) && isset($variantPayload['attributes']) && is_array($variantPayload['attributes'])) {
                foreach ($variantPayload['attributes'] as $index => $group) {
                    $groups[] = $this->normalizeAttributeGroup($group, 'Attribute ' . ($index + 1), is_string($index) ? $index : null);
                }
            }
        }

        if (empty($groups) && isset($payload['attributes']) && is_array($payload['attributes'])) {
            foreach ($payload['attributes'] as $index => $group) {
                $groups[] = $this->normalizeAttributeGroup($group, 'Attribute ' . ($index + 1), is_string($index) ? $index : null);
            }
        }

        return array_values(array_filter($groups, static fn (array $group): bool => trim((string) ($group['name'] ?? '')) !== ''));
    }

    private function resolveVariantGroupsFromDraftConfig(string $draftId): array
    {
        if (!Schema::hasTable('product_draft_variant_configs') || !Schema::hasTable('product_draft_variant_options')) {
            return [];
        }

        $config = DB::table('product_draft_variant_configs')
            ->where('draft_id', $draftId)
            ->first();

        if (!$config || !(bool) ($config->enabled ?? false)) {
            return [];
        }

        $options = DB::table('product_draft_variant_options')
            ->where('config_id', $config->id)
            ->orderBy('group_no')
            ->orderBy('sort_order')
            ->get();

        $group1Options = [];
        $group2Options = [];

        foreach ($options as $row) {
            $value = trim((string) ($row->option_value ?? ''));
            if ($value === '') {
                continue;
            }

            if ((int) $row->group_no === 1) {
                $group1Options[] = $value;
                continue;
            }

            if ((int) $row->group_no === 2) {
                $group2Options[] = $value;
            }
        }

        $groups = [];
        $group1Name = trim((string) ($config->group_1_name ?? ''));
        if ($group1Name !== '' && !empty($group1Options)) {
            $groups[] = [
                'name' => $group1Name,
                'options' => array_values(array_unique($group1Options)),
            ];
        }

        $group2Name = trim((string) ($config->group_2_name ?? ''));
        if ($group2Name !== '' && !empty($group2Options)) {
            $groups[] = [
                'name' => $group2Name,
                'options' => array_values(array_unique($group2Options)),
            ];
        }

        return $groups;
    }

    private function normalizeAttributeGroup(mixed $group, string $fallbackName, ?string $explicitName = null): array
    {
        if (!is_array($group)) {
            return [
                'name' => $explicitName !== null && trim($explicitName) !== '' ? trim($explicitName) : $fallbackName,
                'options' => $this->normalizeOptions($group, $fallbackName),
            ];
        }

        $name = trim((string) ($group['name'] ?? $group['label'] ?? $explicitName ?? $fallbackName));
        $options = $group['options'] ?? $group['values'] ?? $group['items'] ?? [];

        if (empty($options) && $this->isListArray($group)) {
            $options = $group;
        }

        return [
            'name' => $name !== '' ? $name : $fallbackName,
            'options' => $this->normalizeOptions($options, $name !== '' ? $name : $fallbackName),
        ];
    }

    private function normalizeOptions(mixed $options, string $fallbackName): array
    {
        if (!is_array($options)) {
            $options = [$options];
        }

        $normalized = [];
        foreach ($options as $option) {
            if (is_array($option)) {
                $value = $option['value'] ?? $option['label'] ?? $option['name'] ?? null;
            } else {
                $value = $option;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        if (empty($normalized)) {
            $normalized[] = $fallbackName;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizePricingRows(array $payload): array
    {
        $pricing = data_get($payload, 'pricing', []);
        if (is_array($pricing) && isset($pricing['rows']) && is_array($pricing['rows'])) {
            return array_values(array_map([$this, 'normalizePricingRowCommissionRate'], $pricing['rows']));
        }

        if (is_array($pricing) && $this->isListArray($pricing)) {
            return array_values(array_map([$this, 'normalizePricingRowCommissionRate'], $pricing));
        }

        $rows = data_get($payload, 'rows', []);
        return is_array($rows) ? array_values(array_map([$this, 'normalizePricingRowCommissionRate'], $rows)) : [];
    }

    private function normalizePricingRowCommissionRate(mixed $row): mixed
    {
        if (!is_array($row)) {
            return $row;
        }

        if (!array_key_exists('commission_rate', $row)) {
            $row['commission_rate'] = $this->firstPayloadValue([$row], [
                'affiliate_commission_value',
                'affiliateCommissionValue',
                'affiliate_commission.value',
                'affiliateCommission.value',
                'commission_value',
                'commissionValue',
                'commission.value',
                'commissionRate',
            ]);
        }

        if (!array_key_exists('commission_rate_mode', $row)) {
            $commissionMode = $this->firstPayloadValue([$row], [
                'commission_rate_mode',
                'commissionRateMode',
                'affiliate_commission_mode',
                'affiliateCommissionMode',
                'affiliate_commission_type',
                'affiliateCommissionType',
                'commission_type',
                'commissionType',
                'affiliate_commission.mode',
                'affiliate_commission.type',
                'affiliateCommission.mode',
                'affiliateCommission.type',
                'commission.mode',
                'commission.type',
            ]);

            if ($commissionMode !== null) {
                $row['commission_rate_mode'] = $this->normalizeCommissionMode($commissionMode);
            } elseif (($this->normalizeCommissionValue($row['commission_rate'] ?? null) ?? 0) > 100) {
                $row['commission_rate_mode'] = 'fixed';
            }
        }

        if (!array_key_exists('ticket_reward_quantity', $row)) {
            $row['ticket_reward_quantity'] = $this->firstPayloadValue([$row], [
                'ticketRewardQuantity',
                'ticket_quantity',
                'ticketQuantity',
                'ticket_reward.ticket_reward_quantity',
                'ticket_reward.ticketRewardQuantity',
                'ticket_reward.ticket_count',
                'ticket_reward.ticketCount',
                'ticket_reward.quantity',
                'ticketReward.ticket_reward_quantity',
                'ticketReward.ticketRewardQuantity',
                'ticketReward.ticket_count',
                'ticketReward.ticketCount',
                'ticketReward.quantity',
            ]);
        }

        if (!array_key_exists('ticket_type_code', $row)) {
            $row['ticket_type_code'] = $this->firstPayloadValue([$row], [
                'ticketTypeCode',
                'ticket_reward.ticket_type_code',
                'ticket_reward.ticketTypeCode',
                'ticketReward.ticket_type_code',
                'ticketReward.ticketTypeCode',
            ]);
        }

        return $row;
    }

    private function normalizePricingBulk(array $payload): array
    {
        $bulk = data_get($payload, 'pricing.bulk');
        if (!is_array($bulk)) {
            $bulk = [];
        }

        return [
            'price' => $bulk['price'] ?? null,
            'sale_price' => $bulk['sale_price'] ?? ($bulk['salePrice'] ?? null),
            'stock' => $bulk['stock'] ?? null,
            'sku' => $bulk['sku'] ?? null,
            'commission_rate' => $this->firstPayloadValue([$bulk], [
                'affiliate_commission_value',
                'affiliateCommissionValue',
                'affiliate_commission.value',
                'affiliateCommission.value',
                'commission_value',
                'commissionValue',
                'commission.value',
                'commission_rate',
                'commissionRate',
            ]),
            'commission_rate_mode' => $this->firstPayloadValue([$bulk], [
                'commission_rate_mode',
                'commissionRateMode',
                'affiliate_commission_mode',
                'affiliateCommissionMode',
                'affiliate_commission_type',
                'affiliateCommissionType',
                'commission_type',
                'commissionType',
                'affiliate_commission.mode',
                'affiliate_commission.type',
                'affiliateCommission.mode',
                'affiliateCommission.type',
                'commission.mode',
                'commission.type',
            ]),
            'ticket_reward_quantity' => $this->firstPayloadValue([$bulk], [
                'ticket_reward_quantity',
                'ticketRewardQuantity',
                'ticketQuantity',
                'ticket_reward.ticket_reward_quantity',
                'ticket_reward.ticketRewardQuantity',
                'ticket_reward.ticket_count',
                'ticket_reward.ticketCount',
                'ticket_reward.quantity',
                'ticketReward.ticket_reward_quantity',
                'ticketReward.ticketRewardQuantity',
                'ticketReward.ticket_count',
                'ticketReward.ticketCount',
                'ticketReward.quantity',
            ]),
            'ticket_type_code' => $this->firstPayloadValue([$bulk], [
                'ticket_type_code',
                'ticketTypeCode',
                'ticket_reward.ticket_type_code',
                'ticket_reward.ticketTypeCode',
                'ticketReward.ticket_type_code',
                'ticketReward.ticketTypeCode',
            ]),
        ];
    }

    private function validateNormalizedPayload(Store $store, array &$basicInfo, array $variantGroups, array $pricingRows): array
    {
        $errors = [];

        $name = trim((string) ($basicInfo['name'] ?? ''));
        if ($name === '') {
            $errors[] = [
                'path' => 'basic_info.name',
                'message' => 'Vui long nhap ten san pham.',
            ];
        }

        $this->validateCategory($store, $basicInfo, $errors);
        $this->validateTicketRewardQuantity($basicInfo, $errors);
        $this->validateTicketType($basicInfo, $errors);
        $this->validateMedia($store, $basicInfo, $errors);
        $this->validateVariantGroups($variantGroups, $errors);
        $this->validateRowConsistency($variantGroups, $pricingRows, $errors);
        $this->validateEnabledPricingRows($pricingRows, $errors);
        $this->validateStockConstraints($basicInfo, $pricingRows, $errors);

        return $errors;
    }

    private function validateStockConstraints(array &$basicInfo, array &$pricingRows, array &$errors): void
    {
        if (empty($pricingRows)) {
            if (!array_key_exists('stock', $basicInfo) || $basicInfo['stock'] === null || $basicInfo['stock'] === '') {
                $basicInfo['stock'] = 0;
                return;
            }

            if (!$this->isNonNegativeInteger($basicInfo['stock'])) {
                $errors[] = [
                    'path' => 'basic_info.stock',
                    'message' => 'So luong ton kho phai la so nguyen >= 0.',
                ];
                return;
            }

            $basicInfo['stock'] = max(0, (int) $basicInfo['stock']);
            return;
        }

        foreach ($pricingRows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rawStock = $row['stock'] ?? null;
            if ($rawStock === null || $rawStock === '') {
                $pricingRows[$index]['stock'] = 0;
                continue;
            }

            if (!$this->isNonNegativeInteger($rawStock)) {
                $errors[] = [
                    'path' => 'pricing.rows.' . $index . '.stock',
                    'message' => 'So luong ton kho phai la so nguyen >= 0.',
                ];
                continue;
            }

            $pricingRows[$index]['stock'] = max(0, (int) $rawStock);
        }
    }

    private function validateEnabledPricingRows(array $pricingRows, array &$errors): void
    {
        if (empty($pricingRows)) {
            return;
        }

        foreach ($pricingRows as $row) {
            if (is_array($row) && $this->isPricingRowEnabled($row)) {
                return;
            }
        }

        $errors[] = [
            'path' => 'pricing.rows',
            'message' => 'Can giu lai it nhat 1 bien the dang bat.',
        ];
    }

    private function isPricingRowEnabled(array $row): bool
    {
        if (array_key_exists('disabled', $row)) {
            $disabled = $this->normalizeBooleanFlag($row['disabled']);
            if ($disabled !== null) {
                return !$disabled;
            }
        }

        foreach (['enabled', 'is_enabled', 'isEnabled', 'active', 'is_active', 'isActive'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $enabled = $this->normalizeBooleanFlag($row[$key]);
            if ($enabled !== null) {
                return $enabled;
            }
        }

        return true;
    }

    private function normalizeBooleanFlag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }

        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled', 'active'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled', 'inactive'], true)) {
                return false;
            }
        }

        return null;
    }

    private function isNonNegativeInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed !== '' && preg_match('/^\d+$/', $trimmed) === 1;
        }

        return false;
    }

    private function validateTicketRewardQuantity(array &$basicInfo, array &$errors): void
    {
        if (!array_key_exists('ticket_reward_quantity', $basicInfo)) {
            $basicInfo['ticket_reward_quantity'] = 0;
            return;
        }

        $normalized = $this->normalizeTicketRewardQuantity($basicInfo['ticket_reward_quantity']);
        if ($normalized < 0 || $normalized > 1000) {
            $errors[] = [
                'path' => 'basic_info.ticket_reward_quantity',
                'message' => 'So luong ve tang phai nam trong khoang 0-1000.',
            ];
            return;
        }

        $basicInfo['ticket_reward_quantity'] = $normalized;
    }

    private function normalizeTicketRewardQuantity(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return max(0, (int) $value);
    }

    private function validateTicketType(array &$basicInfo, array &$errors): void
    {
        if (!array_key_exists('ticket_type_code', $basicInfo)) {
            return;
        }

        $normalizedCode = $this->normalizeTicketTypeCode($basicInfo['ticket_type_code']);
        if ($normalizedCode === null) {
            $basicInfo['ticket_type_code'] = null;
            return;
        }

        $resolvedType = app(TicketPricingService::class)->resolveTicketType($normalizedCode);
        if (!$resolvedType || strtoupper((string) ($resolvedType['code'] ?? '')) !== $normalizedCode) {
            $errors[] = [
                'path' => 'basic_info.ticket_type_code',
                'message' => 'Loai ve khong hop le hoac khong ton tai trong cau hinh he thong.',
            ];
            return;
        }

        $basicInfo['ticket_type_code'] = $normalizedCode;
    }

    private function normalizeTicketTypeCode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, 50);
    }

    private function validateCategory(Store $store, array &$basicInfo, array &$errors): void
    {
        if (!Schema::hasTable('product_categories')) {
            return;
        }

        $categoryId = $this->normalizeNullableInteger($basicInfo['category_id'] ?? null);

        if ($categoryId === null) {
            $categoryName = $this->normalizeCategoryNameInput(
                $basicInfo['category_name'] ?? $basicInfo['category'] ?? null
            );

            if ($categoryName !== null) {
                $resolvedCategoryId = $this->resolveOrCreateCategoryIdByName($store, $categoryName);
                if ($resolvedCategoryId !== null) {
                    $categoryId = $resolvedCategoryId;
                    $basicInfo['category_id'] = $resolvedCategoryId;
                }
            }
        }

        if ($categoryId === null || $categoryId === '') {
            $errors[] = [
                'path' => 'basic_info.category_id',
                'message' => 'Vui long chon danh muc.',
            ];

            return;
        }

        $categoryScopeQuery = DB::table('product_categories')->where('id', $categoryId);
        if (Schema::hasColumn('product_categories', 'store_id')) {
            $categoryScopeQuery->where(function ($scopeQuery) use ($store) {
                $scopeQuery->whereNull('store_id')->orWhere('store_id', (string) $store->id);
            });
        }

        $exists = $categoryScopeQuery->exists();
        if (!$exists) {
            $errors[] = [
                'path' => 'basic_info.category_id',
                'message' => 'Danh muc khong hop le hoac khong ton tai.',
            ];

            return;
        }

    }

    private function normalizeCategoryNameInput(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/', ' ', $value));
        return $normalized === '' ? null : $normalized;
    }

    private function resolveOrCreateCategoryIdByName(Store $store, string $categoryName): ?int
    {
        if (!Schema::hasTable('product_categories')) {
            return null;
        }

        $supportsParent = Schema::hasColumn('product_categories', 'parent_id');
        $supportsStoreScope = Schema::hasColumn('product_categories', 'store_id');

        $segments = preg_split('/\s*>\s*/u', $categoryName) ?: [];
        $segments = array_values(array_filter(array_map(function ($part) {
            return $this->normalizeCategoryNameInput((string) $part);
        }, $segments), fn ($part) => $part !== null));

        if (empty($segments)) {
            return null;
        }

        if ($supportsParent && count($segments) > 1) {
            $parentId = null;
            foreach ($segments as $segment) {
                $node = $this->resolveOrCreateCategoryNode($store, $parentId, (string) $segment, $supportsStoreScope);
                if (!$node || !isset($node->id)) {
                    return null;
                }

                $parentId = (int) $node->id;
            }

            return $parentId;
        }

        $leafName = (string) end($segments);
        $candidate = $supportsParent
            ? $this->resolveOrCreateCategoryNode($store, null, $leafName, $supportsStoreScope)
            : $this->resolveOrCreateCategoryNode($store, null, $leafName, $supportsStoreScope, false);

        return $candidate && isset($candidate->id) ? (int) $candidate->id : null;
    }

    private function resolveOrCreateCategoryNode(
        Store $store,
        ?int $parentId,
        string $name,
        bool $supportsStoreScope,
        bool $enforceParent = true
    ): ?object {
        $safeName = $this->normalizeCategoryNameInput($name);
        if ($safeName === null) {
            return null;
        }

        $query = DB::table('product_categories')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($safeName)]);

        if ($enforceParent && Schema::hasColumn('product_categories', 'parent_id')) {
            if ($parentId === null) {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $parentId);
            }
        }

        if ($supportsStoreScope) {
            $query->where(function ($scopeQuery) use ($store) {
                $scopeQuery->whereNull('store_id')->orWhere('store_id', (string) $store->id);
            });
            $query->orderByRaw('CASE WHEN store_id IS NULL THEN 1 ELSE 0 END');
        }

        if ($enforceParent && Schema::hasColumn('product_categories', 'parent_id')) {
            $query->orderByRaw('CASE WHEN parent_id IS NULL THEN 1 ELSE 0 END');
        }

        $existing = $query->first();
        if ($existing) {
            return $existing;
        }

        $insert = [
            'name' => $safeName,
        ];

        if (Schema::hasColumn('product_categories', 'slug')) {
            $insert['slug'] = $this->generateUniqueCategorySlug($safeName);
        }

        if (Schema::hasColumn('product_categories', 'parent_id')) {
            $insert['parent_id'] = $enforceParent ? $parentId : null;
        }

        if ($supportsStoreScope) {
            $insert['store_id'] = (string) $store->id;
        }

        if (Schema::hasColumn('product_categories', 'sort_order')) {
            $sortQuery = DB::table('product_categories');
            if (Schema::hasColumn('product_categories', 'parent_id')) {
                if (($insert['parent_id'] ?? null) === null) {
                    $sortQuery->whereNull('parent_id');
                } else {
                    $sortQuery->where('parent_id', $insert['parent_id']);
                }
            }
            $insert['sort_order'] = ((int) ($sortQuery->max('sort_order') ?? 0)) + 1;
        }

        if (Schema::hasColumn('product_categories', 'created_at')) {
            $insert['created_at'] = now();
        }
        if (Schema::hasColumn('product_categories', 'updated_at')) {
            $insert['updated_at'] = now();
        }

        $newId = DB::table('product_categories')->insertGetId($insert);
        return DB::table('product_categories')->where('id', $newId)->first();
    }

    private function generateUniqueCategorySlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'category';
        }

        $slug = $base;
        $suffix = 1;

        while (DB::table('product_categories')->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }

    private function validateMedia(Store $store, array $basicInfo, array &$errors): void
    {
        $allowedPrefixes = $this->allowedProductImagePrefixes($store);

        $thumbnail = $basicInfo['thumbnail'] ?? null;
        if (is_string($thumbnail) && $thumbnail !== '') {
            $this->validateImageUrl($thumbnail, 'basic_info.thumbnail', $allowedPrefixes, $errors);
        }

        $images = $basicInfo['images'] ?? [];
        if (is_array($images)) {
            foreach ($images as $index => $url) {
                if (is_array($url)) {
                    $url = $url['url'] ?? $url['public_url'] ?? $url['path'] ?? null;
                }

                if (!is_string($url) || trim($url) === '') {
                    $errors[] = [
                        'path' => 'basic_info.images.' . $index,
                        'message' => 'URL anh khong hop le.',
                    ];

                    continue;
                }

                $this->validateImageUrl($url, 'basic_info.images.' . $index, $allowedPrefixes, $errors);
            }
        }
    }

    private function validateImageUrl(string $url, string $path, array $allowedPrefixes, array &$errors): void
    {
        $pathPart = null;

        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $parsedPath = parse_url($url, PHP_URL_PATH);
            $pathPart = is_string($parsedPath) ? $parsedPath : null;
        } else {
            $trimmed = trim($url);
            if (str_starts_with($trimmed, '/storage/')) {
                $pathPart = $trimmed;
            }
        }

        if (!is_string($pathPart) || trim($pathPart) === '') {
            $errors[] = [
                'path' => $path,
                'message' => 'URL anh khong hop le.',
            ];

            return;
        }

        $isAllowed = is_string($pathPart) && collect($allowedPrefixes)
            ->contains(static fn (string $prefix): bool => str_starts_with($pathPart, $prefix));

        if (!$isAllowed) {
            $errors[] = [
                'path' => $path,
                'message' => 'URL anh khong thuoc he thong upload hop le cua cua hang hien tai.',
            ];
        }
    }

    private function allowedProductImagePrefixes(Store $store): array
    {
        $basePrefix = '/storage/uploads/stores/' . $store->id;

        return [
            $basePrefix . '/products/',
            $basePrefix . '/licenses/product_cover/',
            $basePrefix . '/licenses/product_gallery/',
            $basePrefix . '/licenses/variant_option/',
        ];
    }

    private function validateVariantGroups(array $variantGroups, array &$errors): void
    {
        foreach ($variantGroups as $index => $group) {
            $path = 'variants.group' . ($index + 1);
            $name = trim((string) ($group['name'] ?? ''));
            $options = $group['options'] ?? [];

            if ($name === '') {
                $errors[] = [
                    'path' => $path . '.name',
                    'message' => 'Ten thuoc tinh khong duoc de trong.',
                ];
            }

            if (!is_array($options) || count($options) === 0) {
                $errors[] = [
                    'path' => $path . '.options',
                    'message' => 'Thuoc tinh phai co it nhat 1 gia tri.',
                ];
            }
        }
    }

    private function validateRowConsistency(array $variantGroups, array $pricingRows, array &$errors): void
    {
        if (empty($variantGroups)) {
            return;
        }

        $expectedRows = 1;
        foreach ($variantGroups as $group) {
            $expectedRows *= max(1, count($group['options'] ?? []));
        }

        $actualRows = count($pricingRows);
        if ($actualRows !== $expectedRows) {
            $errors[] = [
                'path' => 'pricing.rows',
                'message' => 'So luong pricing rows khong khop voi to hop variants (expected: ' . $expectedRows . ', actual: ' . $actualRows . ').',
            ];
        }
    }

    private function buildCombinations(array $variantGroups): array
    {
        if (empty($variantGroups)) {
            return [];
        }

        $combinations = [[]];
        foreach ($variantGroups as $groupIndex => $group) {
            $nextCombinations = [];
            $options = $group['options'] ?? [];
            foreach ($combinations as $combination) {
                foreach ($options as $option) {
                    $combination[$groupIndex] = $option;
                    $nextCombinations[] = $combination;
                }
            }
            $combinations = $nextCombinations;
        }

        return $combinations;
    }

    private function resolveVariantAttributeValueIds(array $row, array $attributes, array $attributeValueCache, array $combinations, int $index): array
    {
        $selectedValues = $this->resolveSelectedAttributeValues($row, $attributes, $combinations, $index);
        $attributeValueIds = [];

        foreach ($selectedValues as $groupIndex => $selectedValue) {
            if (!isset($attributes[$groupIndex]['record'])) {
                continue;
            }

            $attribute = $attributes[$groupIndex]['record'];
            $normalizedValue = mb_strtolower((string) $selectedValue);

            if (isset($attributeValueCache[$attribute->id][$normalizedValue])) {
                $attributeValueIds[] = $attributeValueCache[$attribute->id][$normalizedValue]->id;
            }
        }

        return $attributeValueIds;
    }

    private function resolveSelectedAttributeValues(array $row, array $attributes, array $combinations, int $index): array
    {
        $candidates = [
            $row['attribute_values'] ?? null,
            $row['attributes'] ?? null,
            $row['variant_attribute_values'] ?? null,
            $row,
        ];

        foreach ($candidates as $candidate) {
            $resolved = $this->normalizeSelectedValues($candidate, $attributes);
            if (!empty($resolved)) {
                return $resolved;
            }
        }

        $resolvedFromLegacyKeys = $this->resolveSelectedValuesFromLegacyRow($row, $attributes);
        if (!empty($resolvedFromLegacyKeys)) {
            return $resolvedFromLegacyKeys;
        }

        $rowKey = trim((string) ($row['key'] ?? ''));
        if ($rowKey !== '') {
            $resolvedByKey = $this->resolveSelectedValuesByVariantKey($rowKey, $attributes);
            if (!empty($resolvedByKey)) {
                return $resolvedByKey;
            }
        }

        if (isset($combinations[$index])) {
            return $combinations[$index];
        }

        return [];
    }

    private function resolveSelectedValuesFromLegacyRow(array $row, array $attributes): array
    {
        $resolved = [];

        foreach ($attributes as $groupIndex => $group) {
            $keyCandidates = [
                'group_' . ($groupIndex + 1) . '_option',
                'group' . ($groupIndex + 1) . '_option',
                'option_' . ($groupIndex + 1),
            ];

            $groupName = trim((string) ($group['name'] ?? ''));
            if ($groupName !== '') {
                $keyCandidates[] = Str::snake($groupName) . '_option';
                $keyCandidates[] = Str::snake($groupName);
            }

            foreach ($keyCandidates as $candidateKey) {
                if (!array_key_exists($candidateKey, $row)) {
                    continue;
                }

                $value = $row[$candidateKey];
                if (is_array($value)) {
                    $value = $value['value'] ?? $value['label'] ?? $value['name'] ?? null;
                }

                $value = trim((string) $value);
                if ($value !== '') {
                    $resolved[$groupIndex] = $value;
                    break;
                }
            }
        }

        if (!empty($resolved)) {
            return $resolved;
        }

        $variantLabel = trim((string) ($row['variant_label'] ?? ''));
        if ($variantLabel === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:\/|\|)\s*/', $variantLabel) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn ($part): bool => $part !== ''));

        if (empty($parts)) {
            return [];
        }

        $fromLabel = [];
        foreach ($attributes as $groupIndex => $_group) {
            if (!array_key_exists($groupIndex, $parts)) {
                continue;
            }

            $fromLabel[$groupIndex] = $parts[$groupIndex];
        }

        return $fromLabel;
    }

    private function normalizeSelectedValues(mixed $candidate, array $attributes): array
    {
        if (!is_array($candidate) || empty($candidate)) {
            return [];
        }

        if ($this->isAssocArray($candidate)) {
            $resolved = [];
            foreach ($attributes as $groupIndex => $group) {
                $groupName = (string) ($group['name'] ?? '');
                $groupId = isset($group['record']) ? (string) $group['record']->id : '';
                $match = null;

                foreach ($candidate as $key => $value) {
                    if (
                        ($groupName !== '' && strcasecmp((string) $key, $groupName) === 0)
                        || ($groupId !== '' && (string) $key === $groupId)
                    ) {
                        $match = $value;
                        break;
                    }
                }

                if ($match === null) {
                    continue;
                }

                if (is_array($match)) {
                    $match = $match['value'] ?? $match['label'] ?? $match['name'] ?? null;
                }

                $match = trim((string) $match);
                if ($match !== '') {
                    $resolved[$groupIndex] = $match;
                }
            }

            return $resolved;
        }

        $resolved = [];
        $orderedValues = array_values($candidate);
        foreach ($attributes as $groupIndex => $_group) {
            if (!array_key_exists($groupIndex, $orderedValues)) {
                continue;
            }

            $value = $orderedValues[$groupIndex];
            if (is_array($value)) {
                $value = $value['value'] ?? $value['label'] ?? $value['name'] ?? null;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $resolved[$groupIndex] = $value;
            }
        }

        return $resolved;
    }

    private function normalizeStoragePath(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['url'] ?? $value['public_url'] ?? $value['path'] ?? null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $path = $value;
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $parsedPath = parse_url($value, PHP_URL_PATH);
            if (is_string($parsedPath) && $parsedPath !== '') {
                $path = $parsedPath;
            }
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, 8);
        }

        return $path !== '' ? $path : null;
    }

    private function normalizeImageList(mixed $images): array
    {
        if (!is_array($images)) {
            return [];
        }

        $normalized = [];
        foreach ($images as $image) {
            $path = $this->normalizeStoragePath($image);
            if ($path !== null) {
                $normalized[] = $path;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function validateSkuForUpdate(Store $store, Product $product, array $pricingRows, array $basicInfo, array &$errors): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'sku')) {
            $incomingProductSku = $this->normalizeNullableString($basicInfo['sku'] ?? null);
            if ($incomingProductSku !== null) {
                $normalized = mb_strtolower($incomingProductSku);
                $query = DB::table('products')
                    ->whereRaw('LOWER(sku) = ?', [$normalized])
                    ->where('id', '!=', $product->id);

                if (Schema::hasColumn('products', 'store_id')) {
                    $query->where('store_id', $store->id);
                }

                if ($query->exists()) {
                    $errors[] = [
                        'path' => 'basic_info.sku',
                        'message' => 'SKU san pham khong duoc trung.',
                    ];
                }
            }
        }

        if (!(Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'sku'))) {
            return;
        }

        $seen = [];
        foreach ($pricingRows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!$this->isPricingRowEnabled($row)) {
                continue;
            }

            $sku = $this->normalizeNullableString($row['sku'] ?? null);
            if ($sku === null) {
                continue;
            }

            $normalized = mb_strtolower($sku);
            if (isset($seen[$normalized])) {
                $errors[] = [
                    'path' => 'pricing.rows.' . $index . '.sku',
                    'message' => 'SKU khong duoc trung trong payload.',
                ];
                continue;
            }
            $seen[$normalized] = true;

            $query = DB::table('product_variants as pv')
                ->join('products as p', 'p.id', '=', 'pv.product_id')
                ->whereRaw('LOWER(pv.sku) = ?', [$normalized])
                ->where('p.id', '!=', $product->id);

            if (Schema::hasColumn('products', 'store_id')) {
                $query->where('p.store_id', $store->id);
            }

            if ($query->exists()) {
                $errors[] = [
                    'path' => 'pricing.rows.' . $index . '.sku',
                    'message' => 'SKU khong duoc trung voi bien the khac trong cua hang.',
                ];
            }
        }
    }

    private function resolveExistingVariantKey(ProductVariant $variant): ?string
    {
        if (Schema::hasColumn('product_variants', 'variant_key')) {
            $raw = $variant->getAttribute('variant_key');
            if (is_string($raw) && trim($raw) !== '') {
                return trim($raw);
            }
        }

        $parts = [];
        foreach ($variant->attributeValues as $attributeValue) {
            $parts[] = Str::slug((string) $attributeValue->value, '_');
        }

        $parts = array_values(array_filter($parts, static fn ($v): bool => $v !== ''));
        return !empty($parts) ? implode('__', $parts) : ('variant_' . $variant->id);
    }

    private function resolveIncomingVariantKey(array $row, array $attributes, int $index): string
    {
        $raw = trim((string) ($row['key'] ?? ''));
        if ($raw !== '') {
            return $raw;
        }

        $selected = $this->normalizeSelectedValues($row['attribute_values'] ?? ($row['attributes'] ?? null), $attributes);
        $parts = [];
        foreach ($selected as $value) {
            $parts[] = Str::slug((string) $value, '_');
        }

        $parts = array_values(array_filter($parts, static fn ($v): bool => $v !== ''));
        if (!empty($parts)) {
            return implode('__', $parts);
        }

        return 'row_' . $index;
    }

    private function resolveSelectedValuesByVariantKey(string $key, array $attributes): array
    {
        $parts = array_values(array_filter(explode('__', $key), static fn ($v): bool => trim($v) !== ''));
        if (empty($parts)) {
            return [];
        }

        $selected = [];
        foreach ($attributes as $groupIndex => $group) {
            if (!array_key_exists($groupIndex, $parts)) {
                continue;
            }

            $needle = Str::slug((string) $parts[$groupIndex], '_');
            foreach (($group['options'] ?? []) as $option) {
                if (Str::slug((string) $option, '_') === $needle) {
                    $selected[$groupIndex] = (string) $option;
                    break;
                }
            }
        }

        return $selected;
    }

    private function resolveUniqueSkuForUpdate(string $sku, string $name, int $ignoreProductId): string
    {
        $baseSku = trim($sku) !== '' ? Str::slug($sku) : Str::slug($name);
        if ($baseSku === '') {
            $baseSku = 'sku';
        }

        $candidate = $baseSku;
        $counter = 2;

        while (Product::query()->where('sku', $candidate)->where('id', '!=', $ignoreProductId)->exists()) {
            $candidate = $baseSku . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function toPublicUrl(string $path): string
    {
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) {
            return url('/' . $path);
        }

        return url('/storage/' . $path);
    }

    private function extractProductAttributes($variants): array
    {
        $byId = [];
        $order = [];
        $hasAttributeImage = Schema::hasColumn('attributes', 'image');
        $hasAttributeUseImage = Schema::hasColumn('attributes', 'use_image');
        $hasAttributeValueImage = Schema::hasColumn('attribute_values', 'image');

        foreach ($variants as $variant) {
            foreach ($variant->attributeValues as $attributeValue) {
                $attribute = $attributeValue->attribute;
                if (!$attribute) {
                    continue;
                }

                $attributeId = (int) $attribute->id;
                if (!isset($byId[$attributeId])) {
                    $useImage = false;
                    if ($hasAttributeUseImage) {
                        $useImage = (bool) $attribute->getAttribute('use_image');
                    } elseif ($hasAttributeImage) {
                        $useImage = (bool) $attribute->getAttribute('image');
                    }

                    $byId[$attributeId] = [
                        'id' => $attributeId,
                        'name' => $attribute->name,
                        'use_image' => $useImage,
                        'options' => [],
                    ];
                    $order[] = $attributeId;
                }

                $optionId = (int) $attributeValue->id;
                if (!isset($byId[$attributeId]['options'][$optionId])) {
                    $option = [
                        'id' => $optionId,
                        'value' => $attributeValue->value,
                        'label' => $attributeValue->value,
                    ];

                    if ($hasAttributeValueImage) {
                        $optionImage = $attributeValue->getAttribute('image');
                        $option['image'] = is_string($optionImage) && trim($optionImage) !== ''
                            ? $this->toPublicUrl(trim($optionImage))
                            : null;
                    }

                    $byId[$attributeId]['options'][$optionId] = $option;
                }
            }
        }

        $groups = [];
        foreach ($order as $attributeId) {
            $group = $byId[$attributeId];
            $group['options'] = array_values($group['options']);
            $groups[] = $group;
        }

        return array_slice($groups, 0, 2);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function normalizeNullableNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function normalizeInteger(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

    private function resolveUniqueSlug(string $slug, string $name, ?int $ignoreProductId): string
    {
        $baseSlug = trim($slug) !== '' ? Str::slug($slug) : Str::slug($name);
        if ($baseSlug === '') {
            $baseSlug = 'product';
        }

        $candidate = $baseSlug;
        $counter = 2;

        while ($this->slugExists($candidate, $ignoreProductId)) {
            $candidate = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function slugExists(string $slug, ?int $ignoreProductId): bool
    {
        $query = Product::query()->where('slug', $slug);
        if ($ignoreProductId !== null) {
            $query->where('id', '!=', $ignoreProductId);
        }

        return $query->exists();
    }

    private function resolveUniqueSku(string $sku, string $name): string
    {
        $baseSku = trim($sku) !== '' ? Str::slug($sku) : Str::slug($name);
        if ($baseSku === '') {
            $baseSku = 'sku';
        }

        $candidate = $baseSku;
        $counter = 2;

        while (Product::query()->where('sku', $candidate)->exists()) {
            $candidate = $baseSku . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function isAssocArray(array $value): bool
    {
        return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
    }

    private function isListArray(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    private function toIsoDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveOwnedStoreContext(Request $request): array
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return [null, null, $context['error']];
        }

        if (!$context['store']) {
            return [
                null,
                $context['user'],
                $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, true, false),
            ];
        }

        return [$context['store'], $context['user'], null];
    }

    private function resolveRequestedStoreId(Request $request): ?string
    {
        $storeContextId = trim((string) $request->attributes->get('store_context_id', ''));
        if ($storeContextId !== '') {
            return $storeContextId;
        }

        $storeId = trim((string) ($request->query('store_id') ?: $request->input('store_id') ?: ''));
        if ($storeId !== '') {
            return $storeId;
        }

        $headerStoreId = trim((string) $request->header('X-Store-Id', ''));
        return $headerStoreId !== '' ? $headerStoreId : null;
    }

    private function resolveContext(Request $request): array
    {
        return $this->resolveStoreAdminAccessContext($request);
    }

    private function errorResponse(string $code, string $message, int $status, bool $authenticated, bool $hasStore, ?array $details = null): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'data' => null,
            'meta' => [
                'authenticated' => $authenticated,
                'has_store' => $hasStore,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [$error],
        ], $status);
    }
}
