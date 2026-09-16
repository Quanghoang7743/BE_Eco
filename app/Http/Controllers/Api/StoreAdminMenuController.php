<?php

namespace App\Http\Controllers\Api;

use App\Events\StoreAdminProductUpdated;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreAdminMenuController extends Controller
{
    public function pricing(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $pricing = $this->resolvePricing($store);

        return response()->json([
            'data' => [
                'store_id' => $store->id,
                'pricing' => [
                    [
                        'tier' => 'standard',
                        'label' => 'Standard',
                        'price' => $pricing['standard_price'],
                        'currency' => 'VND',
                        'is_active' => $pricing['standard_price'] !== null,
                    ],
                    [
                        'tier' => 'premium',
                        'label' => 'Premium',
                        'price' => $pricing['premium_price'],
                        'currency' => 'VND',
                        'is_active' => $pricing['premium_price'] !== null,
                    ],
                ],
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('store_menu') || !Schema::hasColumn('store_menu', 'category')) {
            return response()->json([
                'data' => [
                    'store_id' => $store->id,
                    'categories' => [],
                    'sub_categories' => [],
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        $categoryRows = DB::table('store_menu')
            ->where('store_id', $store->id)
            ->whereNotNull('category')
            ->where('category', '<>', '')
            ->select('category', DB::raw('COUNT(*) as item_count'))
            ->groupBy('category')
            ->orderBy('category')
            ->get();

        $subCategoryRows = [];
        if (Schema::hasColumn('store_menu', 'sub_category')) {
            $subCategoryRows = DB::table('store_menu')
                ->where('store_id', $store->id)
                ->whereNotNull('sub_category')
                ->where('sub_category', '<>', '')
                ->select('category', 'sub_category', DB::raw('COUNT(*) as item_count'))
                ->groupBy('category', 'sub_category')
                ->orderBy('category')
                ->orderBy('sub_category')
                ->get();
        }

        $categories = [];
        foreach ($categoryRows as $row) {
            $categoryName = (string) $row->category;

            $subCategories = [];
            foreach ($subCategoryRows as $subRow) {
                if ((string) ($subRow->category ?? '') !== $categoryName) {
                    continue;
                }

                $subCategories[] = [
                    'name' => (string) $subRow->sub_category,
                    'item_count' => (int) $subRow->item_count,
                ];
            }

            $categories[] = [
                'name' => $categoryName,
                'item_count' => (int) $row->item_count,
                'sub_categories' => $subCategories,
            ];
        }

        $flatSubCategories = [];
        foreach ($subCategoryRows as $subRow) {
            $flatSubCategories[] = [
                'name' => (string) $subRow->sub_category,
                'category' => (string) ($subRow->category ?? ''),
                'item_count' => (int) $subRow->item_count,
            ];
        }

        return response()->json([
            'data' => [
                'store_id' => $store->id,
                'categories' => $categories,
                'sub_categories' => $flatSubCategories,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
                'total_categories' => count($categories),
            ],
            'errors' => [],
        ]);
    }

    public function updatePricing(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'standard_price' => ['required', 'numeric', 'min:0'],
            'premium_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $minimumAllowedPrice = $this->resolveMinimumAllowedPrice();
        $standardPrice = (float) $validated['standard_price'];
        $premiumPrice = array_key_exists('premium_price', $validated) && $validated['premium_price'] !== null
            ? (float) $validated['premium_price']
            : null;

        if ($standardPrice < $minimumAllowedPrice) {
            return $this->priceBelowMinimumResponse('standard_price', $minimumAllowedPrice);
        }

        if ($premiumPrice !== null && $premiumPrice < $minimumAllowedPrice) {
            return $this->priceBelowMinimumResponse('premium_price', $minimumAllowedPrice);
        }

        $bulkResult = [
            'updated_total' => 0,
            'updated_standard' => 0,
            'updated_premium' => 0,
            'tier_strategy' => 'none',
        ];

        DB::transaction(function () use ($store, $standardPrice, $premiumPrice, &$bulkResult) {
            $this->saveStorePricingConfiguration($store->id, $standardPrice, $premiumPrice);
            $bulkResult = $this->bulkSyncStoreMenuPricing($store->id, $standardPrice, $premiumPrice);
        });

        $store->refresh();
        $pricing = $this->resolvePricing($store);

        Event::dispatch('store.menu_pricing_updated', [
            'store_id' => $store->id,
            'standard_price' => $pricing['standard_price'],
            'premium_price' => $pricing['premium_price'],
            'updated_menu_items' => $bulkResult['updated_total'],
            'changed_by' => $user->id,
            'changed_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'data' => [
                'store_id' => $store->id,
                'pricing' => [
                    [
                        'tier' => 'standard',
                        'label' => 'Standard',
                        'price' => $pricing['standard_price'],
                        'currency' => 'VND',
                        'is_active' => $pricing['standard_price'] !== null,
                    ],
                    [
                        'tier' => 'premium',
                        'label' => 'Premium',
                        'price' => $pricing['premium_price'],
                        'currency' => 'VND',
                        'is_active' => $pricing['premium_price'] !== null,
                    ],
                ],
                'updated_items_count' => (int) $bulkResult['updated_total'],
                'updated_items_breakdown' => [
                    'standard' => (int) $bulkResult['updated_standard'],
                    'premium' => (int) $bulkResult['updated_premium'],
                    'tier_strategy' => (string) $bulkResult['tier_strategy'],
                ],
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function items(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'in_stock' => ['nullable', 'boolean'],
            'is_lucky' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:item_name,-item_name,price,-price,created_at,-created_at,updated_at,-updated_at'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['page_size'] ?? 20);

        if (!Schema::hasTable('store_menu')) {
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
                        'current_page' => $page,
                        'page_size' => $pageSize,
                        'total_pages' => 0,
                        'has_next' => false,
                    ],
                ],
                'errors' => [],
            ]);
        }

        $query = DB::table('store_menu')->where('store_id', $store->id);

        if (
            Schema::hasColumn('store_menu', 'product_id')
            && Schema::hasTable('products')
            && Schema::hasColumn('products', 'status')
        ) {
            $query->where(function ($menuQuery) {
                $menuQuery
                    ->whereNull('store_menu.product_id')
                    ->orWhereExists(function ($productQuery) {
                        $productQuery->select(DB::raw(1))
                            ->from('products')
                            ->whereColumn('products.id', 'store_menu.product_id')
                            ->whereIn(DB::raw("LOWER(COALESCE(products.status, ''))"), Product::APPROVED_STATUSES);
                    });
            });
        }

        if (!empty($validated['q'])) {
            $keyword = trim((string) $validated['q']);
            $query->where(function ($q) use ($keyword) {
                if (Schema::hasColumn('store_menu', 'item_name')) {
                    $q->orWhere('item_name', 'like', '%' . $keyword . '%');
                }

                if (Schema::hasColumn('store_menu', 'item_code')) {
                    $q->orWhere('item_code', 'like', '%' . $keyword . '%');
                }
            });
        }

        if (!empty($validated['category']) && Schema::hasColumn('store_menu', 'category')) {
            $query->where('category', (string) $validated['category']);
        }

        if (array_key_exists('in_stock', $validated) && Schema::hasColumn('store_menu', 'is_available')) {
            $query->where('is_available', (bool) $validated['in_stock']);
        }

        if (array_key_exists('is_lucky', $validated) && Schema::hasColumn('store_menu', 'is_lucky_item')) {
            $query->where('is_lucky_item', (bool) $validated['is_lucky']);
        }

        if (array_key_exists('is_featured', $validated) && Schema::hasColumn('store_menu', 'is_featured')) {
            $query->where('is_featured', (bool) $validated['is_featured']);
        }

        [$sortColumn, $sortDirection] = $this->resolveMenuItemsSort((string) ($validated['sort'] ?? ''));

        $totalItems = (int) (clone $query)->count();
        $items = $query
            ->orderBy($sortColumn, $sortDirection)
            ->forPage($page, $pageSize)
            ->get();

        $productPricingById = $this->resolveLinkedProductPricingMap($items);
        $payload = [];
        foreach ($items as $item) {
            $payload[] = $this->serializeStoreMenuItem($item, $productPricingById);
        }

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

    public function itemDetail(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('store_menu')) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'ITEM_NOT_FOUND',
                        'message' => 'Khong tim thay mon an hoac mon an khong thuoc cua hang hien tai.',
                    ],
                ],
            ], 404);
        }

        $item = DB::table('store_menu')
            ->where('store_id', $store->id)
            ->where('id', $id)
            ->first();

        if (!$item) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'ITEM_NOT_FOUND',
                        'message' => 'Khong tim thay mon an hoac mon an khong thuoc cua hang hien tai.',
                    ],
                ],
            ], 404);
        }

        $productPricingById = $this->resolveLinkedProductPricingMap([$item]);

        return response()->json([
            'data' => [
                'item' => $this->serializeStoreMenuItem($item, $productPricingById, true),
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function createItem(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'item_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'variant_id' => ['nullable', 'integer', 'min:1'],
            'is_lucky_item' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'category' => ['required', 'string', 'max:100'],
            'sub_category' => ['nullable', 'string', 'max:100'],
            'category_level_1_id' => ['nullable', 'integer', 'min:1'],
            'category_level_2_id' => ['nullable', 'integer', 'min:1'],
            'category_level_3_id' => ['nullable', 'integer', 'min:1'],
            'category_level_1_name' => ['nullable', 'string', 'max:100'],
            'category_level_2_name' => ['nullable', 'string', 'max:100'],
            'category_level_3_name' => ['nullable', 'string', 'max:100'],
            'image_url' => ['nullable', 'url', 'max:2000'],
            'is_available' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'item_code' => ['nullable', 'string', 'max:50'],
        ]);

        $price = (float) $validated['price'];
        $minimumAllowedPrice = $this->resolveMinimumAllowedPrice();
        if ($price < $minimumAllowedPrice) {
            return $this->priceBelowMinimumResponse('price', $minimumAllowedPrice);
        }

        if (array_key_exists('original_price', $validated) && $validated['original_price'] !== null) {
            $originalPrice = (float) $validated['original_price'];
            if ($originalPrice < $price) {
                return response()->json([
                    'data' => null,
                    'meta' => [
                        'authenticated' => true,
                        'has_store' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [
                        [
                            'code' => 'INVALID_ORIGINAL_PRICE',
                            'message' => 'original_price phai lon hon hoac bang price.',
                        ],
                    ],
                ], 422);
            }
        }

        $pricing = $this->resolvePricing($store);
        $allowedPrices = collect([$pricing['standard_price'], $pricing['premium_price']])
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => (float) $value)
            ->unique()
            ->values()
            ->all();

        if (!empty($allowedPrices)) {
            $isAllowed = false;
            foreach ($allowedPrices as $allowedPrice) {
                if (abs($allowedPrice - $price) < 0.0001) {
                    $isAllowed = true;
                    break;
                }
            }

            if (!$isAllowed) {
                return response()->json([
                    'data' => null,
                    'meta' => [
                        'authenticated' => true,
                        'has_store' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [
                        [
                            'code' => 'PRICE_NOT_IN_ALLOWED_TIERS',
                            'message' => 'Gia mon phai khop voi mot trong cac muc gia Standard/Premium dang cau hinh.',
                            'details' => [
                                'allowed_prices' => $allowedPrices,
                            ],
                        ],
                    ],
                ], 422);
            }
        }

        $itemCode = !empty($validated['item_code'])
            ? strtoupper(trim((string) $validated['item_code']))
            : $this->generateUniqueItemCode($store);

        $existsCode = DB::table('store_menu')
            ->where('store_id', $store->id)
            ->where('item_code', $itemCode)
            ->exists();

        if ($existsCode) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'ITEM_CODE_EXISTS',
                        'message' => 'item_code da ton tai trong cua hang hien tai.',
                    ],
                ],
            ], 422);
        }

        $mappingResolution = $this->resolveMenuMappingForUpsert($store, $validated);
        if ($mappingResolution['error'] !== null) {
            return $mappingResolution['error'];
        }

        $categoryResolution = $this->resolveOrCreateCategoryPath($store, $validated);
        if ($categoryResolution['error'] !== null) {
            return $categoryResolution['error'];
        }

        $mappedProductId = $mappingResolution['payload']['product_id'] ?? null;
        $mappedVariantId = $mappingResolution['payload']['variant_id'] ?? null;
        $resolvedCategory = $categoryResolution['payload']['category'] ?? (string) $validated['category'];
        $resolvedSubCategory = $categoryResolution['payload']['sub_category'] ?? ($validated['sub_category'] ?? null);

        $item = DB::transaction(function () use ($store, $validated, $itemCode, $price, $mappedProductId, $mappedVariantId, $resolvedCategory, $resolvedSubCategory) {
            $createPayload = [
                'store_id' => $store->id,
                'item_code' => $itemCode,
                'item_name' => (string) $validated['item_name'],
                'description' => $validated['description'] ?? null,
                'price' => $price,
                'original_price' => $validated['original_price'] ?? null,
                'is_lucky_item' => (bool) ($validated['is_lucky_item'] ?? false),
                'category' => $resolvedCategory,
                'sub_category' => $resolvedSubCategory,
                'image_url' => $validated['image_url'] ?? null,
                'is_available' => (bool) ($validated['is_available'] ?? true),
                'is_featured' => (bool) ($validated['is_featured'] ?? false),
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
            ];

            if (Schema::hasColumn('store_menu', 'product_id')) {
                $createPayload['product_id'] = $mappedProductId;
            }

            if (Schema::hasColumn('store_menu', 'variant_id')) {
                $createPayload['variant_id'] = $mappedVariantId;
            }

            return StoreMenu::query()->create($createPayload);
        });

        return response()->json([
            'data' => [
                'item' => [
                    'id' => $item->id,
                    'item_code' => $item->item_code,
                    'item_name' => $item->item_name,
                    'description' => $item->description,
                    'price' => (float) $item->price,
                    'original_price' => $item->original_price !== null ? (float) $item->original_price : null,
                    'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
                    'variant_id' => $item->variant_id !== null ? (int) $item->variant_id : null,
                    'is_lucky' => (bool) $item->is_lucky_item,
                    'category' => $item->category,
                    'sub_category' => $item->sub_category,
                    'category_level_1_id' => $categoryResolution['payload']['category_level_1_id'] ?? null,
                    'category_level_2_id' => $categoryResolution['payload']['category_level_2_id'] ?? null,
                    'category_level_3_id' => $categoryResolution['payload']['category_level_3_id'] ?? null,
                    'image_url' => $item->image_url,
                    'in_stock' => (bool) $item->is_available,
                    'is_featured' => (bool) $item->is_featured,
                    'sort_order' => (int) $item->sort_order,
                    'created_at' => optional($item->created_at)?->toIso8601String(),
                    'updated_at' => optional($item->updated_at)?->toIso8601String(),
                ],
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 201);
    }

    private function resolveOrCreateCategoryPath(Store $store, array $validated): array
    {
        $level1Name = $this->normalizeCategoryName((string) ($validated['category_level_1_name'] ?? ($validated['category'] ?? '')));
        $level2Name = $this->normalizeCategoryName((string) ($validated['category_level_2_name'] ?? ($validated['sub_category'] ?? '')));
        $level3Name = $this->normalizeCategoryName((string) ($validated['category_level_3_name'] ?? ''));

        $level1Id = $this->normalizeNullableCategoryId($validated, 'category_level_1_id');
        $level2Id = $this->normalizeNullableCategoryId($validated, 'category_level_2_id');
        $level3Id = $this->normalizeNullableCategoryId($validated, 'category_level_3_id');

        if ($level1Id === null && $level1Name === null) {
            return [
                'payload' => [],
                'error' => $this->menuMappingError('CATEGORY_LEVEL_1_REQUIRED', 'Can cung cap category hoac category_level_1_name/category_level_1_id.'),
            ];
        }

        if (($level3Id !== null || $level3Name !== null) && $level2Id === null && $level2Name === null) {
            return [
                'payload' => [],
                'error' => $this->menuMappingError('CATEGORY_CHAIN_INVALID', 'Khong the tao/chon cap 3 khi cap 2 trong.'),
            ];
        }

        if (!Schema::hasTable('product_categories')) {
            return [
                'payload' => [
                    'category' => $level1Name,
                    'sub_category' => $level3Name ?? $level2Name,
                    'category_level_1_id' => null,
                    'category_level_2_id' => null,
                    'category_level_3_id' => null,
                ],
                'error' => null,
            ];
        }

        $level1 = $this->resolveOrCreateCategoryNode($store, null, $level1Id, $level1Name);
        if ($level1['error'] !== null) {
            return [
                'payload' => [],
                'error' => $level1['error'],
            ];
        }

        $level2 = ['node' => null, 'error' => null];
        if ($level2Id !== null || $level2Name !== null) {
            $level2 = $this->resolveOrCreateCategoryNode($store, (int) $level1['node']->id, $level2Id, $level2Name);
            if ($level2['error'] !== null) {
                return [
                    'payload' => [],
                    'error' => $level2['error'],
                ];
            }
        }

        $level3 = ['node' => null, 'error' => null];
        if ($level3Id !== null || $level3Name !== null) {
            $level3 = $this->resolveOrCreateCategoryNode($store, (int) $level2['node']->id, $level3Id, $level3Name);
            if ($level3['error'] !== null) {
                return [
                    'payload' => [],
                    'error' => $level3['error'],
                ];
            }
        }

        $menuCategory = (string) ($level1['node']->name ?? $level1Name);
        $menuSubCategory = $level3['node']->name ?? $level2['node']->name ?? null;

        return [
            'payload' => [
                'category' => $menuCategory,
                'sub_category' => $menuSubCategory,
                'category_level_1_id' => isset($level1['node']->id) ? (int) $level1['node']->id : null,
                'category_level_2_id' => isset($level2['node']->id) ? (int) $level2['node']->id : null,
                'category_level_3_id' => isset($level3['node']->id) ? (int) $level3['node']->id : null,
            ],
            'error' => null,
        ];
    }

    private function normalizeNullableCategoryId(array $validated, string $key): ?int
    {
        if (!array_key_exists($key, $validated) || $validated[$key] === null || $validated[$key] === '') {
            return null;
        }

        return (int) $validated[$key];
    }

    private function resolveOrCreateCategoryNode(Store $store, ?int $parentId, ?int $categoryId, ?string $categoryName): array
    {
        if ($categoryId === null && $categoryName === null) {
            return ['node' => null, 'error' => null];
        }

        $hasStoreScope = Schema::hasColumn('product_categories', 'store_id');

        if ($categoryId !== null) {
            $idQuery = DB::table('product_categories')->where('id', $categoryId);
            if ($hasStoreScope) {
                $idQuery->where(function ($scopeQuery) use ($store) {
                    $scopeQuery->whereNull('store_id')->orWhere('store_id', (string) $store->id);
                });
            }

            $category = $idQuery->first();
            if (!$category) {
                return [
                    'node' => null,
                    'error' => $this->menuMappingError('CATEGORY_NOT_FOUND', 'Khong tim thay danh muc trong pham vi su dung cua cua hang.', [
                        'category_id' => $categoryId,
                    ]),
                ];
            }

            $actualParent = $category->parent_id !== null ? (int) $category->parent_id : null;
            if ($actualParent !== $parentId) {
                return [
                    'node' => null,
                    'error' => $this->menuMappingError('CATEGORY_PARENT_MISMATCH', 'Danh muc chon khong dung cap cha-con.', [
                        'category_id' => $categoryId,
                        'expected_parent_id' => $parentId,
                        'actual_parent_id' => $actualParent,
                    ]),
                ];
            }

            return ['node' => $category, 'error' => null];
        }

        $safeName = $this->normalizeCategoryName((string) $categoryName);
        if ($safeName === null) {
            return [
                'node' => null,
                'error' => $this->menuMappingError('CATEGORY_NAME_REQUIRED', 'Ten danh muc khong duoc de trong.'),
            ];
        }

        $existingQuery = DB::table('product_categories')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($safeName)]);

        if ($parentId === null) {
            $existingQuery->whereNull('parent_id');
        } else {
            $existingQuery->where('parent_id', $parentId);
        }

        if ($hasStoreScope) {
            $existingQuery->where(function ($scopeQuery) use ($store) {
                $scopeQuery->whereNull('store_id')->orWhere('store_id', (string) $store->id);
            });
        }

        if ($hasStoreScope) {
            $existingQuery->orderByRaw('CASE WHEN store_id IS NULL THEN 1 ELSE 0 END');
        }

        $existing = $existingQuery->first();

        if ($existing) {
            return ['node' => $existing, 'error' => null];
        }

        if (!$hasStoreScope) {
            // Backward-compatible fallback: keep menu creation working by using
            // the provided names even when product_categories cannot store per-store nodes.
            return [
                'node' => (object) [
                    'id' => null,
                    'name' => $safeName,
                    'parent_id' => $parentId,
                ],
                'error' => null,
            ];
        }

        $insertPayload = [
            'name' => $safeName,
            'slug' => $this->generateUniqueCategorySlug($safeName),
            'parent_id' => $parentId,
            'store_id' => (string) $store->id,
        ];

        if (Schema::hasColumn('product_categories', 'sort_order')) {
            $sortQuery = DB::table('product_categories');
            if ($parentId === null) {
                $sortQuery->whereNull('parent_id');
            } else {
                $sortQuery->where('parent_id', $parentId);
            }
            $insertPayload['sort_order'] = ((int) ($sortQuery->max('sort_order') ?? 0)) + 1;
        }

        if (Schema::hasColumn('product_categories', 'created_at')) {
            $insertPayload['created_at'] = now();
        }
        if (Schema::hasColumn('product_categories', 'updated_at')) {
            $insertPayload['updated_at'] = now();
        }

        $newId = DB::table('product_categories')->insertGetId($insertPayload);
        $created = DB::table('product_categories')->where('id', $newId)->first();

        return ['node' => $created, 'error' => null];
    }

    private function normalizeCategoryName(string $value): ?string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $value));
        return $normalized === '' ? null : $normalized;
    }

    private function generateUniqueCategorySlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'category';
        }

        $slug = $base;
        $suffix = 1;

        while (ProductCategory::query()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'item_name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'product_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'variant_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sub_category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_level_1_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'category_level_2_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'category_level_3_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'category_level_1_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_level_2_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_level_3_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_lucky_item' => ['sometimes', 'boolean'],
            'is_lucky' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2000'],
        ]);

        if (empty($validated)) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'NO_UPDATABLE_FIELDS',
                        'message' => 'Request phai co it nhat 1 truong hop le de cap nhat.',
                    ],
                ],
            ], 422);
        }

        $currentItem = DB::table('store_menu')
            ->where('id', $id)
            ->where('store_id', $store->id)
            ->first();

        if (!$currentItem) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'ITEM_NOT_FOUND',
                        'message' => 'Khong tim thay mon an hoac mon an khong thuoc cua hang hien tai.',
                    ],
                ],
            ], 404);
        }

        $mappingResolution = $this->resolveMenuMappingForUpsert($store, $validated, $currentItem);
        if ($mappingResolution['error'] !== null) {
            return $mappingResolution['error'];
        }

        $categoryResolution = ['payload' => [], 'error' => null];
        $hasCategoryUpdate = collect([
            'category',
            'sub_category',
            'category_level_1_id',
            'category_level_2_id',
            'category_level_3_id',
            'category_level_1_name',
            'category_level_2_name',
            'category_level_3_name',
        ])->contains(fn ($field) => array_key_exists($field, $validated));

        if ($hasCategoryUpdate) {
            $categoryResolution = $this->resolveOrCreateCategoryPath($store, array_merge([
                'category' => $currentItem->category ?? null,
                'sub_category' => $currentItem->sub_category ?? null,
            ], $validated));
            if ($categoryResolution['error'] !== null) {
                return $categoryResolution['error'];
            }
        }

        $updatePayload = [];

        if (array_key_exists('item_name', $validated) && Schema::hasColumn('store_menu', 'item_name')) {
            $updatePayload['item_name'] = (string) $validated['item_name'];
        }

        if (array_key_exists('description', $validated) && Schema::hasColumn('store_menu', 'description')) {
            $updatePayload['description'] = $validated['description'];
        }

        if ($hasCategoryUpdate && Schema::hasColumn('store_menu', 'category')) {
            $updatePayload['category'] = $categoryResolution['payload']['category'] ?? ($validated['category'] ?? null);
        }

        if ($hasCategoryUpdate && Schema::hasColumn('store_menu', 'sub_category')) {
            $updatePayload['sub_category'] = $categoryResolution['payload']['sub_category'] ?? ($validated['sub_category'] ?? null);
        }

        if (array_key_exists('image_url', $validated) && Schema::hasColumn('store_menu', 'image_url')) {
            $updatePayload['image_url'] = $validated['image_url'];
        }

        if ($mappingResolution['handled'] && Schema::hasColumn('store_menu', 'product_id')) {
            $updatePayload['product_id'] = $mappingResolution['payload']['product_id'];
        }

        if ($mappingResolution['handled'] && Schema::hasColumn('store_menu', 'variant_id')) {
            $updatePayload['variant_id'] = $mappingResolution['payload']['variant_id'];
        }

        if (array_key_exists('is_available', $validated) && Schema::hasColumn('store_menu', 'is_available')) {
            $updatePayload['is_available'] = (bool) $validated['is_available'];
        }

        $isLuckyField = null;
        if (array_key_exists('is_lucky_item', $validated)) {
            $isLuckyField = (bool) $validated['is_lucky_item'];
        }
        if (array_key_exists('is_lucky', $validated)) {
            $isLuckyField = (bool) $validated['is_lucky'];
        }
        if ($isLuckyField !== null && Schema::hasColumn('store_menu', 'is_lucky_item')) {
            $updatePayload['is_lucky_item'] = $isLuckyField;
        }

        if (array_key_exists('price', $validated)) {
            $price = (float) $validated['price'];
            $minimumAllowedPrice = $this->resolveMinimumAllowedPrice();
            if ($price < $minimumAllowedPrice) {
                return $this->priceBelowMinimumResponse('price', $minimumAllowedPrice);
            }

            $pricing = $this->resolvePricing($store);
            $allowedPrices = collect([$pricing['standard_price'], $pricing['premium_price']])
                ->filter(fn ($value) => $value !== null)
                ->map(fn ($value) => (float) $value)
                ->unique()
                ->values()
                ->all();

            if (!empty($allowedPrices)) {
                $isAllowed = false;
                foreach ($allowedPrices as $allowedPrice) {
                    if (abs($allowedPrice - $price) < 0.0001) {
                        $isAllowed = true;
                        break;
                    }
                }

                if (!$isAllowed) {
                    return response()->json([
                        'data' => null,
                        'meta' => [
                            'authenticated' => true,
                            'has_store' => true,
                            'request_at' => now()->toIso8601String(),
                        ],
                        'errors' => [
                            [
                                'code' => 'PRICE_NOT_IN_ALLOWED_TIERS',
                                'message' => 'Gia mon phai khop voi mot trong cac muc gia Standard/Premium dang cau hinh.',
                                'details' => [
                                    'allowed_prices' => $allowedPrices,
                                ],
                            ],
                        ],
                    ], 422);
                }
            }

            if (Schema::hasColumn('store_menu', 'price')) {
                $updatePayload['price'] = $price;
            }
        }

        if (empty($updatePayload)) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'NO_EFFECTIVE_FIELDS',
                        'message' => 'Khong co truong nao phu hop de cap nhat trong he thong hien tai.',
                    ],
                ],
            ], 422);
        }

        if (Schema::hasColumn('store_menu', 'updated_at')) {
            $updatePayload['updated_at'] = now();
        }

        DB::transaction(function () use ($store, $id, $updatePayload) {
            DB::table('store_menu')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->update($updatePayload);
        });

        $updatedItem = DB::table('store_menu')
            ->where('id', $id)
            ->where('store_id', $store->id)
            ->first();

        Event::dispatch('menu.updated', [
            'store_id' => $store->id,
            'item_id' => $id,
            'updated_fields' => array_keys($updatePayload),
            'changed_by' => $user->id,
            'changed_at' => now()->toIso8601String(),
        ]);

        Event::dispatch('store.menu_item_updated', [
            'store_id' => $store->id,
            'item_id' => $id,
            'updated_fields' => array_keys($updatePayload),
            'changed_by' => $user->id,
            'changed_at' => now()->toIso8601String(),
        ]);

        event(new StoreAdminProductUpdated(
            (string) $store->id,
            isset($updatedItem->product_id) ? (string) $updatedItem->product_id : '',
            'menu_item_updated',
            [
                'item_id' => (string) $id,
                'in_stock' => isset($updatedItem->is_available) ? (bool) $updatedItem->is_available : null,
                'is_lucky' => isset($updatedItem->is_lucky_item) ? (bool) $updatedItem->is_lucky_item : null,
                'updated_at' => isset($updatedItem->updated_at)
                    ? (string) $updatedItem->updated_at
                    : now()->toIso8601String(),
            ],
        ));

        $productPricingById = $this->resolveLinkedProductPricingMap([$updatedItem]);

        return response()->json([
            'data' => [
                'item' => $this->serializeStoreMenuItem($updatedItem, $productPricingById, true),
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function deleteItem(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('store_menu')) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'ITEM_NOT_FOUND',
                        'message' => 'Khong tim thay mon an hoac mon an khong thuoc cua hang hien tai.',
                    ],
                ],
            ], 404);
        }

        $item = DB::table('store_menu')
            ->where('id', $id)
            ->where('store_id', $store->id)
            ->first();

        if (!$item) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'ITEM_NOT_FOUND',
                        'message' => 'Khong tim thay mon an hoac mon an khong thuoc cua hang hien tai.',
                    ],
                ],
            ], 404);
        }

        $deletedRows = 0;
        DB::transaction(function () use ($store, $id, &$deletedRows) {
            $deletedRows = DB::table('store_menu')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->delete();
        });

        if ($deletedRows < 1) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'ITEM_NOT_FOUND',
                        'message' => 'Khong tim thay mon an hoac mon an khong thuoc cua hang hien tai.',
                    ],
                ],
            ], 404);
        }

        Event::dispatch('menu.deleted', [
            'store_id' => $store->id,
            'item_id' => $id,
            'deleted_by' => $user->id,
            'deleted_at' => now()->toIso8601String(),
        ]);

        Event::dispatch('store.menu_item_deleted', [
            'store_id' => $store->id,
            'item_id' => $id,
            'item_code' => $item->item_code ?? null,
            'item_name' => $item->item_name ?? null,
            'deleted_by' => $user->id,
            'deleted_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'data' => [
                'deleted' => true,
                'item' => [
                    'id' => $item->id ?? null,
                    'item_code' => $item->item_code ?? null,
                    'item_name' => $item->item_name ?? null,
                ],
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function deleteCategory(Request $request, string $name): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (empty($name)) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'INVALID_CATEGORY_NAME',
                        'message' => 'Tên danh mục không được để trống.',
                    ],
                ],
            ], 422);
        }

        // We will perform deletion in a transaction to ensure integrity
        $affectedItems = 0;
        $affectedProducts = 0;
        $deletedCategories = 0;

        DB::transaction(function () use ($store, $name, &$affectedItems, &$affectedProducts, &$deletedCategories) {
            // 1. Delete from product_categories table
            if (Schema::hasTable('product_categories')) {
                // Find all categories matching the name (case-insensitive) for this store
                $categoryQuery = DB::table('product_categories')
                    ->where('store_id', $store->id)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);

                $categoryIds = $categoryQuery->pluck('id')->all();

                if (!empty($categoryIds)) {
                    // Update products that reference these categories
                    if (Schema::hasTable('products')) {
                        $affectedProducts = DB::table('products')
                            ->where('store_id', $store->id)
                            ->whereIn('category_id', $categoryIds)
                            ->update(['category_id' => null]);
                    }

                    // Delete the categories
                    $deletedCategories = $categoryQuery->delete();
                }
            }

            // 2. Update store_menu items that have this category name to "Khác"
            if (Schema::hasTable('store_menu')) {
                $affectedItems = DB::table('store_menu')
                    ->where('store_id', $store->id)
                    ->whereRaw('LOWER(category) = ?', [mb_strtolower($name)])
                    ->update(['category' => 'Khác']);
            }
        });

        return response()->json([
            'data' => [
                'success' => true,
                'deleted_categories_count' => $deletedCategories,
                'updated_menu_items_count' => $affectedItems,
                'updated_products_count' => $affectedProducts,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function updateItemFlags(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('store_menu')) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'ITEM_NOT_FOUND',
                        'message' => 'Khong tim thay mon an hoac mon an khong thuoc cua hang hien tai.',
                    ],
                ],
            ], 404);
        }

        $validated = $request->validate([
            'is_lucky' => ['sometimes', 'boolean'],
            'in_stock' => ['sometimes', 'boolean'],
        ]);

        if (!array_key_exists('is_lucky', $validated) && !array_key_exists('in_stock', $validated)) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'NO_UPDATABLE_FLAGS',
                        'message' => 'Request phai co it nhat mot truong is_lucky hoac in_stock.',
                    ],
                ],
            ], 422);
        }

        $item = DB::table('store_menu')
            ->where('id', $id)
            ->where('store_id', $store->id)
            ->first();

        if (!$item) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'ITEM_NOT_FOUND',
                        'message' => 'Khong tim thay mon an hoac mon an khong thuoc cua hang hien tai.',
                    ],
                ],
            ], 404);
        }

        $updatePayload = [];
        if (array_key_exists('is_lucky', $validated) && Schema::hasColumn('store_menu', 'is_lucky_item')) {
            $updatePayload['is_lucky_item'] = (bool) $validated['is_lucky'];
        }

        if (array_key_exists('in_stock', $validated) && Schema::hasColumn('store_menu', 'is_available')) {
            $updatePayload['is_available'] = (bool) $validated['in_stock'];
        }

        if (empty($updatePayload)) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'NO_EFFECTIVE_FIELDS',
                        'message' => 'Khong co truong nao phu hop de cap nhat trong he thong hien tai.',
                    ],
                ],
            ], 422);
        }

        if (Schema::hasColumn('store_menu', 'updated_at')) {
            $updatePayload['updated_at'] = now();
        }

        DB::transaction(function () use ($store, $id, $updatePayload) {
            DB::table('store_menu')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->update($updatePayload);
        });

        $updatedItem = DB::table('store_menu')
            ->where('id', $id)
            ->where('store_id', $store->id)
            ->first();

        Event::dispatch('menu.item_status_changed', [
            'store_id' => $store->id,
            'item_id' => $id,
            'is_lucky' => isset($updatedItem->is_lucky_item) ? (bool) $updatedItem->is_lucky_item : null,
            'in_stock' => isset($updatedItem->is_available) ? (bool) $updatedItem->is_available : null,
            'changed_by' => $user->id,
            'changed_at' => now()->toIso8601String(),
        ]);

        Event::dispatch('store.menu_item_flags_updated', [
            'store_id' => $store->id,
            'item_id' => $id,
            'updated_fields' => array_keys($updatePayload),
            'changed_by' => $user->id,
            'changed_at' => now()->toIso8601String(),
        ]);

        event(new StoreAdminProductUpdated(
            (string) $store->id,
            isset($updatedItem->product_id) ? (string) $updatedItem->product_id : '',
            'menu_item_flags_updated',
            [
                'item_id' => (string) $id,
                'in_stock' => isset($updatedItem->is_available) ? (bool) $updatedItem->is_available : null,
                'is_lucky' => isset($updatedItem->is_lucky_item) ? (bool) $updatedItem->is_lucky_item : null,
                'updated_at' => isset($updatedItem->updated_at)
                    ? (string) $updatedItem->updated_at
                    : now()->toIso8601String(),
            ],
        ));

        return response()->json([
            'data' => [
                'item' => [
                    'id' => $updatedItem->id ?? null,
                    'item_code' => $updatedItem->item_code ?? null,
                    'item_name' => $updatedItem->item_name ?? null,
                    'is_lucky' => isset($updatedItem->is_lucky_item) ? (bool) $updatedItem->is_lucky_item : false,
                    'in_stock' => isset($updatedItem->is_available) ? (bool) $updatedItem->is_available : false,
                    'updated_at' => isset($updatedItem->updated_at) ? (string) $updatedItem->updated_at : null,
                ],
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function updateItemImage(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('store_menu')) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'ITEM_NOT_FOUND',
                        'message' => 'Khong tim thay mon an hoac mon an khong thuoc cua hang hien tai.',
                    ],
                ],
            ], 404);
        }

        if (!Schema::hasColumn('store_menu', 'image_url')) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'COLUMN_NOT_FOUND',
                        'message' => 'Cau truc bang store_menu chua co truong image_url.',
                    ],
                ],
            ], 500);
        }

        $validated = $request->validate([
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'required_without:image_url'],
            'image_url' => ['nullable', 'url', 'max:2000', 'required_without:image'],
        ]);

        $item = DB::table('store_menu')
            ->where('id', $id)
            ->where('store_id', $store->id)
            ->first();

        if (!$item) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'ITEM_NOT_FOUND',
                        'message' => 'Khong tim thay mon an hoac mon an khong thuoc cua hang hien tai.',
                    ],
                ],
            ], 404);
        }

        $oldImageUrl = (string) ($item->image_url ?? '');
        $newImageUrl = null;
        $uploadedPath = null;

        if ($request->hasFile('image')) {
            $uploadedPath = $request->file('image')->store("uploads/store-menu/{$store->id}", 'public');
            $newImageUrl = url(Storage::url($uploadedPath));
        } elseif (!empty($validated['image_url'])) {
            $newImageUrl = $this->normalizeAbsoluteUrl((string) $validated['image_url']);
        }

        if ($newImageUrl === null || $newImageUrl === '') {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [
                    [
                        'code' => 'INVALID_IMAGE_INPUT',
                        'message' => 'Can cung cap image hoac image_url hop le.',
                    ],
                ],
            ], 422);
        }

        $payload = [
            'image_url' => $newImageUrl,
        ];
        if (Schema::hasColumn('store_menu', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        DB::transaction(function () use ($store, $id, $payload) {
            DB::table('store_menu')
                ->where('id', $id)
                ->where('store_id', $store->id)
                ->update($payload);
        });

        if ($uploadedPath !== null && $oldImageUrl !== '') {
            $oldPath = $this->extractStoragePath($oldImageUrl);
            if ($oldPath && $oldPath !== $uploadedPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $updatedItem = DB::table('store_menu')
            ->where('id', $id)
            ->where('store_id', $store->id)
            ->first();

        Event::dispatch('menu.item_image_updated', [
            'store_id' => $store->id,
            'item_id' => $id,
            'image_url' => $updatedItem->image_url ?? null,
            'changed_by' => $user->id,
            'changed_at' => now()->toIso8601String(),
        ]);

        Event::dispatch('store.menu_item_image_updated', [
            'store_id' => $store->id,
            'item_id' => $id,
            'image_url' => $updatedItem->image_url ?? null,
            'changed_by' => $user->id,
            'changed_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'data' => [
                'item' => [
                    'id' => $updatedItem->id ?? null,
                    'item_code' => $updatedItem->item_code ?? null,
                    'item_name' => $updatedItem->item_name ?? null,
                    'image_url' => $updatedItem->image_url ?? null,
                    'updated_at' => isset($updatedItem->updated_at) ? (string) $updatedItem->updated_at : null,
                ],
                'upload' => [
                    'source' => $uploadedPath !== null ? 'file' : 'url',
                    'path' => $uploadedPath,
                ],
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function resolveMenuMappingForUpsert(Store $store, array $validated, ?object $currentItem = null): array
    {
        $hasProductKey = array_key_exists('product_id', $validated);
        $hasVariantKey = array_key_exists('variant_id', $validated);

        if (!$hasProductKey && !$hasVariantKey) {
            return [
                'handled' => false,
                'payload' => [],
                'error' => null,
            ];
        }

        $currentProductId = ($currentItem && isset($currentItem->product_id) && $currentItem->product_id !== null)
            ? (int) $currentItem->product_id
            : null;

        $currentVariantId = ($currentItem && isset($currentItem->variant_id) && $currentItem->variant_id !== null)
            ? (int) $currentItem->variant_id
            : null;

        $productId = $hasProductKey
            ? ($validated['product_id'] === null ? null : (int) $validated['product_id'])
            : $currentProductId;

        $variantId = $hasVariantKey
            ? ($validated['variant_id'] === null ? null : (int) $validated['variant_id'])
            : $currentVariantId;

        if ($hasProductKey && !$hasVariantKey && $currentVariantId !== null && $productId !== $currentProductId) {
            $variantId = null;
        }

        if ($productId !== null && $productId <= 0) {
            return [
                'handled' => true,
                'payload' => [],
                'error' => $this->menuMappingError('INVALID_PRODUCT_MAPPING', 'product_id khong hop le.', [
                    'product_id' => $productId,
                ]),
            ];
        }

        if ($variantId !== null && $variantId <= 0) {
            return [
                'handled' => true,
                'payload' => [],
                'error' => $this->menuMappingError('INVALID_VARIANT_MAPPING', 'variant_id khong hop le.', [
                    'variant_id' => $variantId,
                ]),
            ];
        }

        if ($productId !== null) {
            $product = Product::query()->where('id', $productId)->first();
            if (!$product) {
                return [
                    'handled' => true,
                    'payload' => [],
                    'error' => $this->menuMappingError('PRODUCT_NOT_FOUND', 'Khong tim thay san pham de lien ket menu.', [
                        'product_id' => $productId,
                    ]),
                ];
            }

            if (Schema::hasColumn('products', 'store_id')) {
                $productStoreId = (string) ($product->store_id ?? '');
                if ($productStoreId === '' || $productStoreId !== (string) $store->id) {
                    return [
                        'handled' => true,
                        'payload' => [],
                        'error' => $this->menuMappingError('PRODUCT_STORE_MISMATCH', 'San pham khong thuoc cua hang hien tai.', [
                            'product_id' => $productId,
                            'store_id' => (string) $store->id,
                        ]),
                    ];
                }
            }
        }

        if ($variantId !== null) {
            $variant = ProductVariant::query()->where('id', $variantId)->first();
            if (!$variant) {
                return [
                    'handled' => true,
                    'payload' => [],
                    'error' => $this->menuMappingError('VARIANT_NOT_FOUND', 'Khong tim thay bien the de lien ket menu.', [
                        'variant_id' => $variantId,
                    ]),
                ];
            }

            $variantProduct = Product::query()->where('id', (int) $variant->product_id)->first();
            if (!$variantProduct) {
                return [
                    'handled' => true,
                    'payload' => [],
                    'error' => $this->menuMappingError('VARIANT_PRODUCT_NOT_FOUND', 'Bien the khong con san pham cha hop le.', [
                        'variant_id' => $variantId,
                        'product_id' => (int) $variant->product_id,
                    ]),
                ];
            }

            if (Schema::hasColumn('products', 'store_id')) {
                $variantProductStoreId = (string) ($variantProduct->store_id ?? '');
                if ($variantProductStoreId === '' || $variantProductStoreId !== (string) $store->id) {
                    return [
                        'handled' => true,
                        'payload' => [],
                        'error' => $this->menuMappingError('VARIANT_STORE_MISMATCH', 'Bien the khong thuoc cua hang hien tai.', [
                            'variant_id' => $variantId,
                            'store_id' => (string) $store->id,
                        ]),
                    ];
                }
            }

            $variantProductId = (int) $variant->product_id;
            if ($productId !== null && $productId !== $variantProductId) {
                return [
                    'handled' => true,
                    'payload' => [],
                    'error' => $this->menuMappingError('PRODUCT_VARIANT_MISMATCH', 'variant_id khong thuoc product_id da chon.', [
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'variant_product_id' => $variantProductId,
                    ]),
                ];
            }

            $productId = $variantProductId;
        }

        return [
            'handled' => true,
            'payload' => [
                'product_id' => $productId,
                'variant_id' => $variantId,
            ],
            'error' => null,
        ];
    }

    private function menuMappingError(string $code, string $message, array $details = []): JsonResponse
    {
        $errorPayload = [
            'code' => $code,
            'message' => $message,
        ];

        if (!empty($details)) {
            $errorPayload['details'] = $details;
        }

        return response()->json([
            'data' => null,
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [$errorPayload],
        ], 422);
    }

    private function resolveLinkedProductPricingMap(iterable $items): array
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'id')) {
            return [];
        }

        $productIds = [];
        foreach ($items as $item) {
            if (isset($item->product_id) && $item->product_id !== null) {
                $productId = (int) $item->product_id;
                if ($productId > 0) {
                    $productIds[$productId] = true;
                }
            }
        }

        if (empty($productIds)) {
            return [];
        }

        $columns = ['id'];
        $hasPrice = Schema::hasColumn('products', 'price');
        $hasSalePrice = Schema::hasColumn('products', 'sale_price');

        if ($hasPrice) {
            $columns[] = 'price';
        }
        if ($hasSalePrice) {
            $columns[] = 'sale_price';
        }
        if (!$hasPrice && !$hasSalePrice) {
            return [];
        }

        $rows = DB::table('products')
            ->select($columns)
            ->whereIn('id', array_keys($productIds))
            ->get();

        $pricingById = [];
        foreach ($rows as $row) {
            $pricingById[(int) $row->id] = [
                'price' => $hasPrice && $row->price !== null ? (float) $row->price : null,
                'sale_price' => $hasSalePrice && $row->sale_price !== null ? (float) $row->sale_price : null,
            ];
        }

        return $pricingById;
    }

    private function serializeStoreMenuItem(object $item, array $productPricingById = [], bool $includeDetail = false): array
    {
        [$price, $originalPrice, $salePrice] = $this->resolveStoreMenuItemDisplayPrices($item, $productPricingById);

        $payload = [
            'id' => $item->id ?? null,
            'item_code' => $item->item_code ?? null,
            'item_name' => $item->item_name ?? null,
            'product_id' => isset($item->product_id) && $item->product_id !== null ? (int) $item->product_id : null,
            'variant_id' => isset($item->variant_id) && $item->variant_id !== null ? (int) $item->variant_id : null,
            'category' => $item->category ?? null,
            'sub_category' => $item->sub_category ?? null,
            'price' => $price,
            'sale_price' => $salePrice,
            'original_price' => $originalPrice,
            'is_lucky' => isset($item->is_lucky_item) ? (bool) $item->is_lucky_item : false,
            'in_stock' => isset($item->is_available) ? (bool) $item->is_available : false,
            'image_url' => $item->image_url ?? null,
            'updated_at' => isset($item->updated_at) ? (string) $item->updated_at : null,
        ];

        if ($includeDetail) {
            $payload['description'] = $item->description ?? null;
            $payload['is_featured'] = isset($item->is_featured) ? (bool) $item->is_featured : false;
            $payload['sort_order'] = isset($item->sort_order) ? (int) $item->sort_order : 0;
            $payload['created_at'] = isset($item->created_at) ? (string) $item->created_at : null;
        }

        return $payload;
    }

    private function resolveStoreMenuItemDisplayPrices(object $item, array $productPricingById): array
    {
        $menuPrice = property_exists($item, 'price') && $item->price !== null ? (float) $item->price : null;
        $menuOriginalPrice = property_exists($item, 'original_price') && $item->original_price !== null
            ? (float) $item->original_price
            : null;

        $productId = isset($item->product_id) && $item->product_id !== null ? (int) $item->product_id : null;
        $productPricing = $productId !== null ? ($productPricingById[$productId] ?? null) : null;

        if ($productPricing === null) {
            return [$menuPrice, $menuOriginalPrice, null];
        }

        $productPrice = $productPricing['price'];
        $productSalePrice = $productPricing['sale_price'];
        if ($productPrice === null && $productSalePrice === null) {
            return [$menuPrice, $menuOriginalPrice, null];
        }

        $displayPrice = $productPrice ?? $productSalePrice;
        $effectiveSalePrice = null;
        if ($productSalePrice !== null && $productSalePrice > 0 && ($productPrice === null || $productSalePrice < $productPrice)) {
            $displayPrice = $productSalePrice;
            $effectiveSalePrice = $productSalePrice;
        }

        return [
            $displayPrice,
            $productPrice ?? $menuOriginalPrice,
            $effectiveSalePrice,
        ];
    }

    private function resolvePricing(Store $store): array
    {
        $standardPrice = null;
        $premiumPrice = null;

        $storePriceColumns = [
            'menu_standard_price',
            'standard_price',
            'price_standard',
            'menu_price_standard',
            'menu_price',
            'default_menu_price',
        ];

        $storePremiumColumns = [
            'menu_premium_price',
            'premium_price',
            'price_premium',
            'menu_price_premium',
        ];

        foreach ($storePriceColumns as $column) {
            if (Schema::hasColumn('stores', $column) && $store->{$column} !== null) {
                $standardPrice = $this->toNumber($store->{$column});
                break;
            }
        }

        foreach ($storePremiumColumns as $column) {
            if (Schema::hasColumn('stores', $column) && $store->{$column} !== null) {
                $premiumPrice = $this->toNumber($store->{$column});
                break;
            }
        }

        if (($standardPrice === null || $premiumPrice === null) && Schema::hasTable('store_menu')) {
            $prices = DB::table('store_menu')
                ->where('store_id', $store->id)
                ->whereNotNull('price')
                ->select('price')
                ->distinct()
                ->orderBy('price')
                ->pluck('price')
                ->map(fn ($value) => $this->toNumber($value))
                ->filter(fn ($value) => $value !== null)
                ->values();

            if ($standardPrice === null && $prices->count() > 0) {
                $standardPrice = $prices->get(0);
            }

            if ($premiumPrice === null && $prices->count() > 1) {
                $premiumPrice = $prices->get(1);
            }

            if ($premiumPrice === null && $prices->count() > 1) {
                $premiumPrice = $prices->last();
            }
        }

        return [
            'standard_price' => $standardPrice,
            'premium_price' => $premiumPrice,
        ];
    }

    private function toNumber($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function saveStorePricingConfiguration(string $storeId, float $standardPrice, ?float $premiumPrice): void
    {
        $payload = [];

        $storePriceColumns = [
            'menu_standard_price',
            'standard_price',
            'price_standard',
            'menu_price_standard',
            'menu_price',
            'default_menu_price',
        ];

        $storePremiumColumns = [
            'menu_premium_price',
            'premium_price',
            'price_premium',
            'menu_price_premium',
        ];

        foreach ($storePriceColumns as $column) {
            if (Schema::hasColumn('stores', $column)) {
                $payload[$column] = $standardPrice;
            }
        }

        foreach ($storePremiumColumns as $column) {
            if (Schema::hasColumn('stores', $column)) {
                $payload[$column] = $premiumPrice;
            }
        }

        if (!empty($payload)) {
            if (Schema::hasColumn('stores', 'updated_at')) {
                $payload['updated_at'] = now();
            }

            DB::table('stores')
                ->where('id', $storeId)
                ->update($payload);
        }
    }

    private function bulkSyncStoreMenuPricing(string $storeId, float $standardPrice, ?float $premiumPrice): array
    {
        if (!Schema::hasTable('store_menu') || !Schema::hasColumn('store_menu', 'price')) {
            return [
                'updated_total' => 0,
                'updated_standard' => 0,
                'updated_premium' => 0,
                'tier_strategy' => 'none',
            ];
        }

        $baseQuery = DB::table('store_menu')->where('store_id', $storeId);
        $updatedStandard = 0;
        $updatedPremium = 0;
        $strategy = 'all_standard';

        if (Schema::hasColumn('store_menu', 'price_tier')) {
            $strategy = 'price_tier';
            $updatedStandard = (clone $baseQuery)
                ->whereIn(DB::raw('LOWER(price_tier)'), ['standard', 'std'])
                ->update(['price' => $standardPrice, 'updated_at' => now()]);

            if ($premiumPrice !== null) {
                $updatedPremium = (clone $baseQuery)
                    ->whereIn(DB::raw('LOWER(price_tier)'), ['premium', 'pro'])
                    ->update(['price' => $premiumPrice, 'updated_at' => now()]);
            }
        } elseif (Schema::hasColumn('store_menu', 'tier')) {
            $strategy = 'tier';
            $updatedStandard = (clone $baseQuery)
                ->whereIn(DB::raw('LOWER(tier)'), ['standard', 'std'])
                ->update(['price' => $standardPrice, 'updated_at' => now()]);

            if ($premiumPrice !== null) {
                $updatedPremium = (clone $baseQuery)
                    ->whereIn(DB::raw('LOWER(tier)'), ['premium', 'pro'])
                    ->update(['price' => $premiumPrice, 'updated_at' => now()]);
            }
        } elseif (Schema::hasColumn('store_menu', 'is_premium')) {
            $strategy = 'is_premium';
            $updatedStandard = (clone $baseQuery)
                ->where('is_premium', false)
                ->update(['price' => $standardPrice, 'updated_at' => now()]);

            if ($premiumPrice !== null) {
                $updatedPremium = (clone $baseQuery)
                    ->where('is_premium', true)
                    ->update(['price' => $premiumPrice, 'updated_at' => now()]);
            }
        } elseif (Schema::hasColumn('store_menu', 'is_lucky_item')) {
            $strategy = 'is_lucky_item';
            $updatedStandard = (clone $baseQuery)
                ->where('is_lucky_item', false)
                ->update(['price' => $standardPrice, 'updated_at' => now()]);

            if ($premiumPrice !== null) {
                $updatedPremium = (clone $baseQuery)
                    ->where('is_lucky_item', true)
                    ->update(['price' => $premiumPrice, 'updated_at' => now()]);
            }
        } else {
            $updatedStandard = (clone $baseQuery)
                ->update(['price' => $standardPrice, 'updated_at' => now()]);
        }

        return [
            'updated_total' => (int) ($updatedStandard + $updatedPremium),
            'updated_standard' => (int) $updatedStandard,
            'updated_premium' => (int) $updatedPremium,
            'tier_strategy' => $strategy,
        ];
    }

    private function resolveMinimumAllowedPrice(): float
    {
        $default = 5000.0;

        $tables = ['settings', 'app_settings'];
        $keys = [
            'ticket_value',
            'ticket_value_vnd',
            'ticket_unit_value',
            'one_ticket_value',
            's43_ticket_value',
        ];

        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            if (!Schema::hasColumn($table, 'key') || !Schema::hasColumn($table, 'value')) {
                continue;
            }

            $value = DB::table($table)
                ->whereIn('key', $keys)
                ->value('value');

            if (is_numeric($value)) {
                return max($default, (float) $value);
            }
        }

        return $default;
    }

    private function priceBelowMinimumResponse(string $field, float $minimum): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [
                [
                    'code' => 'PRICE_BELOW_MINIMUM',
                    'message' => 'Gia moi khong duoc nho hon gia tri toi thieu cua 1 ve.',
                    'details' => [
                        'field' => $field,
                        'minimum_allowed' => $minimum,
                    ],
                ],
            ],
        ], 422);
    }

    private function resolveMenuItemsSort(string $sort): array
    {
        $mapping = [
            'item_name' => ['item_name', 'asc'],
            '-item_name' => ['item_name', 'desc'],
            'price' => ['price', 'asc'],
            '-price' => ['price', 'desc'],
            'created_at' => ['created_at', 'asc'],
            '-created_at' => ['created_at', 'desc'],
            'updated_at' => ['updated_at', 'asc'],
            '-updated_at' => ['updated_at', 'desc'],
        ];

        if (isset($mapping[$sort])) {
            [$column, $direction] = $mapping[$sort];
            if (Schema::hasColumn('store_menu', $column)) {
                return [$column, $direction];
            }
        }

        if (Schema::hasColumn('store_menu', 'updated_at')) {
            return ['updated_at', 'desc'];
        }

        if (Schema::hasColumn('store_menu', 'created_at')) {
            return ['created_at', 'desc'];
        }

        if (Schema::hasColumn('store_menu', 'item_name')) {
            return ['item_name', 'asc'];
        }

        return ['id', 'desc'];
    }

    private function generateUniqueItemCode(Store $store): string
    {
        $storeCode = strtoupper((string) ($store->store_code ?? 'STORE'));
        $storeCode = preg_replace('/[^A-Z0-9]/', '', $storeCode) ?: 'STORE';

        for ($i = 0; $i < 20; $i++) {
            $suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $candidate = 'ITEM-' . $storeCode . '-' . $suffix;

            $exists = DB::table('store_menu')
                ->where('store_id', $store->id)
                ->where('item_code', $candidate)
                ->exists();

            if (!$exists) {
                return $candidate;
            }
        }

        return 'ITEM-' . $storeCode . '-' . strtoupper(substr((string) str_replace('.', '', (string) microtime(true)), -8));
    }

    private function normalizeAbsoluteUrl(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }

        if (str_starts_with($value, '/')) {
            return url($value);
        }

        return url(Storage::url(ltrim($value, '/')));
    }

    private function extractStoragePath(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $path = parse_url($value, PHP_URL_PATH) ?: '';
            if (str_starts_with($path, '/storage/')) {
                return ltrim(substr($path, strlen('/storage/')), '/');
            }

            return null;
        }

        if (str_starts_with($value, '/storage/')) {
            return ltrim(substr($value, strlen('/storage/')), '/');
        }

        return ltrim($value, '/');
    }

    private function resolveOwnedStoreContext(Request $request): array
    {
        $context = $this->resolveStoreAdminAccessContext($request);
        if ($context['error']) {
            return [null, $context['user'], $context['error']];
        }

        return [$context['store'], $context['user'], null];
    }
}
