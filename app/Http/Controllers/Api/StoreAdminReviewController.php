<?php

namespace App\Http\Controllers\Api;

use App\Models\Notification;
use App\Models\Store;
use App\Models\StoreReview;
use App\Models\StoreMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class StoreAdminReviewController
{
    public function index(Request $request): JsonResponse
    {
        $store = $this->getStore($request);

        if (!$store) {
            return response()->json([
                'message' => 'Tai khoan hien tai chua co cua hang.',
            ], 404);
        }

        if (!Schema::hasTable('store_reviews')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'page' => 1,
                    'page_size' => 20,
                ],
            ]);
        }

        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, min(100, (int) $request->query('page_size', 20)));

        $query = StoreReview::query()
            ->where('store_id', $store->id)
            ->whereHas('menuItem')
            ->with(['user:id,name,full_name,avatar', 'order:id,order_code'])
            ->orderByDesc('created_at');

        if (Schema::hasColumn('store_reviews', 'is_hidden')) {
            $query->where('is_hidden', false);
        }

        $total = (clone $query)->count();
        $reviews = $query
            ->forPage($page, $pageSize)
            ->get();

        return response()->json([
            'data' => $reviews->map(function (StoreReview $review) {
                return $this->formatReview($review);
            })->values()->all(),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_pages' => (int) ceil($total / $pageSize),
            ],
        ]);
    }

    public function summaryByMenu(Request $request): JsonResponse
    {
        $store = $this->getStore($request);

        if (!$store) {
            return response()->json(['message' => 'Store not found'], 404);
        }

        $hasHiddenColumn = Schema::hasColumn('store_reviews', 'is_hidden');

        $menuItems = StoreMenu::query()
            ->where('store_id', $store->id)
            ->withCount(['reviews' => function ($query) use ($hasHiddenColumn) {
                if ($hasHiddenColumn) {
                    $query->where('is_hidden', false);
                }
            }])
            ->withCount(['reviews as unreplied_count' => function ($query) use ($hasHiddenColumn) {
                if ($hasHiddenColumn) {
                    $query->where('is_hidden', false);
                }

                $query->where(function ($replyQuery) {
                    $replyQuery->whereNull('reply_text')
                        ->orWhere('reply_text', '');
                });
            }])
            ->withAvg(['reviews' => function ($query) use ($hasHiddenColumn) {
                if ($hasHiddenColumn) {
                    $query->where('is_hidden', false);
                }
            }], 'rating')
            ->withMax(['reviews as latest_comment_at' => function ($query) use ($hasHiddenColumn) {
                if ($hasHiddenColumn) {
                    $query->where('is_hidden', false);
                }
            }], 'created_at')
            ->get();

        return response()->json([
            'data' => $menuItems->map(fn($item) => [
                'id' => $item->id,
                'item_name' => $item->item_name,
                'image_url' => $item->image_url,
                'review_count' => (int) $item->reviews_count,
                'unreplied_count' => (int) ($item->unreplied_count ?? 0),
                'average_rating' => round((float) $item->reviews_avg_rating, 1),
                'latest_comment_at' => $item->latest_comment_at
                    ? Carbon::parse($item->latest_comment_at)->toIso8601String()
                    : null,
            ])
        ]);
    }

    public function menuItemReviews(Request $request, $menuItemId): JsonResponse
    {
        $store = $this->getStore($request);

        if (!$store) {
            return response()->json(['message' => 'Store not found'], 404);
        }

        $validated = $request->validate([
            'rating' => 'nullable|integer|between:1,5',
        ]);

        $menuItemExists = StoreMenu::where('store_id', $store->id)
            ->where(function ($q) use ($menuItemId) {
                $q->where('id', $menuItemId)
                  ->orWhere('product_id', $menuItemId);
            })
            ->exists();

        if (!$menuItemExists) {
            $page = max(1, (int) $request->query('page', 1));
            $pageSize = max(1, min(100, (int) $request->query('page_size', 10)));
            return response()->json([
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total_pages' => 0,
                ]
            ]);
        }

        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, min(100, (int) $request->query('page_size', 10)));
        $rating = $validated['rating'] ?? null;
        $hasHiddenColumn = Schema::hasColumn('store_reviews', 'is_hidden');

        $query = StoreReview::query()
            ->where('store_id', $store->id)
            ->where('menu_item_id', $menuItemId)
            ->with(['user:id,name,full_name,avatar'])
            ->orderByDesc('created_at');

        if ($hasHiddenColumn) {
            $query->where('is_hidden', false);
        }

        if ($rating !== null) {
            $query->where('rating', (int) $rating);
        }

        $total = (clone $query)->count();
        $reviews = $query->forPage($page, $pageSize)->get();

        return response()->json([
            'data' => $reviews->map(fn($review) => $this->formatReview($review)),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_pages' => (int) ceil($total / $pageSize),
            ]
        ]);
    }

    public function reply(Request $request, $reviewId): JsonResponse
    {
        $store = $this->getStore($request);

        if (!$store) {
            return response()->json(['message' => 'Store not found'], 404);
        }

        $review = StoreReview::where('id', $reviewId)
            ->where('store_id', $store->id)
            ->firstOrFail();

        $request->validate([
            'reply_text' => 'required|string',
        ]);

        $review->update([
            'reply_text' => $request->reply_text,
            'replied_at' => now(),
            'replied_by' => $request->user()->id,
        ]);

        if ($review->user_id) {
            Notification::create([
                'user_id' => $review->user_id,
                'title' => 'Phản hồi đánh giá',
                'message' => "Cửa hàng {$store->name} đã phản hồi đánh giá của bạn.",
                'type' => 'review_reply',
                'audience_scope' => 'user_private',
            ]);
        }

        return response()->json([
            'message' => 'Reply sent successfully',
            'data' => $this->formatReview($review)
        ]);
    }

    public function destroy(Request $request, $reviewId): JsonResponse
    {
        $store = $this->getStore($request);

        if (!$store) {
            return response()->json(['message' => 'Store not found'], 404);
        }

        $review = StoreReview::where('id', $reviewId)
            ->where('store_id', $store->id)
            ->firstOrFail();

        $review->delete();

        return response()->json([
            'message' => 'Review deleted successfully'
        ]);
    }

    private function getStore(Request $request)
    {
        $user = $request->user();
        if (!$user) return null;

        return Store::query()
            ->where('owner_id', $user->id)
            ->orderByDesc('created_at')
            ->first();
    }

    private function formatReview(StoreReview $review): array
    {
        $mediaEntries = is_array($review->images) ? $review->images : (is_string($review->images) ? json_decode($review->images, true) : []);
        if (!is_array($mediaEntries)) $mediaEntries = [];

        $imageUrls = collect($mediaEntries)
            ->filter(function ($item) {
                if (is_string($item) && trim($item) !== '') {
                    return true;
                }
                return is_array($item) && (($item['type'] ?? null) === 'image') && !empty($item['url']);
            })
            ->map(fn ($item) => is_string($item) ? trim($item) : trim((string) $item['url']))
            ->values()
            ->all();

        $formattedMedia = collect($mediaEntries)
            ->map(function ($item) {
                if (is_string($item) && trim($item) !== '') return ['type' => 'image', 'url' => trim($item)];
                if (is_array($item) && !empty($item['url'])) return ['type' => $item['type'] ?? 'image', 'url' => trim((string) $item['url'])];
                return null;
            })
            ->filter()
            ->values()
            ->all();

        return [
            'id' => (string) $review->id,
            'customer' => [
                'id' => (string) ($review->user_id ?? ''),
                'name' => $review->user?->full_name ?: $review->user?->name,
                'avatar' => $review->user?->avatar ?? $review->user?->avatar_url,
            ],
            'rating' => (int) ($review->rating ?? 0),
            'comment' => $review->comment,
            'images' => $imageUrls,
            'media' => $formattedMedia,
            'reply' => $review->reply_text ? [
                'text' => $review->reply_text,
                'at' => $review->replied_at ? $review->replied_at->toIso8601String() : null,
            ] : null,
            'created_at' => optional($review->created_at)->toIso8601String(),
        ];
    }
}
