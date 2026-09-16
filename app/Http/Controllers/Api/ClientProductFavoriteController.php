<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\UserProductFavorite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientProductFavoriteController extends ClientController
{
    public function toggle(Request $request, string $productId): JsonResponse
    {
        $user = $request->user() ?: $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! Schema::hasTable('user_product_favorites')) {
            return response()->json([
                'success' => false,
                'message' => 'Bang san pham yeu thich chua duoc khoi tao.',
            ], 503);
        }

        $product = Product::query()
            ->whereKey($productId)
            ->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), Product::APPROVED_STATUSES)
            ->first();

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Khong tim thay san pham.',
            ], 404);
        }

        $favorite = UserProductFavorite::query()
            ->where('user_id', (int) $user->id)
            ->where('product_id', (int) $product->id)
            ->first();

        if ($favorite) {
            $favorite->delete();
            $isFavorite = false;
        } else {
            UserProductFavorite::query()->create([
                'user_id' => (int) $user->id,
                'product_id' => (int) $product->id,
            ]);
            $isFavorite = true;
        }

        return response()->json([
            'success' => true,
            'message' => $isFavorite
                ? 'Da them san pham vao muc yeu thich.'
                : 'Da bo san pham khoi muc yeu thich.',
            'data' => [
                'product_id' => (int) $product->id,
                'is_favorite' => $isFavorite,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user() ?: $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! Schema::hasTable('user_product_favorites')) {
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'page' => 1,
                    'page_size' => 10,
                    'total_pages' => 0,
                    'current_page' => 1,
                    'per_page' => 10,
                    'last_page' => 1,
                    'has_next' => false,
                ],
            ]);
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($validated['page_size'] ?? $validated['per_page'] ?? 10)));

        $query = UserProductFavorite::query()
            ->where('user_id', (int) $user->id)
            ->whereHas('product', function ($productQuery): void {
                $productQuery->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), Product::APPROVED_STATUSES);
            })
            ->with(['product' => function ($productQuery): void {
                $productQuery->select([
                    'id',
                    'name',
                    'slug',
                    'thumbnail',
                    'price',
                    'sale_price',
                    'stock',
                    'store_id',
                    'category_id',
                ]);
            }])
            ->orderByDesc('created_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())
            ->map(function (UserProductFavorite $favorite): ?array {
                $product = $favorite->product;
                if (! $product) {
                    return null;
                }

                return [
                    'id' => (int) $product->id,
                    'name' => (string) ($product->name ?? ''),
                    'slug' => (string) ($product->slug ?? ''),
                    'thumbnail' => $product->thumbnail,
                    'thumb' => $product->thumbnail,
                    'price' => (float) ($product->price ?? 0),
                    'sale_price' => (float) ($product->sale_price ?? 0),
                    'stock' => (int) ($product->stock ?? 0),
                    'is_in_stock' => (int) ($product->stock ?? 0) > 0,
                    'stock_status' => (int) ($product->stock ?? 0) > 0 ? 'in_stock' : 'out_of_stock',
                    'store_id' => $product->store_id !== null ? (string) $product->store_id : null,
                    'category_id' => $product->category_id !== null ? (int) $product->category_id : null,
                    'is_favorite' => true,
                    'favorited_at' => optional($favorite->created_at)->toIso8601String(),
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'total' => (int) $paginator->total(),
                'page' => (int) $paginator->currentPage(),
                'page_size' => (int) $paginator->perPage(),
                'total_pages' => (int) $paginator->lastPage(),
                'current_page' => (int) $paginator->currentPage(),
                'per_page' => (int) $paginator->perPage(),
                'last_page' => (int) $paginator->lastPage(),
                'has_next' => (bool) $paginator->hasMorePages(),
                'request_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
