<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use App\Events\StoreAdminProductUpdated;

class StoreAdminFlashSaleController extends Controller
{
    private const FLASH_SALE_TIMEZONE = 'Asia/Ho_Chi_Minh';

    public function index(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('flash_sales')) {
            return response()->json([
                'data' => [
                    'items' => [],
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                    'paging' => [
                        'total_items' => 0,
                        'current_page' => 1,
                        'page_size' => 20,
                        'total_pages' => 0,
                        'has_next' => false,
                    ],
                ],
                'errors' => [],
            ]);
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['page_size'] ?? 20);

        $query = FlashSale::query()->where('store_id', $store->id);

        if (!empty($validated['q'])) {
            $keyword = trim((string) $validated['q']);
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        $totalItems = (int) (clone $query)->count();

        $sales = $query
            ->orderByDesc('start_time')
            ->forPage($page, $pageSize)
            ->get();

        $itemsBySale = collect();
        if (Schema::hasTable('flash_sale_items') && $sales->isNotEmpty()) {
            $itemsBySale = FlashSaleItem::query()
                ->whereIn('flash_sale_id', $sales->pluck('id')->all())
                ->get()
                ->groupBy('flash_sale_id');
        }

        $payload = $sales->map(function (FlashSale $sale) use ($itemsBySale) {
            $items = $itemsBySale->get($sale->id, collect());

            return $this->serializeFlashSale($sale, $items->values()->all(), true);
        })->values()->all();

        $totalPages = $pageSize > 0 ? (int) ceil($totalItems / $pageSize) : 0;

        return response()->json([
            'data' => [
                'items' => $payload,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
                'paging' => [
                    'total_items' => $totalItems,
                    'current_page' => $page,
                    'page_size' => $pageSize,
                    'total_pages' => $totalPages,
                    'has_next' => $page < $totalPages,
                ],
            ],
            'errors' => [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('flash_sales') || !Schema::hasTable('flash_sale_items')) {
            return $this->errorResponse('FLASH_SALE_NOT_SUPPORTED', 'Bang flash_sale chua duoc khoi tao.', 422, true, true);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.flash_sale_price' => ['required', 'numeric', 'min:0'],
            'items.*.sale_quantity' => ['required', 'integer', 'min:1'],
            'items.*.per_user_limit' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $startTime = $this->parseFlashSaleDateTime((string) $validated['start_time']);
        $endTime = $this->parseFlashSaleDateTime((string) $validated['end_time']);
        $hasExplicitStoreContext = $this->resolveRequestedStoreId($request) !== null;

        if ($endTime->lessThanOrEqualTo($startTime)) {
            return $this->errorResponse('INVALID_TIME_RANGE', 'Thoi gian ket thuc phai sau thoi gian bat dau.', 422, true, true);
        }

        // Allow starting in the past/present as requested
        // if ($startTime->lessThanOrEqualTo(now(self::FLASH_SALE_TIMEZONE))) {
        //     return $this->errorResponse('INVALID_START_TIME', 'Thoi gian bat dau phai lon hon thoi gian hien tai.', 422, true, true);
        // }

        $overlapProgram = $this->findOverlapProgram($store->id, $startTime, $endTime, null);
        if ($overlapProgram) {
            return $this->errorResponse(
                'FLASH_SALE_TIME_OVERLAP',
                'Khung gio nay da co chuong trinh Flash Sale khac. Vui long chon sua chuong trinh da co neu chua toi gio chay hoac chon khung gio khac.',
                422,
                true,
                true,
                [
                    'flash_sale_id' => [(string) $overlapProgram->id],
                    'flash_sale_name' => [(string) $overlapProgram->name],
                ]
            );
        }

        $itemsValidation = $this->validateFlashSaleItems(
            $store,
            $validated['items'],
            $startTime,
            $endTime,
            null,
            $hasExplicitStoreContext
        );

        if ($itemsValidation['error'] !== null) {
            return $itemsValidation['error'];
        }

        $sale = DB::transaction(function () use ($store, $validated, $startTime, $endTime) {
            $sale = FlashSale::query()->create([
                'store_id' => $store->id,
                'name' => (string) $validated['name'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]);

            foreach ($validated['items'] as $item) {
                FlashSaleItem::query()->create([
                    'flash_sale_id' => $sale->id,
                    'product_id' => (int) $item['product_id'],
                    'variant_id' => isset($item['variant_id']) ? (int) $item['variant_id'] : null,
                    'flash_sale_price' => (float) $item['flash_sale_price'],
                    'sale_quantity' => (int) $item['sale_quantity'],
                    'sold_quantity' => 0,
                    'per_user_limit' => isset($item['per_user_limit']) ? (int) $item['per_user_limit'] : null,
                ]);
            }

            return $sale;
        });

        $this->notifyProductUpdates($sale);

        return response()->json([
            'data' => $this->serializeFlashSale($sale, $sale->items->values()->all(), true),
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('flash_sales')) {
            return $this->errorResponse('FLASH_SALE_NOT_SUPPORTED', 'Bang flash_sale chua duoc khoi tao.', 422, true, true);
        }

        $sale = FlashSale::query()
            ->where('store_id', $store->id)
            ->where('id', $id)
            ->first();

        if (!$sale) {
            return $this->errorResponse('FLASH_SALE_NOT_FOUND', 'Khong tim thay chuong trinh Flash Sale.', 404, true, true);
        }

        $items = Schema::hasTable('flash_sale_items')
            ? FlashSaleItem::query()->where('flash_sale_id', $sale->id)->get()->values()->all()
            : [];

        return response()->json([
            'data' => $this->serializeFlashSale($sale, $items, true),
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('flash_sales')) {
            return $this->errorResponse('FLASH_SALE_NOT_SUPPORTED', 'Bang flash_sale chua duoc khoi tao.', 422, true, true);
        }

        $sale = FlashSale::query()
            ->where('store_id', $store->id)
            ->where('id', $id)
            ->first();

        if (!$sale) {
            return $this->errorResponse('FLASH_SALE_NOT_FOUND', 'Khong tim thay chuong trinh Flash Sale.', 404, true, true);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:255'],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.product_id' => ['required_with:items', 'integer', 'min:1'],
            'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.flash_sale_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.sale_quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.per_user_limit' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $startTime = isset($validated['start_time'])
            ? $this->parseFlashSaleDateTime((string) $validated['start_time'])
            : $sale->start_time;
        $endTime = isset($validated['end_time'])
            ? $this->parseFlashSaleDateTime((string) $validated['end_time'])
            : $sale->end_time;
        $hasExplicitStoreContext = $this->resolveRequestedStoreId($request) !== null;

        if ($endTime->lessThanOrEqualTo($startTime)) {
            return $this->errorResponse('INVALID_TIME_RANGE', 'Thoi gian ket thuc phai sau thoi gian bat dau.', 422, true, true);
        }

        // Allow starting in the past/present as requested
        // $startTimeChanged = array_key_exists('start_time', $validated) && !$startTime->equalTo($sale->start_time);
        // if ($startTimeChanged && $startTime->lessThanOrEqualTo(now(self::FLASH_SALE_TIMEZONE))) {
        //     return $this->errorResponse('INVALID_START_TIME', 'Thoi gian bat dau phai lon hon thoi gian hien tai.', 422, true, true);
        // }

        $overlapProgram = $this->findOverlapProgram($store->id, $startTime, $endTime, $sale->id);
        if ($overlapProgram) {
            return $this->errorResponse(
                'FLASH_SALE_TIME_OVERLAP',
                'Khung gio nay da co chuong trinh Flash Sale khac. Vui long chon sua chuong trinh da co neu chua toi gio chay hoac chon khung gio khac.',
                422,
                true,
                true,
                [
                    'flash_sale_id' => [(string) $overlapProgram->id],
                    'flash_sale_name' => [(string) $overlapProgram->name],
                ]
            );
        }

        if (isset($validated['items'])) {
            $itemsValidation = $this->validateFlashSaleItems(
                $store,
                $validated['items'],
                $startTime,
                $endTime,
                $sale->id,
                $hasExplicitStoreContext
            );

            if ($itemsValidation['error'] !== null) {
                return $itemsValidation['error'];
            }
        }

        $existingProductIds = [];
        if (Schema::hasTable('flash_sale_items')) {
            $existingProductIds = DB::table('flash_sale_items')
                ->where('flash_sale_id', $sale->id)
                ->pluck('product_id')
                ->all();
        }

        $sale = DB::transaction(function () use ($sale, $validated, $startTime, $endTime) {
            $dirty = false;

            if (array_key_exists('name', $validated)) {
                $sale->name = (string) $validated['name'];
                $dirty = true;
            }

            if (array_key_exists('start_time', $validated)) {
                $sale->start_time = $startTime;
                $dirty = true;
            }

            if (array_key_exists('end_time', $validated)) {
                $sale->end_time = $endTime;
                $dirty = true;
            }

            if (array_key_exists('is_active', $validated)) {
                $sale->is_active = (bool) $validated['is_active'];
                $dirty = true;
            }

            if ($dirty) {
                $sale->save();
            }

            if (array_key_exists('items', $validated) && Schema::hasTable('flash_sale_items')) {
                FlashSaleItem::query()->where('flash_sale_id', $sale->id)->delete();

                foreach ($validated['items'] as $item) {
                    FlashSaleItem::query()->create([
                        'flash_sale_id' => $sale->id,
                        'product_id' => (int) $item['product_id'],
                        'variant_id' => isset($item['variant_id']) ? (int) $item['variant_id'] : null,
                        'flash_sale_price' => (float) $item['flash_sale_price'],
                        'sale_quantity' => (int) $item['sale_quantity'],
                        'sold_quantity' => 0,
                        'per_user_limit' => isset($item['per_user_limit']) ? (int) $item['per_user_limit'] : null,
                    ]);
                }
            }

            return $sale;
        });

        $this->notifyProductUpdates($sale, $existingProductIds);

        return response()->json([
            'data' => $this->serializeFlashSale($sale, $sale->items->values()->all(), true),
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('flash_sales')) {
            return $this->errorResponse('FLASH_SALE_NOT_SUPPORTED', 'Bang flash_sale chua duoc khoi tao.', 422, true, true);
        }

        $sale = FlashSale::query()
            ->where('store_id', $store->id)
            ->where('id', $id)
            ->first();

        if (!$sale) {
            return $this->errorResponse('FLASH_SALE_NOT_FOUND', 'Khong tim thay chuong trinh Flash Sale.', 404, true, true);
        }

        $flashSaleItemIds = [];
        $soldQuantity = 0;

        if (Schema::hasTable('flash_sale_items')) {
            $flashSaleItems = FlashSaleItem::query()
                ->where('flash_sale_id', $sale->id)
                ->get(['id', 'sold_quantity']);

            $flashSaleItemIds = $flashSaleItems
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();

            $soldQuantity = (int) $flashSaleItems->sum(static fn (FlashSaleItem $item): int => (int) ($item->sold_quantity ?? 0));
        }

        $hasOrderReferences = false;
        if (
            !empty($flashSaleItemIds)
            && Schema::hasTable('order_items')
            && Schema::hasColumn('order_items', 'flash_sale_item_id')
        ) {
            $hasOrderReferences = DB::table('order_items')
                ->whereIn('flash_sale_item_id', $flashSaleItemIds)
                ->exists();
        }

        if ($soldQuantity > 0 || $hasOrderReferences) {
            return $this->errorResponse(
                'FLASH_SALE_HAS_SALES',
                'Khong the xoa Flash Sale da phat sinh don hang. Vui long tat chuong trinh de giu lich su ban hang.',
                422,
                true,
                true
            );
        }

        $existingProductIds = [];
        if (Schema::hasTable('flash_sale_items')) {
            $existingProductIds = DB::table('flash_sale_items')
                ->where('flash_sale_id', $sale->id)
                ->pluck('product_id')
                ->all();
        }

        DB::transaction(function () use ($sale) {
            if (Schema::hasTable('flash_sale_items')) {
                FlashSaleItem::query()->where('flash_sale_id', $sale->id)->delete();
            }

            $sale->delete();
        });

        $this->notifyProductUpdates($sale, $existingProductIds);

        return response()->json([
            'data' => [
                'id' => (string) $sale->id,
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

    private function validateFlashSaleItems(
        Store $store,
        array $items,
        Carbon $startTime,
        Carbon $endTime,
        ?string $ignoreSaleId,
        bool $hasExplicitStoreContext
    ): array
    {
        $errors = [];
        $missingStoreContextHint = false;
        $requestedQuantities = [];
        $hasProductStoreId = Schema::hasColumn('products', 'store_id');
        $productIds = array_values(array_unique(array_filter(array_map(
            static fn ($item): int => (int) ($item['product_id'] ?? 0),
            $items
        ), static fn (int $productId): bool => $productId > 0)));
        $variantIds = array_values(array_unique(array_filter(array_map(
            static fn ($item): int => isset($item['variant_id']) ? (int) $item['variant_id'] : 0,
            $items
        ), static fn (int $variantId): bool => $variantId > 0)));

        $productsById = empty($productIds)
            ? collect()
            : Product::query()
                ->whereIn('id', $productIds)
                ->when($hasProductStoreId, fn ($query) => $query->where('store_id', $store->id))
                ->get()
                ->keyBy(static fn (Product $product): int => (int) $product->id);

        $variantsById = collect();
        if (!empty($variantIds) && !$productsById->isEmpty()) {
            $variantQuery = ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->whereIn('product_id', $productsById->keys()->all());
            if (Schema::hasColumn('product_variants', 'enabled')) {
                $variantQuery->where('enabled', true);
            }
            $variantsById = $variantQuery
                ->get()
                ->keyBy(static fn (ProductVariant $variant): int => (int) $variant->id);
        }

        $eligibleProductIds = $this->resolveFlashSaleEligibleProductIds($store, $productsById->values()->all());
        $reservedFlashSaleQuantities = $this->resolveReservedFlashSaleQuantityMap($store->id, $productIds, $ignoreSaleId);
        $overlapFlashSales = $this->resolveOverlapFlashSaleMap($store->id, $productIds, $startTime, $endTime, $ignoreSaleId);
        $missingStoreProductIds = [];

        foreach ($items as $index => $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $variantId = isset($item['variant_id']) ? (int) $item['variant_id'] : null;
            $flashSalePrice = (float) ($item['flash_sale_price'] ?? 0);
            $saleQuantity = (int) ($item['sale_quantity'] ?? 0);

            $product = $productsById->get($productId);

            if (!$product) {
                $errors["items.{$index}.product_id"][] = 'San pham khong thuoc cua hang hien tai.';
                if (!$hasExplicitStoreContext && $productId > 0) {
                    $missingStoreProductIds[$productId] = true;
                }

                continue;
            }

            if (!isset($eligibleProductIds[(int) $product->id])) {
                $errors["items.{$index}.product_id"][] = 'San pham phai duoc duyet truoc khi tao Flash Sale.';
                continue;
            }

            $variant = null;
            if ($variantId !== null && $variantId > 0) {
                $variant = $variantsById->get($variantId);

                if (!$variant || (int) $variant->product_id !== (int) $product->id) {
                    $errors["items.{$index}.variant_id"][] = 'Bien the khong ton tai hoac khong thuoc san pham.';
                    continue;
                }
            }

            $basePrice = $this->resolveBasePrice($product, $variant);
            if ($basePrice <= 0) {
                $errors["items.{$index}.flash_sale_price"][] = 'San pham chua co gia goc hop le.';
                continue;
            }

            if ($flashSalePrice <= 0 || $flashSalePrice >= $basePrice) {
                $errors["items.{$index}.flash_sale_price"][] = 'Gia Flash Sale phai nho hon gia goc.';
            }

            $stock = $variant ? (int) ($variant->stock ?? 0) : (int) ($product->stock ?? 0);
            $inventoryKey = $this->buildFlashSaleInventoryKey($productId, $variantId);
            $requestedBefore = (int) ($requestedQuantities[$inventoryKey] ?? 0);
            $reservedBaseQuantity = (int) ($reservedFlashSaleQuantities[$this->buildFlashSaleInventoryKey($productId, null)] ?? 0);
            $reservedFlashSaleQuantity = $variantId !== null && $variantId > 0
                ? $reservedBaseQuantity + (int) ($reservedFlashSaleQuantities[$inventoryKey] ?? 0)
                : $reservedBaseQuantity;
            $availableStock = max(0, $stock - $requestedBefore);

            if ($saleQuantity > $availableStock) {
                $errors["items.{$index}.sale_quantity"][] = 'So luong Flash Sale vuot qua ton kho kha dung sau khi tru hang da du tru.';
            }

            $perUserLimit = isset($item['per_user_limit']) ? (int) $item['per_user_limit'] : null;
            if ($perUserLimit !== null && $perUserLimit > $saleQuantity) {
                $errors["items.{$index}.per_user_limit"][] = 'Gioi han mua theo tai khoan phai khong vuot qua so luong Flash Sale.';
            }

            $requestedQuantities[$inventoryKey] = $requestedBefore + max(0, $saleQuantity);

            $overlap = $variantId !== null && $variantId > 0
                ? isset($overlapFlashSales['inventory'][$this->buildFlashSaleInventoryKey($productId, null)])
                    || isset($overlapFlashSales['inventory'][$inventoryKey])
                : isset($overlapFlashSales['products'][$productId]);

            if ($overlap) {
                $errors["items.{$index}.product_id"][] = 'San pham/phan loai dang ton tai trong Flash Sale khac co thoi gian trung.';
            }
        }

        if (!$hasExplicitStoreContext && !empty($missingStoreProductIds)) {
            $missingStoreContextHint = Product::query()
                ->whereIn('id', array_keys($missingStoreProductIds))
                ->exists();
        }

        if ($missingStoreContextHint) {
            $errors['store_id'][] = 'Yeu cau hien tai chua gui store_id. Vui long gui store_id de thao tac dung cua hang.';
        }

        if (!empty($errors)) {
            return [
                'error' => $this->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $errors),
            ];
        }

        return ['error' => null];
    }

    private function buildFlashSaleInventoryKey(int $productId, ?int $variantId): string
    {
        return $productId . ':' . ($variantId !== null && $variantId > 0 ? $variantId : 'base');
    }

    private function resolveFlashSaleEligibleProductIds(Store $store, iterable $products): array
    {
        $products = collect($products);
        if ($products->isEmpty()) {
            return [];
        }

        $hasStatusColumn = Schema::hasColumn('products', 'status');
        $hasStoreMenuMapping = Schema::hasTable('store_menu')
            && Schema::hasColumn('store_menu', 'store_id')
            && Schema::hasColumn('store_menu', 'product_id');

        $eligibleIds = [];
        $menuCheckIds = [];

        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $productId = (int) $product->id;
            if ($hasStatusColumn && $product->hasApprovedStatus()) {
                $eligibleIds[$productId] = true;
                continue;
            }

            if ($hasStoreMenuMapping) {
                $menuCheckIds[] = $productId;
                continue;
            }

            if (!$hasStatusColumn) {
                $eligibleIds[$productId] = true;
            }
        }

        $menuCheckIds = array_values(array_unique(array_filter($menuCheckIds)));
        if (!empty($menuCheckIds)) {
            $menuQuery = DB::table('store_menu')
                ->where('store_id', $store->id)
                ->whereIn('product_id', $menuCheckIds);

            if (Schema::hasColumn('store_menu', 'is_available')) {
                $menuQuery->where('is_available', true);
            }

            foreach ($menuQuery->pluck('product_id') as $productId) {
                $eligibleIds[(int) $productId] = true;
            }
        }

        return $eligibleIds;
    }

    private function resolveReservedFlashSaleQuantityMap(
        string $storeId,
        array $productIds,
        ?string $ignoreSaleId
    ): array {
        if (
            empty($productIds)
            || !Schema::hasTable('flash_sale_items')
            || !Schema::hasTable('flash_sales')
        ) {
            return [];
        }

        $rows = DB::table('flash_sale_items as fsi')
            ->join('flash_sales as fs', 'fs.id', '=', 'fsi.flash_sale_id')
            ->where('fs.store_id', $storeId)
            ->where('fs.is_active', true)
            ->where('fs.end_time', '>', now(self::FLASH_SALE_TIMEZONE)->toDateTimeString())
            ->whereIn('fsi.product_id', $productIds)
            ->when($ignoreSaleId, fn ($query) => $query->where('fs.id', '<>', $ignoreSaleId))
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
            $key = $this->buildFlashSaleInventoryKey($productId, $variantId);
            $reservedByKey[$key] = (int) (($reservedByKey[$key] ?? 0) + max(0, (int) ($row->reserved_quantity ?? 0)));
        }

        return $reservedByKey;
    }

    private function resolveOverlapFlashSaleMap(
        string $storeId,
        array $productIds,
        Carbon $startTime,
        Carbon $endTime,
        ?string $ignoreSaleId
    ): array
    {
        if (
            empty($productIds)
            || !Schema::hasTable('flash_sale_items')
            || !Schema::hasTable('flash_sales')
        ) {
            return ['products' => [], 'inventory' => []];
        }

        $rows = DB::table('flash_sale_items as fsi')
            ->join('flash_sales as fs', 'fs.id', '=', 'fsi.flash_sale_id')
            ->where('fs.store_id', $storeId)
            ->where('fs.is_active', true)
            ->whereIn('fsi.product_id', $productIds)
            ->where('fs.start_time', '<', $endTime)
            ->where('fs.end_time', '>', $startTime)
            ->when($ignoreSaleId, fn ($query) => $query->where('fs.id', '<>', $ignoreSaleId))
            ->get(['fsi.product_id', 'fsi.variant_id']);

        $overlapProducts = [];
        $overlapInventory = [];
        foreach ($rows as $row) {
            $productId = (int) ($row->product_id ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $variantId = $row->variant_id !== null ? (int) $row->variant_id : null;
            $overlapProducts[$productId] = true;
            $overlapInventory[$this->buildFlashSaleInventoryKey($productId, $variantId)] = true;
        }

        return [
            'products' => $overlapProducts,
            'inventory' => $overlapInventory,
        ];
    }

    private function resolveBasePrice(Product $product, ?ProductVariant $variant): float
    {
        if ($variant) {
            $variantSalePrice = (float) ($variant->sale_price ?? 0);
            if ($variantSalePrice > 0) {
                return $variantSalePrice;
            }

            $variantPrice = (float) ($variant->price ?? 0);
            if ($variantPrice > 0) {
                return $variantPrice;
            }

            return 0;
        }

        $productSalePrice = (float) ($product->sale_price ?? 0);
        if ($productSalePrice > 0) {
            return $productSalePrice;
        }

        $productPrice = (float) ($product->price ?? 0);
        if ($productPrice > 0) {
            return $productPrice;
        }

        return 0;
    }

    private function parseFlashSaleDateTime(string $value): Carbon
    {
        $normalized = trim($value);
        $hasExplicitTimezone = preg_match('/(?:Z|[+-]\d{2}:\d{2})$/i', $normalized) === 1;

        return $hasExplicitTimezone
            ? Carbon::parse($normalized)
            : Carbon::parse($normalized, self::FLASH_SALE_TIMEZONE);
    }

    private function findOverlapProgram(
        string $storeId,
        Carbon $startTime,
        Carbon $endTime,
        ?string $ignoreSaleId
    ): ?FlashSale {
        $query = FlashSale::query()
            ->where('store_id', $storeId)
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime);

        if ($ignoreSaleId) {
            $query->where('id', '<>', $ignoreSaleId);
        }

        return $query->orderBy('start_time')->first();
    }

    private function serializeFlashSale(FlashSale $sale, array $items, bool $includeItems): array
    {
        $normalizedItems = array_map(function ($item) {
            return [
                'id' => (int) ($item->id ?? 0),
                'product_id' => (int) ($item->product_id ?? 0),
                'variant_id' => isset($item->variant_id) ? (int) $item->variant_id : null,
                'flash_sale_price' => (float) ($item->flash_sale_price ?? 0),
                'sale_quantity' => (int) ($item->sale_quantity ?? 0),
                'sold_quantity' => (int) ($item->sold_quantity ?? 0),
                'per_user_limit' => isset($item->per_user_limit) ? (int) $item->per_user_limit : null,
            ];
        }, $items);
        $productIds = array_values(array_unique(array_filter(array_map(
            static fn (array $item): int => (int) ($item['product_id'] ?? 0),
            $normalizedItems
        ))));
        $saleQuantityTotal = array_reduce(
            $normalizedItems,
            static fn (int $sum, array $item): int => $sum + (int) ($item['sale_quantity'] ?? 0),
            0
        );
        $soldQuantityTotal = array_reduce(
            $normalizedItems,
            static fn (int $sum, array $item): int => $sum + (int) ($item['sold_quantity'] ?? 0),
            0
        );

        $payload = [
            'id' => (string) $sale->id,
            'store_id' => (string) $sale->store_id,
            'name' => (string) $sale->name,
            'start_time' => $sale->start_time ? $sale->start_time->copy()->setTimezone(self::FLASH_SALE_TIMEZONE)->toIso8601String() : null,
            'end_time' => $sale->end_time ? $sale->end_time->copy()->setTimezone(self::FLASH_SALE_TIMEZONE)->toIso8601String() : null,
            'is_active' => (bool) $sale->is_active,
            'items_count' => count($normalizedItems),
            'product_count' => count($productIds),
            'sale_quantity_total' => $saleQuantityTotal,
            'sold_quantity_total' => $soldQuantityTotal,
            'created_at' => optional($sale->created_at)?->toIso8601String(),
            'updated_at' => optional($sale->updated_at)?->toIso8601String(),
        ];

        if (!$includeItems) {
            return $payload;
        }

        $payload['items'] = $normalizedItems;

        return $payload;
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
                $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, false, true),
            ];
        }

        return [$context['store'], $context['user'], null];
    }

    private function resolveContext(Request $request): array
    {
        return $this->resolveStoreAdminAccessContext($request);
    }

    private function resolveRequestedStoreId(Request $request): ?string
    {
        foreach ([
            $request->query('store_id'),
            $request->input('store_id'),
            $request->attributes->get('store_context_id'),
            $request->header('X-Store-Id'),
        ] as $value) {
            $storeId = trim((string) $value);
            if ($storeId !== '') {
                return $storeId;
            }
        }

        return null;
    }

    private function errorResponse(string $code, string $message, int $status, bool $hasStore, bool $authenticated, ?array $details = null): JsonResponse
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

    private function notifyProductUpdates(FlashSale $sale, array $extraProductIds = [], string $action = 'menu_item_updated'): void
    {
        try {
            $productIds = DB::table('flash_sale_items')
                ->where('flash_sale_id', $sale->id)
                ->pluck('product_id')
                ->all();

            $allProductIds = array_merge($productIds, $extraProductIds);
            $allProductIds = array_values(array_unique(array_filter(array_map('intval', $allProductIds))));

            foreach ($allProductIds as $productId) {
                event(new StoreAdminProductUpdated(
                    (string) $sale->store_id,
                    (string) $productId,
                    $action,
                    ['is_lucky' => false]
                ));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify product updates for flash sale: ' . $e->getMessage());
        }
    }
}
