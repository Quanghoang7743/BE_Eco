<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\Store;
use App\Models\StoreAdminNotification;
use App\Models\StoreReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClientReviewController extends ClientController
{
    public function myReviews(Request $request): JsonResponse
    {
        if (!Schema::hasTable('store_reviews')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'page' => 1,
                    'page_size' => 10,
                    'total_pages' => 0,
                    'has_next' => false,
                ],
                'errors' => [],
            ]);
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $this->resolveApiUser($request);
        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $page = max(1, (int) ($validated['page'] ?? 1));
        $pageSize = max(1, min(100, (int) ($validated['page_size'] ?? 10)));
        $hasHiddenColumn = Schema::hasColumn('store_reviews', 'is_hidden');

        $query = StoreReview::query()
            ->from('store_reviews as sr')
            ->where('sr.user_id', $user->id)
            ->leftJoin('stores as s', 's.id', '=', 'sr.store_id')
            ->leftJoin('store_menu as sm', 'sm.id', '=', 'sr.menu_item_id')
            ->orderByDesc('sr.created_at')
            ->select([
                'sr.id',
                'sr.store_id',
                'sr.order_id',
                'sr.product_name',
                'sr.menu_item_id',
                'sr.product_id',
                'sr.rating',
                'sr.comment',
                'sr.images',
                'sr.reply_text',
                'sr.replied_at',
                'sr.created_at',
                's.name as store_name',
                'sm.item_name as menu_item_name',
            ]);

        if ($hasHiddenColumn) {
            $query->where('sr.is_hidden', false);
        }

        $total = (clone $query)->count('sr.id');
        $rows = $query
            ->forPage($page, $pageSize)
            ->get();

        $data = $rows->map(function ($row) {
            $images = $this->normalizeReviewImages($row->images ?? null);
            $media = $this->extractMediaEntries($row->images ?? null);

            return [
                'review_id' => (string) ($row->id ?? ''),
                'store_id' => (string) ($row->store_id ?? ''),
                'store_name' => (string) ($row->store_name ?? ''),
                'order_id' => (string) ($row->order_id ?? ''),
                'menu_item_id' => !empty($row->menu_item_id) ? (string) $row->menu_item_id : null,
                'product_id' => !empty($row->product_id) ? (string) $row->product_id : null,
                'product_name' => (string) ($row->menu_item_name ?? $row->product_name ?? ''),
                'rating' => (int) ($row->rating ?? 0),
                'comment' => (string) ($row->comment ?? ''),
                'images' => $images,
                'media' => $media,
                'created_at' => $this->toIsoDateTime($row->created_at ?? null),
                'store_reply' => !empty($row->reply_text)
                    ? [
                        'content' => (string) $row->reply_text,
                        'replied_at' => $this->toIsoDateTime($row->replied_at ?? null),
                    ]
                    : null,
            ];
        })->values()->all();

        $totalPages = (int) ceil($total / $pageSize);

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function menuItemReviews(Request $request, string $menuItemId): JsonResponse
    {
        if (!Schema::hasTable('store_reviews')) {
            return response()->json([
                'data' => [],
                'summary' => [
                    'average_rating' => 0,
                    'total_reviews' => 0,
                    'rating_breakdown' => [
                        '1' => 0,
                        '2' => 0,
                        '3' => 0,
                        '4' => 0,
                        '5' => 0,
                    ],
                ],
                'meta' => [
                    'total' => 0,
                    'page' => 1,
                    'page_size' => 10,
                    'total_pages' => 0,
                    'has_next' => false,
                ],
                'errors' => [],
            ]);
        }

        $validated = $request->validate([
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = max(1, (int) ($validated['page'] ?? 1));
        $pageSize = max(1, min(100, (int) ($validated['page_size'] ?? 10)));
        $rating = isset($validated['rating']) ? (int) $validated['rating'] : null;

        $viewer = $this->resolveApiUser($request);
        $hasHiddenColumn = Schema::hasColumn('store_reviews', 'is_hidden');
        $hasProductNameColumn = Schema::hasColumn('store_reviews', 'product_name');
        $hasAvatarColumn = Schema::hasColumn('users', 'avatar');
        $hasAvatarUrlColumn = Schema::hasColumn('users', 'avatar_url');

        $anchorRows = StoreReview::query()
            ->from('store_reviews as sr_anchor')
            ->select(['sr_anchor.store_id', 'sr_anchor.product_name'])
            ->where('sr_anchor.menu_item_id', $menuItemId)
            ->when($hasHiddenColumn, function ($query) {
                $query->where('sr_anchor.is_hidden', false);
            })
            ->get();

        $anchorStoreIds = $anchorRows
            ->pluck('store_id')
            ->filter(fn ($value) => (string) $value !== '')
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values()
            ->all();

        $anchorProductNames = $anchorRows
            ->pluck('product_name')
            ->map(fn ($value) => mb_strtolower(trim((string) $value)))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values()
            ->all();

        $baseQuery = StoreReview::query()
            ->from('store_reviews as sr');

        $baseQuery->where(function ($query) use ($menuItemId, $hasProductNameColumn, $anchorStoreIds, $anchorProductNames) {
            $query->where('sr.menu_item_id', $menuItemId);

            if ($hasProductNameColumn && !empty($anchorStoreIds) && !empty($anchorProductNames)) {
                // Backward compatibility: old reviews might miss menu_item_id.
                $query->orWhere(function ($legacyQuery) use ($anchorStoreIds, $anchorProductNames) {
                    $legacyQuery
                        ->where(function ($nullOrEmptyMenuQuery) {
                            $nullOrEmptyMenuQuery
                                ->whereNull('sr.menu_item_id')
                                ->orWhere('sr.menu_item_id', '');
                        })
                        ->whereIn('sr.store_id', $anchorStoreIds)
                        ->whereIn(
                            DB::raw('LOWER(TRIM(COALESCE(sr.product_name, "")))'),
                            $anchorProductNames
                        );
                });
            }
        });

        if ($hasHiddenColumn) {
            $baseQuery->where('sr.is_hidden', false);
        }

        $summaryQuery = (clone $baseQuery);

        $listQuery = (clone $baseQuery)
            ->leftJoin('users as u', 'u.id', '=', 'sr.user_id')
            ->leftJoin('orders as o', 'o.id', '=', 'sr.order_id')
            ->orderByDesc('sr.created_at')
            ->select([
                'sr.id',
                'sr.user_id',
                'sr.order_id',
                'sr.rating',
                'sr.comment',
                'sr.images',
                'sr.reply_text',
                'sr.replied_at',
                'sr.created_at',
                'u.name as user_name',
                'u.full_name as user_full_name',
                'o.user_id as order_user_id',
                'o.status as order_status',
            ]);

        if ($hasAvatarColumn) {
            $listQuery->addSelect('u.avatar as user_avatar');
        }

        if ($hasAvatarUrlColumn) {
            $listQuery->addSelect('u.avatar_url as user_avatar_url');
        }

        if ($rating !== null) {
            $listQuery->where('sr.rating', $rating);
        }

        $total = (clone $listQuery)->count('sr.id');
        $rows = $listQuery
            ->forPage($page, $pageSize)
            ->get();

        $summaryTotal = (clone $summaryQuery)->count('sr.id');
        $summaryAvg = round((float) ((clone $summaryQuery)->avg('sr.rating') ?? 0), 2);
        $breakdownRows = (clone $summaryQuery)
            ->selectRaw('sr.rating, COUNT(*) as total')
            ->groupBy('sr.rating')
            ->pluck('total', 'rating');

        $ratingBreakdown = [
            '1' => 0,
            '2' => 0,
            '3' => 0,
            '4' => 0,
            '5' => 0,
        ];

        foreach ($ratingBreakdown as $star => $count) {
            $ratingBreakdown[$star] = (int) ($breakdownRows[(int) $star] ?? 0);
        }

        $data = $rows->map(function ($row) {
            $images = $this->normalizeReviewImages($row->images ?? null);
            $media = $this->extractMediaEntries($row->images ?? null);

            $displayName = trim((string) ($row->user_full_name ?? $row->user_name ?? 'Người dùng'));
            $avatar = trim((string) ($row->user_avatar_url ?? $row->user_avatar ?? ''));

            $isVerifiedPurchase = false;
            if (!empty($row->order_id) && !empty($row->order_user_id) && !empty($row->user_id)) {
                $orderStatus = strtolower((string) ($row->order_status ?? ''));
                $isSuccessStatus = in_array($orderStatus, ['completed', 'delivered'], true);
                $isSameUser = (string) $row->order_user_id === (string) $row->user_id;
                $isVerifiedPurchase = $isSuccessStatus && $isSameUser;
            }

            return [
                'review_id' => (string) ($row->id ?? ''),
                'user' => [
                    'name' => $this->maskUserDisplayName($displayName),
                    'avatar' => $avatar !== '' ? $avatar : null,
                ],
                'rating' => (int) ($row->rating ?? 0),
                'comment' => (string) ($row->comment ?? ''),
                'images' => $images,
                'media' => $media,
                'created_at' => $this->toIsoDateTime($row->created_at ?? null),
                'is_verified_purchase' => $isVerifiedPurchase,
                'store_reply' => !empty($row->reply_text)
                    ? [
                        'content' => (string) $row->reply_text,
                        'replied_at' => $this->toIsoDateTime($row->replied_at ?? null),
                    ]
                    : null,
            ];
        })->values()->all();

        $totalPages = (int) ceil($total / $pageSize);

        return response()->json([
            'data' => $data,
            'summary' => [
                'average_rating' => $summaryAvg,
                'total_reviews' => $summaryTotal,
                'rating_breakdown' => $ratingBreakdown,
            ],
            'meta' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'filters' => [
                    'menu_item_id' => $menuItemId,
                    'rating' => $rating,
                ],
                'authenticated' => $viewer !== null,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function storeReviews(Request $request, string $storeId): JsonResponse
    {
        if (!Schema::hasTable('store_reviews')) {
            return response()->json([
                'data' => [],
                'summary' => [
                    'average_rating' => 0,
                    'total_reviews' => 0,
                ],
                'meta' => [
                    'total' => 0,
                    'page' => 1,
                    'page_size' => 20,
                    'total_pages' => 0,
                    'has_next' => false,
                ],
                'errors' => [],
            ]);
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = max(1, (int) ($validated['page'] ?? 1));
        $pageSize = max(1, min(100, (int) ($validated['page_size'] ?? 20)));
        $hasHiddenColumn = Schema::hasColumn('store_reviews', 'is_hidden');
        $hasAvatarUrlColumn = Schema::hasColumn('users', 'avatar_url');

        $query = StoreReview::query()
            ->from('store_reviews as sr')
            ->where('sr.store_id', $storeId)
            ->leftJoin('users as u', 'u.id', '=', 'sr.user_id')
            ->orderByDesc('sr.created_at')
            ->select([
                'sr.id',
                'sr.user_id',
                'sr.rating',
                'sr.comment',
                'sr.images',
                'sr.reply_text',
                'sr.replied_at',
                'sr.created_at',
                'u.name as user_name',
                'u.full_name as user_full_name',
                'u.avatar as user_avatar',
            ]);

        if ($hasAvatarUrlColumn) {
            $query->addSelect('u.avatar_url as user_avatar_url');
        }

        if ($hasHiddenColumn) {
            $query->where('sr.is_hidden', false);
        }

        $summaryQuery = (clone $query);

        $total = (clone $query)->count('sr.id');
        $rows = $query
            ->forPage($page, $pageSize)
            ->get();

        $summaryTotal = (clone $summaryQuery)->count('sr.id');
        $summaryAverage = round((float) ((clone $summaryQuery)->avg('sr.rating') ?? 0), 2);

        $data = $rows->map(function ($row) {
            $images = $this->normalizeReviewImages($row->images ?? null);
            $media = $this->extractMediaEntries($row->images ?? null);

            return [
                'review_id' => (string) ($row->id ?? ''),
                'rating' => (int) ($row->rating ?? 0),
                'comment' => (string) ($row->comment ?? ''),
                'images' => $images,
                'media' => $media,
                'created_at' => $this->toIsoDateTime($row->created_at ?? null),
                'customer' => [
                    'id' => (string) ($row->user_id ?? ''),
                    'name' => (string) ($row->user_full_name ?? $row->user_name ?? ''),
                    'avatar' => (string) ($row->user_avatar_url ?? $row->user_avatar ?? ''),
                ],
                'reply' => !empty($row->reply_text)
                    ? [
                        'text' => (string) $row->reply_text,
                        'at' => $this->toIsoDateTime($row->replied_at ?? null),
                    ]
                    : null,
            ];
        })->values()->all();

        return response()->json([
            'data' => $data,
            'summary' => [
                'average_rating' => $summaryAverage,
                'total_reviews' => $summaryTotal,
            ],
            'meta' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_pages' => (int) ceil($total / $pageSize),
                'has_next' => $page * $pageSize < $total,
            ],
            'errors' => [],
        ]);
    }

    public function reviewedOrderIds(Request $request): JsonResponse
    {
        if (!Schema::hasTable('store_reviews')) {
            return response()->json([
                'order_ids' => [],
            ]);
        }

        $user = $this->resolveApiUser($request);
        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $orderIds = StoreReview::query()
            ->where('user_id', $user->id)
            ->whereNotNull('order_id')
            ->pluck('order_id')
            ->map(fn ($value) => (string) $value)
            ->filter(fn ($value) => $value !== '')
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'order_ids' => $orderIds,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!Schema::hasTable('store_reviews')) {
            return response()->json([
                'message' => 'Chuc nang danh gia chua san sang.',
            ], 503);
        }

        $maxReviewImageSizeKb = 10240;

        $validated = $request->validate([
            'order_id' => ['nullable', 'string', 'max:64'],
            'orderId' => ['required_without:order_id', 'string', 'max:64'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:3000'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'productName' => ['nullable', 'string', 'max:255'],
            'menu_item_id' => ['nullable', 'string', 'max:64'],
            'menuItemId' => ['nullable', 'string', 'max:64'],
            'product_id' => ['nullable', 'string', 'max:64'],
            'productId' => ['nullable', 'string', 'max:64'],
            'media_images' => ['nullable', 'array', 'max:6'],
            'media_images.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif', 'max:' . $maxReviewImageSizeKb],
            'media_videos' => ['nullable', 'array', 'max:2'],
            'media_videos.*' => ['file', 'mimetypes:video/mp4,video/webm,video/quicktime,video/x-msvideo,video/x-ms-wmv', 'max:20480'],
        ]);

        $user = $this->resolveApiUser($request);
        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $orderIdOrCode = trim((string) ($validated['order_id'] ?? $validated['orderId'] ?? ''));
        $orderQuery = Order::query()->where('user_id', $user->id);

        if ($orderIdOrCode !== '') {
            $orderQuery->where(function ($query) use ($orderIdOrCode): void {
                $query->where('id', $orderIdOrCode);
                if (Schema::hasColumn('orders', 'order_code')) {
                    $query->orWhere('order_code', $orderIdOrCode);
                }
            });
        }

        $order = $orderQuery->with('items')->first();

        if (!$order) {
            return response()->json([
                'message' => 'Khong tim thay don hang da mua.',
            ], 404);
        }

        $alreadyReviewed = StoreReview::query()
            ->where('user_id', $user->id)
            ->where('order_id', $order->id)
            ->exists();

        if ($alreadyReviewed) {
            return response()->json([
                'message' => 'Don hang nay da duoc danh gia truoc do.',
            ], 422);
        }

        $status = strtolower((string) ($order->status ?? ''));
        if (in_array($status, ['cancelled', 'canceled'], true)) {
            return response()->json([
                'message' => 'Don hang da huy khong the danh gia.',
            ], 422);
        }

        $storeId = (string) ($order->store_id ?? '');
        if ($storeId === '') {
            return response()->json([
                'message' => 'Don hang nay chua gan cua hang de tiep nhan danh gia.',
            ], 422);
        }

        $productName = trim((string) ($validated['product_name'] ?? $validated['productName'] ?? ''));
        $menuItemId = trim((string) ($validated['menu_item_id'] ?? $validated['menuItemId'] ?? ''));
        $productId = trim((string) ($validated['product_id'] ?? $validated['productId'] ?? ''));

        if ($menuItemId !== '' || $productId !== '' || $productName !== '') {
            $matchedItem = collect($order->items)->first(function ($item) use ($menuItemId, $productId, $productName) {
                $itemMenuId = (string) ($item->menu_item_id ?? '');
                $itemProductId = (string) ($item->product_id ?? '');
                $itemName = trim((string) ($item->item_name ?? $item->product_name ?? ''));

                if ($menuItemId !== '' && $itemMenuId !== '' && $menuItemId === $itemMenuId) {
                    return true;
                }

                if ($productId !== '' && $itemProductId !== '' && $productId === $itemProductId) {
                    return true;
                }

                return $productName !== '' && mb_strtolower($productName) === mb_strtolower($itemName);
            });

            if (!$matchedItem) {
                return response()->json([
                    'message' => 'San pham danh gia khong thuoc don hang da mua.',
                ], 422);
            }

            if ($productName === '') {
                $productName = trim((string) ($matchedItem->item_name ?? $matchedItem->product_name ?? ''));
            }
        }

        $comment = trim((string) ($validated['comment'] ?? ''));
        $normalizedComment = trim(
            $productName !== ''
                ? '[San pham: ' . $productName . '] ' . $comment
                : $comment
        );

        DB::beginTransaction();

        try {
            $review = new StoreReview();
            $review->store_id = $storeId;
            $review->user_id = $user->id;
            $review->order_id = $order->id;
            $review->rating = (int) $validated['rating'];
            $review->comment = $normalizedComment !== '' ? $normalizedComment : null;

            if (Schema::hasColumn('store_reviews', 'product_name')) {
                $review->product_name = $productName !== '' ? $productName : null;
            }

            if (Schema::hasColumn('store_reviews', 'menu_item_id')) {
                $review->menu_item_id = $menuItemId !== '' ? $menuItemId : null;
            }

            if (Schema::hasColumn('store_reviews', 'product_id')) {
                $review->product_id = $productId !== '' ? $productId : null;
            }

            $mediaEntries = [];

            if ($request->hasFile('media_images')) {
                foreach ((array) $request->file('media_images') as $imageFile) {
                    if (!$imageFile) {
                        continue;
                    }

                    $path = $imageFile->store("uploads/store-reviews/{$storeId}/images", 'public');
                    $mediaEntries[] = [
                        'type' => 'image',
                        'url' => url(Storage::url($path)),
                        'path' => $path,
                    ];
                }
            }

            if ($request->hasFile('media_videos')) {
                foreach ((array) $request->file('media_videos') as $videoFile) {
                    if (!$videoFile) {
                        continue;
                    }

                    $path = $videoFile->store("uploads/store-reviews/{$storeId}/videos", 'public');
                    $mediaEntries[] = [
                        'type' => 'video',
                        'url' => url(Storage::url($path)),
                        'path' => $path,
                    ];
                }
            }

            if (!empty($mediaEntries)) {
                $review->images = $mediaEntries;
            }

            $review->save();

            $this->createStoreAdminReviewNotification($review, $storeId, $productName, $menuItemId);

            $ratingQuery = StoreReview::query()
                ->where('store_id', $storeId)
                ->whereNotNull('rating');

            if (Schema::hasColumn('store_reviews', 'is_hidden')) {
                $ratingQuery->where('is_hidden', false);
            }

            $ratingCount = (int) (clone $ratingQuery)->count();
            $ratingAvg = (float) ((clone $ratingQuery)->avg('rating') ?? 0);

            $store = Store::query()->find($storeId);
            if ($store) {
                if (Schema::hasColumn('stores', 'rating_count')) {
                    $store->rating_count = $ratingCount;
                }
                if (Schema::hasColumn('stores', 'rating_avg')) {
                    $store->rating_avg = round($ratingAvg, 2);
                }
                $store->save();
            }

            DB::commit();

            return response()->json([
                'id' => $review->id,
                'order_id' => $review->order_id,
                'rating' => (int) $review->rating,
                'product_name' => $productName !== '' ? $productName : null,
                'media' => $review->images ?? [],
                'message' => 'Da gui danh gia thanh cong toi admin cua hang.',
            ], 201);
        } catch (\Throwable $exception) {
            DB::rollBack();

            return response()->json([
                'message' => 'Khong the gui danh gia. Vui long thu lai.',
                'error' => config('app.debug') ? $exception->getMessage() : null,
            ], 500);
        }
    }

    private function createStoreAdminReviewNotification(
        StoreReview $review,
        string $storeId,
        string $productName,
        string $menuItemId
    ): void {
        if (!Schema::hasTable('store_admin_notifications') || trim($storeId) === '') {
            return;
        }

        $target = trim($menuItemId) !== ''
            ? '/admin_store/reviews/' . rawurlencode(trim($menuItemId))
            : '/admin_store/reviews';
        $displayName = trim($productName) !== '' ? trim($productName) : 'san pham';

        try {
            StoreAdminNotification::query()->create([
                'id' => (string) Str::uuid(),
                'store_id' => $storeId,
                'order_id' => null,
                'order_code' => null,
                'type' => 'review_new',
                'title' => 'Danh gia moi',
                'message' => 'Khach hang vua gui danh gia ' . (int) $review->rating . ' sao cho ' . $displayName . '.',
                'data' => [
                    'review_id' => (string) $review->id,
                    'order_id' => $review->order_id ? (string) $review->order_id : null,
                    'menu_item_id' => trim($menuItemId) !== '' ? trim($menuItemId) : null,
                    'product_name' => trim($productName) !== '' ? trim($productName) : null,
                    'rating' => (int) $review->rating,
                    'action_url' => $target,
                ],
                'is_read' => false,
                'read_at' => null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function normalizeReviewImages(mixed $rawImages): array
    {
        $entries = [];

        if (is_string($rawImages)) {
            $decoded = json_decode($rawImages, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $entries = $decoded;
            }
        } elseif (is_array($rawImages)) {
            $entries = $rawImages;
        }

        return collect($entries)
            ->filter(function ($item): bool {
                if (is_string($item) && trim($item) !== '') {
                    return true;
                }

                return is_array($item)
                    && (($item['type'] ?? null) === 'image')
                    && !empty($item['url']);
            })
            ->map(function ($item): string {
                return is_string($item)
                    ? trim($item)
                    : trim((string) ($item['url'] ?? ''));
            })
            ->filter(fn (string $url): bool => $url !== '')
            ->values()
            ->all();
    }

    private function extractMediaEntries(mixed $rawImages): array
    {
        $entries = [];

        if (is_string($rawImages)) {
            $decoded = json_decode($rawImages, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $entries = $decoded;
            }
        } elseif (is_array($rawImages)) {
            $entries = $rawImages;
        }

        return collect($entries)
            ->map(function ($item) {
                if (is_string($item) && trim($item) !== '') {
                    return [
                        'type' => 'image',
                        'url' => trim($item),
                    ];
                }

                if (is_array($item) && !empty($item['url'])) {
                    return [
                        'type' => $item['type'] ?? 'image',
                        'url' => trim((string) $item['url']),
                    ];
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function maskUserDisplayName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return 'Người dùng';
        }

        $len = mb_strlen($trimmed);
        if ($len <= 1) {
            return '*';
        }

        if ($len === 2) {
            return mb_substr($trimmed, 0, 1) . '*';
        }

        return mb_substr($trimmed, 0, 1)
            . str_repeat('*', max(1, $len - 2))
            . mb_substr($trimmed, -1);
    }

    private function toIsoDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance($value)->toIso8601String();
            }

            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
