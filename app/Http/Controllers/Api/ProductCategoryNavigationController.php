<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ProductCategoryNavigationController extends Controller
{
    public function homeNav(): JsonResponse
    {
        $payload = Cache::remember(ProductCategory::HOME_NAV_CACHE_KEY, now()->addMinutes(10), function () {
            $categories = ProductCategory::query()
                ->where('is_active', true)
                ->where('show_on_home', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->orderBy('id')
                ->get([
                    'id',
                    'name',
                    'slug',
                    'parent_id',
                    'image',
                    'sort_order',
                    'is_active',
                    'show_on_home',
                    'level',
                    'store_id',
                ]);

            return [
                'cached_at' => now()->toIso8601String(),
                'data' => $categories
                    ->map(fn (ProductCategory $category) => $this->formatCategory($category))
                    ->values()
                    ->all(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $payload['data'],
            'meta' => [
                'total' => count($payload['data']),
                'cache_key' => ProductCategory::HOME_NAV_CACHE_KEY,
                'cached_at' => $payload['cached_at'] ?? null,
                'request_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function featured(): JsonResponse
    {
        return $this->homeNav();
    }

    private function formatCategory(ProductCategory $category): array
    {
        return [
            'id' => (int) $category->id,
            'category_id' => (int) $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'parent_id' => $category->parent_id ? (int) $category->parent_id : null,
            'image' => $category->image,
            'image_url' => $this->resolveImageUrl($category->image),
            'sort_order' => (int) ($category->sort_order ?? 0),
            'is_active' => (bool) ($category->is_active ?? true),
            'show_on_home' => (bool) ($category->show_on_home ?? false),
            'level' => (int) ($category->level ?? 1),
            'store_id' => $category->store_id,
        ];
    }

    private function resolveImageUrl(?string $image): ?string
    {
        if (! $image) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $image)) {
            return $image;
        }

        return asset('storage/' . ltrim($image, '/'));
    }
}
